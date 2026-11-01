<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\PinnedHttpClient\Internal\BoundedSink;

/**
 * PSR-18 клиент для адресов, которые выбрал не вы. Имя узла разрешается здесь, каждый полученный адрес проходит
 * {@see AddressPolicy}, и соединение закрепляется за проверенными адресами (`CURLOPT_RESOLVE`): подмена DNS между
 * проверкой и соединением («DNS rebinding») не уведёт на внутренний узел.
 *
 * Транспорт клиент строит сам и наружу не отдаёт: только cURL-обработчик (потоковый обработчик Guzzle закрепления не
 * знает), без прокси из окружения, без переадресаций, с потолком на размер тела. Переадресации ведёт
 * {@see RedirectFollower} или вызывающий код, отправляя каждый переход через этот клиент заново.
 *
 * @api
 */
final readonly class PinnedClient implements ClientInterface
{
    public const int DEFAULT_MAX_BODY_BYTES = 10_485_760;

    private Resolver $resolver;

    private Client $guzzle;

    /** @var positive-int|null */
    private ?int $maxBodyBytes;

    /**
     * @param Resolver|null $resolver по умолчанию {@see SystemResolver} (IPv4 и IPv6)
     * @param float $timeoutSeconds весь запрос, > 0
     * @param float $connectTimeoutSeconds установка соединения, > 0
     * @param int|null $maxBodyBytes потолок тела после распаковки, ≥ 1; `null` — без потолка
     * @param string|null $proxy явный непустой прокси (`http://egress:3128`); с ним соединение к цели устанавливает
     *     прокси, и SSRF-границей становится он. Прокси из окружения (`HTTPS_PROXY` и т. п.) не используется никогда
     */
    public function __construct(
        private AddressPolicy $policy = new PublicAddresses(),
        ?Resolver $resolver = null,
        private float $timeoutSeconds = 8.0,
        private float $connectTimeoutSeconds = 4.0,
        ?int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
        private ?string $proxy = null,
    ) {
        if (\is_nan($timeoutSeconds) || \is_nan($connectTimeoutSeconds) || $timeoutSeconds <= 0.0 || $connectTimeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('Timeouts must be positive');
        }

        if ($maxBodyBytes !== null && $maxBodyBytes < 1) {
            throw new \InvalidArgumentException('The body limit must be positive or null');
        }

        if ($proxy === '') {
            throw new \InvalidArgumentException('The proxy must be a non-empty URL or null');
        }

        $this->maxBodyBytes = $maxBodyBytes;
        $this->resolver = $resolver ?? new SystemResolver();
        $this->guzzle = new Client(['handler' => HandlerStack::create(new CurlHandler())]);
    }

    /**
     * Тот же клиент с другим потолком тела (≥ 1 или `null`).
     */
    public function withMaxBodyBytes(?int $maxBodyBytes): self
    {
        return new self(
            policy: $this->policy,
            resolver: $this->resolver,
            timeoutSeconds: $this->timeoutSeconds,
            connectTimeoutSeconds: $this->connectTimeoutSeconds,
            maxBodyBytes: $maxBodyBytes,
            proxy: $this->proxy,
        );
    }

    /**
     * Тот же клиент с другими таймаутами.
     */
    public function withTimeouts(float $timeoutSeconds, float $connectTimeoutSeconds): self
    {
        return new self(
            policy: $this->policy,
            resolver: $this->resolver,
            timeoutSeconds: $timeoutSeconds,
            connectTimeoutSeconds: $connectTimeoutSeconds,
            maxBodyBytes: $this->maxBodyBytes,
            proxy: $this->proxy,
        );
    }

    /**
     * @throws RequestRefused схема, узел, разрешение имени или политика не пустили запрос
     * @throws ResponseTooLarge тело превысило потолок
     * @throws TransferFailed соединение, TLS или таймаут
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new RequestRefused($request, Refusal::UnsupportedScheme);
        }

        $host = trim($uri->getHost(), '[]');

        if ($host === '') {
            throw new RequestRefused($request, Refusal::MissingHost);
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $literal ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new RequestRefused($request, Refusal::Unresolvable);
        }

        foreach ($addresses as $address) {
            if (!$this->policy->allows($address)) {
                throw new RequestRefused($request, Refusal::AddressNotAllowed);
            }
        }

        $curl = [];

        if (!$literal) {
            $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
            $curl[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . implode(',', array_map($this->bracketed(...), $addresses))];
        }

        $sink = $this->maxBodyBytes === null
            ? null
            : new BoundedSink(Utils::streamFor(Utils::tryFopen('php://temp', 'w+')), $this->maxBodyBytes);

        $options = [
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => $this->timeoutSeconds,
            'connect_timeout' => $this->connectTimeoutSeconds,
            // пустая строка выключает и прокси Guzzle из окружения, и тот, что libcurl читает из окружения сам
            'proxy' => $this->proxy ?? '',
            'curl' => $curl,
        ];

        if ($sink instanceof \Rasuvaeff\PinnedHttpClient\Internal\BoundedSink) {
            $options['sink'] = $sink;
        }

        try {
            return $this->guzzle->send($request, $options);
        } catch (TransferException $failure) {
            if ($sink instanceof \Rasuvaeff\PinnedHttpClient\Internal\BoundedSink && $sink->exceeded() && $this->maxBodyBytes !== null) {
                throw new ResponseTooLarge($request, $this->maxBodyBytes);
            }

            throw new TransferFailed($request, $failure->getMessage(), $failure);
        }
    }

    private function bracketed(string $address): string
    {
        return str_contains($address, ':') ? '[' . $address . ']' : $address;
    }
}

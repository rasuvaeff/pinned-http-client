<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface as GuzzleClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * PSR-18 клиент для адресов, которые выбрал не вы. Имя узла разрешается здесь, каждый полученный адрес проходит
 * {@see AddressPolicy}, и соединение закрепляется за проверенными адресами (`CURLOPT_RESOLVE`): подмена DNS между
 * проверкой и соединением («DNS rebinding») не уведёт на внутренний узел. Переадресаций клиент не делает: их ведёт
 * вызывающий код, проверяя политикой каждый переход заново.
 *
 * @api
 */
final readonly class PinnedClient implements ClientInterface
{
    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /**
     * @param (Closure(string): list<string>)|null $resolver имя узла в IPv4-адреса; по умолчанию `gethostbynamel`
     */
    public function __construct(
        private AddressPolicy $policy = new PublicAddresses(),
        private float $timeoutSeconds = 8.0,
        private float $connectTimeoutSeconds = 4.0,
        private GuzzleClient $guzzle = new Client(),
        ?Closure $resolver = null,
    ) {
        $this->resolver = $resolver ?? static function (string $host): array {
            $found = gethostbynamel($host);

            return $found === false ? [] : $found;
        };
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->send($request);
    }

    /**
     * Как {@see sendRequest()}, с параметрами Guzzle поверх обычных (например `stream`).
     *
     * @param array<string, mixed> $options
     */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new PinningFailed($request, 'Only http and https are fetched');
        }

        $host = trim($uri->getHost(), '[]');

        if ($host === '') {
            throw new PinningFailed($request, 'The URL has no host');
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $literal ? [$host] : ($this->resolver)($host);

        if ($addresses === []) {
            throw new PinningFailed($request, 'The host does not resolve');
        }

        foreach ($addresses as $address) {
            if (!$this->policy->allows($address)) {
                throw new PinningFailed($request, 'The host resolves to a non-public address');
            }
        }

        /** @var array<int, mixed> $curl */
        $curl = \is_array($options['curl'] ?? null) ? $options['curl'] : [];
        unset($curl[CURLOPT_RESOLVE]);

        if (!$literal) {
            $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
            $curl[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . implode(',', array_map($this->bracketed(...), $addresses))];
        }

        // закрепление и запрет переадресаций вызывающий переопределить не может
        $options = array_replace(
            ['http_errors' => false, 'timeout' => $this->timeoutSeconds, 'connect_timeout' => $this->connectTimeoutSeconds],
            $options,
            ['allow_redirects' => false, 'curl' => $curl],
        );

        try {
            return $this->guzzle->send($request, $options);
        } catch (Throwable $failure) {
            throw new PinningFailed($request, $failure->getMessage(), $failure);
        }
    }

    private function bracketed(string $address): string
    {
        return str_contains($address, ':') ? '[' . $address . ']' : $address;
    }
}

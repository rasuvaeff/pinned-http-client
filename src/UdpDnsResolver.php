<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

/**
 * Резолвер с ограниченным по времени разрешением имён: два DNS-запроса (A и AAAA) к явно заданному серверу,
 * таймаут ожидания ответа задаёт клиент, а не настройки резолвера ОС — именно этого не умеет {@see SystemResolver}.
 *
 * Сервер — IP-литерал (IPv4 или IPv6), обычно локальный или внутренний рекурсивный резолвер; транспорт —
 * {@see StreamUdpTransport} либо любой свой через {@see DnsTransport}. Любая неудача — таймаут, повреждённый
 * ответ, чужой id, RCODE — даёт пустой список по контракту {@see Resolver}; {@see PinnedClient} превращает его
 * в {@see ResolutionFailed}. Список адресов собирается из всех A/AAAA-записей секции Answer, включая цепочки
 * CNAME, которые рекурсивный сервер досылает сам.
 *
 * @api
 */
final readonly class UdpDnsResolver implements Resolver
{
    private DnsTransport $transport;

    /**
     * @param string $server IP-литерал DNS-сервера, IPv4 или IPv6
     * @param int $port порт DNS-сервера, от 1 до 65535
     * @param float $timeoutSeconds ожидание ответа на один запрос, > 0
     * @param DnsTransport|null $transport по умолчанию {@see StreamUdpTransport}
     */
    public function __construct(
        private string $server,
        private int $port = 53,
        private float $timeoutSeconds = 2.0,
        ?DnsTransport $transport = null,
    ) {
        if (filter_var($server, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('The DNS server must be an IPv4 or IPv6 address');
        }

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('The DNS port must be between 1 and 65535');
        }

        if (\is_nan($timeoutSeconds) || $timeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('The DNS timeout must be positive');
        }

        $this->transport = $transport ?? new StreamUdpTransport();
    }

    #[\Override]
    public function resolve(string $host): array
    {
        $addresses = [];

        foreach ([Internal\DnsMessage::TYPE_A, Internal\DnsMessage::TYPE_AAAA] as $type) {
            $id = random_int(0, 0xFFFF);
            $response = $this->transport->query(
                server: $this->server,
                port: $this->port,
                timeoutSeconds: $this->timeoutSeconds,
                message: Internal\DnsMessage::encodeQuery($host, $type, $id),
            );

            if ($response === null) {
                continue;
            }

            try {
                $parsed = Internal\DnsMessage::parseAddresses($response, $id);
            } catch (Internal\InvalidDnsResponse) {
                // битый ответ неотличим от неответа: стек не даёт адресов
                continue;
            }

            foreach ($parsed as $address) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}

<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

/**
 * Транспорт DNS по умолчанию: один UDP-датаграммный обмен на запрос. Любая неудача — таймаут, отказ,
 * недоступный сервер — это `null`, а не исключение: по контракту {@see DnsTransport} отсутствие ответа
 * неотличимо от невозможности спросить.
 *
 * @internal
 */
final readonly class StreamUdpTransport implements DnsTransport
{
    #[\Override]
    public function query(string $server, int $port, float $timeoutSeconds, string $message): ?string
    {
        $host = str_contains($server, ':') ? '[' . $server . ']' : $server;
        $socket = @stream_socket_client(
            'udp://' . $host . ':' . $port,
            error_code: $errno,
            error_message: $errstr,
            timeout: $timeoutSeconds,
        );

        if ($socket === false) {
            return null;
        }

        try {
            $seconds = (int) $timeoutSeconds;
            stream_set_timeout(
                $socket,
                seconds: $seconds,
                microseconds: (int) (($timeoutSeconds - (float) $seconds) * 1_000_000.0),
            );

            if (@fwrite($socket, $message) !== strlen($message)) {
                return null;
            }

            $response = @fread($socket, 4096);

            return \is_string($response) && $response !== '' ? $response : null;
        } finally {
            fclose($socket);
        }
    }
}

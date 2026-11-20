<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

/**
 * Один DNS-запрос: отправить сообщение и дождаться ответа. Резолверу всё равно, как именно — по умолчанию это
 * UDP до указанного сервера ({@see StreamUdpTransport}), но интерфейс оставляет место, например, для DNS over HTTPS.
 *
 * @api
 */
interface DnsTransport
{
    /**
     * @param string $message байты запроса от {@see \Rasuvaeff\PinnedHttpClient\Internal\DnsMessage}
     *
     * @return string|null байты ответа; `null` — сервер не ответил или транспорт не смог отправить
     */
    public function query(string $server, int $port, float $timeoutSeconds, string $message): ?string;
}

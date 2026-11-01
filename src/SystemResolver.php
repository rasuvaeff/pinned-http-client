<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

/**
 * Системный резолвер, оба стека: IPv4 через `gethostbynamel()` (учитывает `/etc/hosts`) и IPv6 через AAAA-записи
 * `dns_get_record()`. Таймаута у системного разрешения нет: он задаётся настройками резолвера ОС, а не клиентом.
 *
 * @api
 */
final readonly class SystemResolver implements Resolver
{
    #[\Override]
    public function resolve(string $host): array
    {
        $ipv4 = gethostbynamel($host);
        $addresses = $ipv4 === false ? [] : $ipv4;

        // dns_get_record предупреждает на несуществующем имени; отсутствие AAAA — обычный ответ, не ошибка
        $records = @dns_get_record($host, DNS_AAAA);

        foreach ($records === false ? [] : $records as $record) {
            $ipv6 = $this->text($record['ipv6'] ?? null);

            if ($ipv6 !== null) {
                $addresses[] = $ipv6;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function text(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}

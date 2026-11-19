<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

/**
 * Перехват глобальных DNS-функций для тестов SystemResolver: вызов без квалификации из
 * {@see SystemResolver} разрешается в этом namespace раньше глобальной функции. Сценарий
 * задаётся свойствами {@see DnsShim::$hostByNames} / {@see DnsShim::$aaaaRecords}; без
 * сценария обе функции переходят к настоящим (обычный сетевой тест остаётся сетевым).
 *
 * @internal
 */
final class DnsShim
{
    /** @var bool перехватывать ли вызовы; включается тестом с заранее заданным сценарием */
    public static bool $active = false;

    /**
     * @var array<string, list<string>|false> ответ `gethostbynamel()` по имени узла
     */
    public static array $hostByNames = [];

    /**
     * @var array<string, list<array{ipv6: string}>|false> ответ `dns_get_record(DNS_AAAA)` по имени узла
     */
    public static array $aaaaRecords = [];

    public static function reset(): void
    {
        self::$active = false;
        self::$hostByNames = [];
        self::$aaaaRecords = [];
    }
}

/**
 * Тень `gethostbynamel()`: вызывается только из {@see SystemResolver} и только пока
 * {@see DnsShim::$active}.
 *
 * @param string $hostname
 *
 * @return list<string>|false
 */
function gethostbynamel(string $hostname): array|false
{
    if (DnsShim::$active && \array_key_exists($hostname, DnsShim::$hostByNames)) {
        return DnsShim::$hostByNames[$hostname];
    }

    return \gethostbynamel($hostname);
}

/**
 * Тень `dns_get_record()` в объёме, который использует {@see SystemResolver}.
 *
 * @param string $hostname
 * @param int $type ожидается DNS_AAAA; другой тип уходит в настоящую функцию
 *
 * @return list<array{ipv6: string}>|false
 */
function dns_get_record(string $hostname, int $type = DNS_ANY): array|false
{
    if (DnsShim::$active && $type === DNS_AAAA && \array_key_exists($hostname, DnsShim::$aaaaRecords)) {
        return DnsShim::$aaaaRecords[$hostname];
    }

    return \dns_get_record($hostname, $type);
}

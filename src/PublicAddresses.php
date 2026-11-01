<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

/**
 * Пускает только публичные адреса: частные сети, loopback, link-local (включая адрес метаданных облака 169.254.169.254),
 * CGNAT, документационные, зарезервированные, multicast и широковещательные диапазоны отвергаются. Адрес IPv6, несущий
 * IPv4 (`::ffff:a.b.c.d`, NAT64 `64:ff9b::/96`), проверяется как этот IPv4; 6to4, Teredo, устаревшие
 * IPv4-совместимые (`::/96`, включая `::` и `::1`) и SIIT-адреса (`::ffff:0:0:0/96`) не пускаются целиком.
 *
 * @api
 */
final readonly class PublicAddresses implements AddressPolicy
{
    /** @var list<array{string, int}> */
    private const array BLOCKED = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
        ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15],
        ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
        ['::', 96], ['::ffff:0:0:0', 96], ['64:ff9b:1::', 48], ['100::', 64], ['2001::', 32], ['2001:db8::', 32], ['2002::', 16], ['3fff::', 20],
        ['fc00::', 7], ['fe80::', 10], ['fec0::', 10], ['ff00::', 8],
    ];

    private const string MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    private const string NAT64_PREFIX = "\0\x64\xff\x9b\0\0\0\0\0\0\0\0";

    #[\Override]
    public function allows(string $address): bool
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);

        if ($packed === false) {
            return false;
        }

        if (\strlen($packed) === 16 && (str_starts_with($packed, self::MAPPED_PREFIX) || str_starts_with($packed, self::NAT64_PREFIX))) {
            $packed = substr($packed, 12);
        }

        foreach (self::BLOCKED as [$network, $bits]) {
            $base = inet_pton($network);

            if ($base !== false && \strlen($base) === \strlen($packed) && $this->within($packed, $base, $bits)) {
                return false;
            }
        }

        return true;
    }

    private function within(string $packed, string $base, int $bits): bool
    {
        $whole = intdiv($bits, 8);

        if (substr($packed, 0, $whole) !== substr($base, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = 256 - (1 << (8 - $rest));

        return (\ord($packed[$whole]) & $mask) === (\ord($base[$whole]) & $mask);
    }
}

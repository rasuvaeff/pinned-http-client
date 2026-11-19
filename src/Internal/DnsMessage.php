<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Internal;

/**
 * Кодек DNS-сообщений (RFC 1035) в объёме, нужном резолверу: запрос A/AAAA с флагом RD и разбор ответа,
 * собирающий адреса из секции Answer (включая сжатые имена и цепочки CNAME, за которыми рекурсивный
 * сервер сам досылает записи). Любое повреждение сообщения — исключение: молчаливый сбор частичного
 * результата утаил бы причину, а границе между «битый ответ» и «нет адресов» различать их обязан
 * вызывающий ({@see \Rasuvaeff\PinnedHttpClient\UdpDnsResolver} превращает исключение в пустой список).
 *
 * @internal
 */
final class DnsMessage
{
    public const int TYPE_A = 1;

    public const int TYPE_AAAA = 28;

    /**
     * @param int $type {@see self::TYPE_A} или {@see self::TYPE_AAAA}
     * @param int $id случайный 16-битный идентификатор, который ответ обязан вернуть
     */
    public static function encodeQuery(string $name, int $type, int $id): string
    {
        $labels = explode('.', $name);
        $encoded = '';

        foreach ($labels as $label) {
            $length = strlen($label);

            if ($length === 0 || $length > 63) {
                throw new \InvalidArgumentException('Invalid DNS label length in the host name');
            }

            $encoded .= chr($length) . $label;
        }

        $header = pack('n6', $id, 0x0100, 1, 0, 0, 0);

        return $header . $encoded . "\x00" . pack('n2', $type, 1);
    }

    /**
     * Собирает IPv4/IPv6-адреса из секции Answer. Чужой id, не-ответ, ошибка RCODE или повреждение
     * сообщения — {@see InvalidDnsResponse}; адресов нет — обычный пустой список.
     *
     * @return list<string>
     */
    public static function parseAddresses(string $message, int $expectedId): array
    {
        // короткое сообщение unpack отбракует сам (false), отдельный guard не нужен
        /** @var array{id: int, flags: int, qdcount: int, ancount: int, nsmatch: int, nsadditional: int}|false $header */
        $header = unpack('nid/nflags/nqdcount/nancount/nnsmatch/nnsadditional', $message);

        if ($header === false) {
            throw new InvalidDnsResponse('The response is shorter than the fixed 12-byte header');
        }

        if ($header['id'] !== $expectedId) {
            throw new InvalidDnsResponse('The response id does not match the query');
        }

        if (($header['flags'] & 0x8000) === 0) {
            throw new InvalidDnsResponse('The message is not a response (QR unset)');
        }

        if (($header['flags'] & 0x000F) !== 0) {
            throw new InvalidDnsResponse('The response reports an error (RCODE is not NOERROR)');
        }

        $offset = 12;

        for ($question = 0; $question < $header['qdcount']; $question++) {
            $offset = self::skipName($message, $offset) + 4;
        }

        $addresses = [];

        for ($answer = 0; $answer < $header['ancount']; $answer++) {
            $offset = self::skipName($message, $offset);

            /** @var array{type: int, class: int, ttl: int, rdlength: int}|false $record */
            $record = unpack('ntype/nclass/Nttl/nrdlength', substr($message, $offset));

            if ($record === false) {
                throw new InvalidDnsResponse('The answer record header is truncated');
            }

            $offset += 10;
            $rdata = substr($message, $offset, $record['rdlength']);

            if (strlen($rdata) !== $record['rdlength']) {
                throw new InvalidDnsResponse('The answer record data is truncated');
            }

            $address = match ($record['type']) {
                self::TYPE_A => $record['rdlength'] === 4 ? inet_ntop($rdata) : false,
                self::TYPE_AAAA => $record['rdlength'] === 16 ? inet_ntop($rdata) : false,
                default => false,
            };

            if ($address === false) {
                // не-адресные и неуместные записи пропускаются; ответ продолжается
                $offset += $record['rdlength'];

                continue;
            }

            $addresses[] = $address;
            $offset += $record['rdlength'];
        }

        return $addresses;
    }

    /**
     * Проводит мимо одного DNS-имени: последовательные метки, сжатые указатели (включая цепочки),
     * нулевой терминатор. Повреждение имени — {@see InvalidDnsResponse} со своей причиной.
     *
     * @return int смещение за именем
     */
    private static function skipName(string $message, int $offset): int
    {
        $length = strlen($message);
        $jumps = 0;
        $next = $offset;
        // after the first compression pointer the name ends somewhere else in the
        // message; sequential reading resumes right behind the two pointer bytes
        $resume = null;

        while (true) {
            if ($next >= $length) {
                throw new InvalidDnsResponse('The name runs past the end of the message');
            }

            $size = ord($message[$next]);

            if ($size === 0) {
                // sequential path ends behind the null byte; pointer path already
                // resumed behind the two pointer bytes
                return $resume ?? $next + 1;
            }

            if (($size & 0xC0) === 0xC0) {
                if ($next + 1 >= $length) {
                    throw new InvalidDnsResponse('The compression pointer is cut before its second byte');
                }

                $jumps++;

                if ($jumps > 100) {
                    throw new InvalidDnsResponse('The compression pointer loops');
                }

                $resume ??= $next + 2;

                $next = (($size & 0x3F) << 8) | ord($message[$next + 1]);

                continue;
            }

            if (($size & 0xC0) !== 0) {
                throw new InvalidDnsResponse('The name uses a reserved label type');
            }

            if ($next + 1 + $size > $length) {
                throw new InvalidDnsResponse('The name label runs past the end of the message');
            }

            $next += 1 + $size;
        }
    }
}

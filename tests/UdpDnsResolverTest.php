<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests;

use Rasuvaeff\PinnedHttpClient\DnsTransport;
use Rasuvaeff\PinnedHttpClient\Internal\DnsMessage;
use Rasuvaeff\PinnedHttpClient\UdpDnsResolver;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(UdpDnsResolver::class)]
final class UdpDnsResolverTest
{
    private const string A = '93.184.216.34';

    private const string AAAA = '2606:2800:220:1:248:1893:25c8:1946';

    public function asksBothStacksAndJoinsTheAddresses(): void
    {
        $transport = Understudy::for(DnsTransport::class);
        // запрос заканчивается (type, class); A — type 1, AAAA — type 28.
        // id резолвер генерирует сам; заглушка возвращает его в ответе, как это делает настоящий сервер
        $aResponse = $this->answer(self::A, DnsMessage::TYPE_A);
        $aaaaResponse = $this->answer(self::AAAA, DnsMessage::TYPE_AAAA);
        when(fn() => $transport->query(Arg::any(), Arg::any(), Arg::any(), Arg::satisfies(
            static fn(string $message): bool => str_ends_with($message, "\x00\x01\x00\x01"),
        )))->answers(static fn(Invocation $invocation): string
            => substr((string) $invocation->args[3], 0, 2) . substr($aResponse, 2));
        when(fn() => $transport->query(Arg::any(), Arg::any(), Arg::any(), Arg::satisfies(
            static fn(string $message): bool => str_ends_with($message, "\x00\x1c\x00\x01"),
        )))->answers(static fn(Invocation $invocation): string
            => substr((string) $invocation->args[3], 0, 2) . substr($aaaaResponse, 2));

        $resolver = new UdpDnsResolver(server: '10.0.0.53', timeoutSeconds: 2.0, transport: $transport);

        Assert::same($resolver->resolve('example.test'), [self::A, self::AAAA]);
    }

    public function anUnansweredStackDoesNotFailTheResolution(): void
    {
        $transport = Understudy::for(DnsTransport::class);
        when(fn() => $transport->query(Arg::any(), Arg::any(), Arg::any(), Arg::any()));

        $resolver = new UdpDnsResolver(server: '10.0.0.53', timeoutSeconds: 2.0, transport: $transport);

        // loose-режим отвечает null на оба запроса: имя не разрешается — пустой список
        Assert::same($resolver->resolve('example.test'), []);
    }

    public function duplicateAddressesAreReportedOnce(): void
    {
        $transport = Understudy::for(DnsTransport::class);
        // A-ответ несёт дважды один адрес, AAAA-ответ — другой: dedup убирает только дубль
        $aResponse = $this->answerWithDuplicates(self::A);
        $aaaaResponse = $this->answer(self::AAAA, DnsMessage::TYPE_AAAA);
        when(fn() => $transport->query(Arg::any(), Arg::any(), Arg::any(), Arg::satisfies(
            static fn(string $message): bool => str_ends_with($message, "\x00\x01\x00\x01"),
        )))->answers(static fn(Invocation $invocation): string
            => substr((string) $invocation->args[3], 0, 2) . substr($aResponse, 2));
        when(fn() => $transport->query(Arg::any(), Arg::any(), Arg::any(), Arg::satisfies(
            static fn(string $message): bool => str_ends_with($message, "\x00\x1c\x00\x01"),
        )))->answers(static fn(Invocation $invocation): string
            => substr((string) $invocation->args[3], 0, 2) . substr($aaaaResponse, 2));

        $resolver = new UdpDnsResolver(server: '10.0.0.53', timeoutSeconds: 2.0, transport: $transport);

        Assert::same($resolver->resolve('example.test'), [self::A, self::AAAA]);
    }

    public function returnsEmptyListWhenTheServerDoesNotAnswer(): void
    {
        // настоящий транспорт против закрытого порта: таймаут срабатывает, а не виснет
        $resolver = new UdpDnsResolver(server: '127.0.0.1', port: 1, timeoutSeconds: 0.2);

        Assert::same($resolver->resolve('example.test'), []);
    }

    public function theHighestPortIsLegal(): void
    {
        $transport = Understudy::for(DnsTransport::class);
        $port = Arg::captor();
        when(fn() => $transport->query(Arg::any(), $port->capture(), Arg::any(), Arg::any()));

        (new UdpDnsResolver(server: '10.0.0.53', port: 65535, timeoutSeconds: 2.0, transport: $transport))
            ->resolve('example.test');

        Assert::same($port->last(), 65535);
    }

    public function anUnansweredAQueryFailsTheWholeResolution(): void
    {
        $transport = Understudy::for(DnsTransport::class);
        $aaaaResponse = $this->answer(self::AAAA, DnsMessage::TYPE_AAAA);
        when(fn() => $transport->query(Arg::any(), Arg::any(), Arg::any(), Arg::satisfies(
            static fn(string $message): bool => str_ends_with($message, "\x00\x01\x00\x01"),
        ))); // A-запрос не отвечает (loose null)
        when(fn() => $transport->query(Arg::any(), Arg::any(), Arg::any(), Arg::satisfies(
            static fn(string $message): bool => str_ends_with($message, "\x00\x1c\x00\x01"),
        )))->answers(static fn(Invocation $invocation): string
            => substr((string) $invocation->args[3], 0, 2) . substr($aaaaResponse, 2));

        $resolver = new UdpDnsResolver(server: '10.0.0.53', timeoutSeconds: 2.0, transport: $transport);

        // частичный результат не отдаётся, и второй запрос не тратит время зря
        Assert::same($resolver->resolve('example.test'), []);
        Understudy::verify(fn() => $transport->query(Arg::any(), Arg::any(), Arg::any(), Arg::satisfies(
            static fn(string $message): bool => str_ends_with($message, "\x00\x1c\x00\x01"),
        )), never: true);
    }

    public function anUnansweredAaaaQueryFailsTheWholeResolution(): void
    {
        $transport = Understudy::for(DnsTransport::class);
        $aResponse = $this->answer(self::A, DnsMessage::TYPE_A);
        when(fn() => $transport->query(Arg::any(), Arg::any(), Arg::any(), Arg::satisfies(
            static fn(string $message): bool => str_ends_with($message, "\x00\x01\x00\x01"),
        )))->answers(static fn(Invocation $invocation): string
            => substr((string) $invocation->args[3], 0, 2) . substr($aResponse, 2));
        when(fn() => $transport->query(Arg::any(), Arg::any(), Arg::any(), Arg::satisfies(
            static fn(string $message): bool => str_ends_with($message, "\x00\x1c\x00\x01"),
        ))); // AAAA-запрос не отвечает

        $resolver = new UdpDnsResolver(server: '10.0.0.53', timeoutSeconds: 2.0, transport: $transport);

        Assert::same($resolver->resolve('example.test'), []);
    }

    public function aDamagedReplyToEitherQueryFailsTheWholeResolution(): void
    {
        foreach (["\x00\x01\x00\x01", "\x00\x1c\x00\x01"] as $damagedSuffix) {
            $transport = Understudy::for(DnsTransport::class);
            $aResponse = $this->answer(self::A, DnsMessage::TYPE_A);
            $aaaaResponse = $this->answer(self::AAAA, DnsMessage::TYPE_AAAA);
            when(fn() => $transport->query(Arg::any(), Arg::any(), Arg::any(), Arg::any()))
                ->answers(static function (Invocation $invocation) use ($damagedSuffix, $aResponse, $aaaaResponse): string {
                    $message = (string) $invocation->args[3];

                    if (str_ends_with($message, $damagedSuffix)) {
                        return 'garbage';
                    }

                    return substr($message, 0, 2) . substr(
                        str_ends_with($message, "\x00\x01\x00\x01") ? $aResponse : $aaaaResponse,
                        2,
                    );
                });

            $resolver = new UdpDnsResolver(server: '10.0.0.53', timeoutSeconds: 2.0, transport: $transport);

            Assert::same($resolver->resolve('example.test'), []);
        }
    }

    #[DataProvider('invalidSettingsProvider')]
    #[ExpectException(\InvalidArgumentException::class)]
    public function invalidSettingsAreRejected(string $server, int $port, float $timeout): void
    {
        new UdpDnsResolver(server: $server, port: $port, timeoutSeconds: $timeout);
    }

    public static function invalidSettingsProvider(): iterable
    {
        yield 'server is a name, not an IP' => ['resolver.example.test', 53, 1.0];
        yield 'server is empty' => ['', 53, 1.0];
        yield 'zero port' => ['127.0.0.1', 0, 1.0];
        yield 'port over 65535' => ['127.0.0.1', 65536, 1.0];
        yield 'zero timeout' => ['127.0.0.1', 53, 0.0];
        yield 'negative timeout' => ['127.0.0.1', 53, -1.0];
        yield 'nan timeout' => ['127.0.0.1', 53, \NAN];
    }

    /**
     * A-ответ с двумя одинаковыми A-записями: сборщик видит один адрес дважды.
     */
    private function answerWithDuplicates(string $address): string
    {
        $query = DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_A, 0x0000);
        $record = "\xc0\x0c" . "\x00\x01" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x04"
            . inet_pton($address);

        return "\x00\x00" . "\x81\x80"
            . "\x00\x01" . "\x00\x02" . "\x00\x00" . "\x00\x00" // qdcount=1, ancount=2
            . substr($query, 12) // вопрос
            . $record . $record;
    }

    /**
     * Ответ с одной A- или AAAA-записью: заголовок QR=1, RD=1, RCODE=0, вопрос-эхо и запись с сжатым
     * именем-указателем на вопрос.
     */
    private function answer(string $address, int $type): string
    {
        $query = DnsMessage::encodeQuery('example.test', $type, 0x0000);
        $rdlength = $type === DnsMessage::TYPE_A ? 4 : 16;
        $record = "\xc0\x0c" . pack('n', $type) . "\x00\x01" . "\x00\x00\x00\x3c" . pack('n', $rdlength)
            . inet_pton($address);

        return "\x00\x00" . "\x81\x80"
            . "\x00\x01" . "\x00\x01" . "\x00\x00" . "\x00\x00" // qdcount=1, ancount=1
            . substr($query, 12) // вопрос
            . $record;
    }
}

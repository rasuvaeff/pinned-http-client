<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests;

use Rasuvaeff\PinnedHttpClient\DnsShim;
use Rasuvaeff\PinnedHttpClient\SystemResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

require_once __DIR__ . '/Support/SystemResolverDnsShim.php';

/**
 * Системный резолвер: `/etc/hosts` для IPv4 (localhost есть везде), пустой ответ для `.invalid` без
 * предупреждений; сценарии AAAA-ветки задаются перехватом DNS-функций ({@see DnsShim}).
 */
#[Test]
#[Covers(SystemResolver::class)]
final class SystemResolverTest
{
    #[BeforeTest]
    public function resetShim(): void
    {
        DnsShim::reset();
    }

    #[AfterTest]
    public function tearDownShim(): void
    {
        DnsShim::reset();
    }

    public function localhostResolvesThroughTheHostsFile(): void
    {
        $addresses = (new SystemResolver())->resolve('localhost');

        Assert::true(\in_array('127.0.0.1', $addresses, strict: true));
        Assert::same($addresses, array_values(array_unique($addresses)));
    }

    public function aNameThatCannotExistResolvesToNothingWithoutAWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            Assert::same((new SystemResolver())->resolve('nothing.invalid'), []);
        } finally {
            restore_error_handler();
        }

        Assert::same($warnings, []);
    }

    public function collectsBothStacksAndDeduplicates(): void
    {
        DnsShim::$active = true;
        DnsShim::$hostByNames['dual.example.test'] = ['192.0.2.10', '192.0.2.10', '192.0.2.11'];
        DnsShim::$aaaaRecords['dual.example.test'] = [
            ['ipv6' => '2001:db8::10'],
            ['ipv6' => '2001:db8::10'],
        ];

        Assert::same(
            (new SystemResolver())->resolve('dual.example.test'),
            ['192.0.2.10', '192.0.2.11', '2001:db8::10'],
        );
    }

    public function skipsRecordsWithoutAnAddress(): void
    {
        DnsShim::$active = true;
        DnsShim::$hostByNames['txt.example.test'] = ['192.0.2.10'];
        DnsShim::$aaaaRecords['txt.example.test'] = [
            ['ipv6' => '2001:db8::10'],
            // запись без адреса (TXT в этом ключе): собирается мимо
            ['other' => 'text'],
            ['ipv6' => '2001:db8::11'],
        ];

        Assert::same(
            (new SystemResolver())->resolve('txt.example.test'),
            ['192.0.2.10', '2001:db8::10', '2001:db8::11'],
        );
    }

    public function aFailedIpv4LookupStillAsksForIpv6(): void
    {
        DnsShim::$active = true;
        DnsShim::$hostByNames['v6only.example.test'] = false;
        DnsShim::$aaaaRecords['v6only.example.test'] = [['ipv6' => '2001:db8::42']];

        Assert::same((new SystemResolver())->resolve('v6only.example.test'), ['2001:db8::42']);
    }
}

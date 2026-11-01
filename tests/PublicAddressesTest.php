<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests;

use Rasuvaeff\PinnedHttpClient\PublicAddresses;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PublicAddresses::class)]
final class PublicAddressesTest
{
    private const array REFUSED = [
        '0.0.0.0', '0.255.255.255', '10.0.0.1', '10.255.255.255', '100.64.0.1', '100.127.255.255', '127.0.0.1', '127.255.255.254',
        '169.254.169.254', '172.16.0.1', '172.31.255.255', '192.0.0.1', '192.0.2.5', '192.88.99.1', '192.168.1.1', '198.18.0.1',
        '198.19.255.255', '198.51.100.7', '203.0.113.9', '224.0.0.1', '239.255.255.255', '240.0.0.1', '255.255.255.255',
        '::', '::1', '64:ff9b:1::1', '100::1', '2001::1', '2001:db8::1', '2002:7f00:1::1', 'fc00::1', 'fd12:3456::1',
        'fe80::1', 'febf::1', 'fec0::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254',
        '64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe', '::7f00:1', '::808:808', '::ffff:0:7f00:1', '::ffff:0:808:808',
        '3fff::1', '3fff:fff:ffff::1',
    ];

    private const array ALLOWED = [
        '1.1.1.1', '8.8.8.8', '93.184.216.34', '9.255.255.255', '11.0.0.0', '100.63.255.255', '100.128.0.0', '126.255.255.255',
        '128.0.0.1', '169.253.255.255', '169.255.0.0', '172.15.255.255', '172.32.0.0', '192.0.1.1', '192.169.0.1', '198.17.255.255',
        '198.20.0.0', '223.255.255.255', '2606:4700:4700::1111', '2a00:1450:4001::200e', '::ffff:8.8.8.8', '64:ff9b::808:808',
        '2001:4860:4860::8888', '2003::1', '32.1.0.1', '32.1.13.184', '32.2.0.1', '32.0.0.1', '100.128.0.1', '1.0.0.1',
        '3ffe:ffff::1', '3fff:1000::1', '::1:0:0:1',
    ];

    public function everyInternalOrReservedAddressIsRefused(): void
    {
        $policy = new PublicAddresses();

        foreach (self::REFUSED as $address) {
            Assert::false($policy->allows($address), $address);
        }
    }

    public function publicAddressesAndTheirNeighboursOutsideTheRangesAreAllowed(): void
    {
        $policy = new PublicAddresses();

        foreach (self::ALLOWED as $address) {
            Assert::true($policy->allows($address), $address);
        }
    }

    public function aVerdictNeverRaisesAPhpWarning(): void
    {
        $policy = new PublicAddresses();
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });

        try {
            foreach ([...self::REFUSED, ...self::ALLOWED] as $address) {
                $policy->allows($address);
            }
        } finally {
            restore_error_handler();
        }

        Assert::true(actual: true);
    }

    public function whatIsNotAnAddressIsRefused(): void
    {
        $policy = new PublicAddresses();

        foreach (['', 'example.com', '127.1', '2130706433', '0x7f.0.0.1', '1.2.3', '1.2.3.4.5', '256.1.1.1', '8.8.8.8 ', ' 8.8.8.8', "8.8.8.8\n", '[::1]', '::ffff:', 'gggg::1'] as $text) {
            Assert::false($policy->allows($text), var_export($text, return: true));
        }
    }

    /**
     * Любой IPv4 внутри запрещённой сети отвергается, и адрес, вложенный в IPv6 (`::ffff:`, NAT64), получает тот же ответ.
     */
    #[Property(runs: 400)]
    public function theVerdictOnAnIpv4DoesNotDependOnHowItIsWrapped(int $number): void
    {
        $policy = new PublicAddresses();
        $v4 = long2ip($number);
        $hex = sprintf('%x:%x', ($number >> 16) & 0xFFFF, $number & 0xFFFF);
        $expected = $policy->allows($v4);

        Assert::same($policy->allows('::ffff:' . $v4), $expected);
        Assert::same($policy->allows('64:ff9b::' . $hex), $expected);
    }

    /**
     * Независимая модель: перебор запрещённых сетей арифметикой над числом.
     */
    #[Property(runs: 600)]
    public function anIpv4IsAllowedExactlyWhenItIsOutsideEveryBlockedNetwork(int $number): void
    {
        $blocked = [
            [0x00000000, 8], [0x0A000000, 8], [0x64400000, 10], [0x7F000000, 8], [0xA9FE0000, 16], [0xAC100000, 12],
            [0xC0000000, 24], [0xC0000200, 24], [0xC0586300, 24], [0xC0A80000, 16], [0xC6120000, 15],
            [0xC6336400, 24], [0xCB007100, 24], [0xE0000000, 4], [0xF0000000, 4],
        ];
        $expected = true;

        foreach ($blocked as [$network, $bits]) {
            $mask = ($bits === 0) ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;

            if (($number & $mask) === $network) {
                $expected = false;
            }
        }

        Assert::same((new PublicAddresses())->allows(long2ip($number)), $expected);
    }

    /**
     * @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface>
     */
    public static function theVerdictOnAnIpv4DoesNotDependOnHowItIsWrappedGenerators(): array
    {
        return ['number' => Gen::frequency([[3, Gen::intBetween(0, 0xFFFFFFFF)], [1, Gen::elements([0x7F000001, 0xA9FEA9FE, 0x0A000001, 0xC0A80101, 0x08080808, 0xFFFFFFFF, 0, 0x64400000, 0x643FFFFF])]])];
    }

    /**
     * @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface>
     */
    public static function anIpv4IsAllowedExactlyWhenItIsOutsideEveryBlockedNetworkGenerators(): array
    {
        return self::theVerdictOnAnIpv4DoesNotDependOnHowItIsWrappedGenerators();
    }
}

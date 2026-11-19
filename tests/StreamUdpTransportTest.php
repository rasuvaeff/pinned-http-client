<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests;

use Rasuvaeff\PinnedHttpClient\StreamShim;
use Rasuvaeff\PinnedHttpClient\StreamUdpTransport;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

require_once __DIR__ . '/Support/StreamShim.php';

#[Test]
#[Covers(StreamUdpTransport::class)]
final class StreamUdpTransportTest
{
    #[BeforeTest]
    public function resetShim(): void
    {
        StreamShim::reset();
    }

    #[AfterTest]
    public function tearDownShim(): void
    {
        StreamShim::reset();
    }

    public function completesOneExchangeAndClosesTheSocket(): void
    {
        StreamShim::$written = 3;
        StreamShim::$read = 'RESP';

        $result = (new StreamUdpTransport())->query('127.0.0.1', 53, 2.0, 'abc');

        Assert::same($result, 'RESP');
        Assert::same(StreamShim::$log, [
            'connect udp://127.0.0.1:53 timeout=2',
            'timeout 2.000000',
            'write 3',
            'read \'RESP\'',
            'close',
        ]);
    }

    public function bracketsAnIpv6Server(): void
    {
        StreamShim::$written = 3;
        StreamShim::$read = 'RESP';

        $result = (new StreamUdpTransport())->query('::1', 53, 1.5, 'abc');

        Assert::same($result, 'RESP');
        Assert::string(StreamShim::$log[0])->contains('connect udp://[::1]:53');
        // дробный таймаут раскладывается в секунды и микросекунды
        Assert::string(StreamShim::$log[1])->contains('timeout 1.500000');
    }

    public function aRefusedConnectionYieldsNothing(): void
    {
        StreamShim::$connect = false;

        Assert::same((new StreamUdpTransport())->query('127.0.0.1', 53, 2.0, 'abc'), null);
        Assert::same(StreamShim::$log, ['connect udp://127.0.0.1:53 timeout=2']);
    }

    public function aFailedWriteYieldsNothingAndStillCloses(): void
    {
        StreamShim::$written = false;

        Assert::same((new StreamUdpTransport())->query('127.0.0.1', 53, 2.0, 'abc'), null);
        Assert::same(StreamShim::$log[2], 'write false');
        Assert::same(StreamShim::$log[3], 'close');
    }

    public function aShortWriteYieldsNothing(): void
    {
        StreamShim::$written = 2; // написали 2 из 3 байтов

        Assert::same((new StreamUdpTransport())->query('127.0.0.1', 53, 2.0, 'abc'), null);
    }

    public function aFailedReadYieldsNothing(): void
    {
        StreamShim::$written = 3;
        StreamShim::$read = false;

        Assert::same((new StreamUdpTransport())->query('127.0.0.1', 53, 2.0, 'abc'), null);
        Assert::same(StreamShim::$log[4], 'close');
    }

    public function aLongerThanRequestedAnswerIsReadInChunks(): void
    {
        // ответ длиннее 4096: fread(requested length) отрезает ровно свой лимит
        StreamShim::$written = 3;
        StreamShim::$read = str_repeat('x', 5000);

        $result = (new StreamUdpTransport())->query('127.0.0.1', 53, 2.0, 'abc');

        Assert::true(\is_string($result) && strlen($result) === 4096);
    }

    public function anEmptyDatagramYieldsNothing(): void
    {
        StreamShim::$written = 3;
        StreamShim::$read = '';

        Assert::same((new StreamUdpTransport())->query('127.0.0.1', 53, 2.0, 'abc'), null);
    }
}

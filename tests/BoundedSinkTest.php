<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests;

use GuzzleHttp\Psr7\Utils;
use Rasuvaeff\PinnedHttpClient\Internal\BoundedSink;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(BoundedSink::class)]
final class BoundedSinkTest
{
    public function writesUpToTheLimitAndRefusesTheChunkThatCrossesIt(): void
    {
        $sink = new BoundedSink(Utils::streamFor(''), 5);

        Assert::same($sink->write('abc'), 3);
        Assert::same($sink->write('de'), 2);
        Assert::false($sink->exceeded());
        Assert::same($sink->write('f'), 0);
        Assert::true($sink->exceeded());
        Assert::same((string) $sink, 'abcde');
    }

    public function aRefusedChunkIsNotCountedAgainstLaterSmallerOnes(): void
    {
        $sink = new BoundedSink(Utils::streamFor(''), 4);

        Assert::same($sink->write('abc'), 3);
        Assert::same($sink->write('de'), 0);
        Assert::same($sink->write('d'), 1);
        Assert::same((string) $sink, 'abcd');
    }

    public function everythingElseIsTheInnerStream(): void
    {
        $inner = Utils::streamFor('');
        $sink = new BoundedSink($inner, 100);
        $sink->write('hello world');

        Assert::same([$sink->getSize(), $sink->tell(), $sink->isSeekable(), $sink->isReadable(), $sink->isWritable()], [11, 11, true, true, true]);
        $sink->rewind();
        Assert::same($sink->read(5), 'hello');
        $sink->seek(6);
        Assert::same($sink->getContents(), 'world');
        Assert::true($sink->eof());
        Assert::same($sink->getMetadata('mode'), $inner->getMetadata('mode'));
        Assert::same($sink->getMetadata(), $inner->getMetadata());
        Assert::true(\is_resource($sink->detach()));
    }

    public function closingClosesTheInnerStream(): void
    {
        $inner = Utils::streamFor('data');
        (new BoundedSink($inner, 10))->close();

        Assert::null($inner->getSize());
        Assert::false($inner->isReadable());
    }
}

<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests;

use Rasuvaeff\PinnedHttpClient\SystemResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Системный резолвер: `/etc/hosts` для IPv4 (localhost есть везде), пустой ответ для `.invalid` без предупреждений.
 */
#[Test]
#[Covers(SystemResolver::class)]
final class SystemResolverTest
{
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
}

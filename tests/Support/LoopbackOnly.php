<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests\Support;

use Rasuvaeff\PinnedHttpClient\AddressPolicy;

/**
 * Пускает только 127.0.0.1 — адрес тестового сервера; всё остальное (10.0.0.1 «внутренней» сети) отвергается.
 */
final readonly class LoopbackOnly implements AddressPolicy
{
    #[\Override]
    public function allows(string $address): bool
    {
        return $address === '127.0.0.1';
    }
}

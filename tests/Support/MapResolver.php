<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests\Support;

use Rasuvaeff\PinnedHttpClient\Resolver;

/**
 * Таблица вместо DNS: имена `.invalid` существуют только здесь.
 */
final class MapResolver implements Resolver
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param array<string, list<string>> $answers
     */
    public function __construct(private readonly array $answers) {}

    #[\Override]
    public function resolve(string $host): array
    {
        $this->asked[] = $host;

        return $this->answers[$host] ?? [];
    }
}

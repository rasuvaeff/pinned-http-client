<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

/**
 * Имя узла → адреса, к которым можно подключиться. Каждый адрес потом проходит {@see AddressPolicy}.
 *
 * @api
 */
interface Resolver
{
    /**
     * @return list<string> текстовые IPv4/IPv6-адреса; пустой список — имя не разрешается
     */
    public function resolve(string $host): array;
}

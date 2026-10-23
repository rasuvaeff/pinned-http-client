<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

/**
 * Решает, можно ли соединяться с адресом, в который разрешилось имя узла.
 *
 * @api
 */
interface AddressPolicy
{
    /**
     * @param string $address IPv4 или IPv6 в текстовом виде, уже разобранный резолвером
     */
    public function allows(string $address): bool;
}

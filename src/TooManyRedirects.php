<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Цепочка переадресаций длиннее разрешённой.
 *
 * @api
 */
final class TooManyRedirects extends ClientException implements RequestExceptionInterface
{
    /**
     * @param int<0, max> $limit
     */
    public function __construct(RequestInterface $request, public readonly int $limit)
    {
        parent::__construct($request, \sprintf('More than %d redirects', $limit));
    }
}

<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Тело ответа (после распаковки) превысило `maxBodyBytes`: передача оборвана, ответа нет.
 *
 * @api
 */
final class ResponseTooLarge extends ClientException implements RequestExceptionInterface
{
    /**
     * @param positive-int $limit
     */
    public function __construct(RequestInterface $request, public readonly int $limit)
    {
        parent::__construct($request, \sprintf('The response body exceeds %d bytes', $limit));
    }
}

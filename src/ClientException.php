<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Общий предок исключений клиента: ловить его — значит ловить всё, что бросает {@see PinnedClient}.
 *
 * @api
 */
abstract class ClientException extends \RuntimeException implements ClientExceptionInterface
{
    public function __construct(private readonly RequestInterface $request, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}

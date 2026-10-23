<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Запрос не ушёл: у узла нет разрешённого адреса, схема не http(s) или соединение не удалось.
 *
 * @api
 */
final class PinningFailed extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $request, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    #[\Override]
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}

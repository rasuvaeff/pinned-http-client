<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Запрос не ушёл: его отверг клиент (схема, узел, разрешение имени, политика адресов). Сообщение адресов не называет.
 *
 * @api
 */
final class RequestRefused extends ClientException implements RequestExceptionInterface
{
    public function __construct(RequestInterface $request, public readonly Refusal $reason)
    {
        parent::__construct($request, match ($reason) {
            Refusal::UnsupportedScheme => 'Only http and https are fetched',
            Refusal::MissingHost => 'The URL has no host',
            Refusal::AddressNotAllowed => 'The host resolves to an address the policy does not allow',
        });
    }
}

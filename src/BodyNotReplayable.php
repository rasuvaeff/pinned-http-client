<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Переадресация сохраняет тело запроса, но оно уже отправлено и перемотать его нельзя. Запрос — тот, на который пришла
 * переадресация; следующий переход не отправлен.
 *
 * @api
 */
final class BodyNotReplayable extends ClientException implements RequestExceptionInterface
{
    public function __construct(RequestInterface $request)
    {
        parent::__construct($request, 'A non-seekable request body cannot be sent again to the redirect target');
    }
}

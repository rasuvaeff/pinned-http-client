<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Имя узла не разрешилось ни в один адрес. Сетевое состояние, а не отказ: системный резолвер не отличает
 * NXDOMAIN от SERVFAIL или таймаута, поэтому повтор позже может удаться.
 *
 * @api
 */
final class ResolutionFailed extends ClientException implements NetworkExceptionInterface
{
    public function __construct(RequestInterface $request)
    {
        parent::__construct($request, 'The host does not resolve');
    }
}

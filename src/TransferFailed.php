<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

use Psr\Http\Client\NetworkExceptionInterface;

/**
 * Запрос был разрешён, но передача не удалась: соединение, TLS, таймаут. Причина — в `getPrevious()`.
 *
 * @api
 */
final class TransferFailed extends ClientException implements NetworkExceptionInterface {}

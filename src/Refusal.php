<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

/**
 * Почему запрос не был отправлен.
 *
 * @api
 */
enum Refusal
{
    /** Схема не http и не https. */
    case UnsupportedScheme;

    /** В URL нет узла. */
    case MissingHost;

    /** Хотя бы один адрес узла не прошёл политику. */
    case AddressNotAllowed;
}

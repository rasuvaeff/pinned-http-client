<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

/**
 * Ответ дошёл, но не годится как документ метаданных: статус не 2xx, битый JSON, скаляр в корне. Тело ответа в сообщение не
 * попадает — оно может быть произвольным по размеру и содержимому.
 *
 * @api
 */
final class InvalidMetadataDocument extends ClientException {}

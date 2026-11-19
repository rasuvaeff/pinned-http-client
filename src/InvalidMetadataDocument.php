<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

/**
 * Ответ дошёл, но не оказался JSON-объектом: битый JSON, скаляр, массив в корне. Тело ответа в сообщение не
 * попадает — оно может быть произвольным по размеру и содержимому.
 *
 * @api
 */
final class InvalidMetadataDocument extends ClientException {}

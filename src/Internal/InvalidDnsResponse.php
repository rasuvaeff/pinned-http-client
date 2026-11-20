<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Internal;

/**
 * Ответ DNS-сервера не разобрался: чужой id, не-ответ, ошибка RCODE, обрезанное сообщение или
 * зацикленное сжатое имя. Внутреннее исключение: {@see \Rasuvaeff\PinnedHttpClient\UdpDnsResolver}
 * поглощает его и даёт пустой список по контракту {@see \Rasuvaeff\PinnedHttpClient\Resolver}.
 *
 * @internal
 */
final class InvalidDnsResponse extends \RuntimeException {}

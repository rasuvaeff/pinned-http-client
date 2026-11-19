<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

/**
 * Перехват глобальных stream-функций для тестов {@see StreamUdpTransport}: вызовы без квалификации из
 * этого namespace разрешаются в эти функции раньше глобальных. Сценарий задаётся свойствами
 * {@see StreamShim::$connect}, {@see StreamShim::$written}, {@see StreamShim::$read}; вся активность
 * логируется в {@see StreamShim::$log} — сетевого обмена нет, ресурс из `connect` — маркер `fopen()`,
 * а не настоящий сокет. Без активного сценария функции переходят к настоящим.
 *
 * @internal
 */
final class StreamShim
{
    /** @var false|'marker' результат `stream_socket_client()`; marker — настоящий resource-маркер */
    public static false|string $connect = 'marker';

    /** @var false|int|null ответ `fwrite()`; null — настоящая запись (только вне сценария) */
    public static false|int|null $written = null;

    /** @var false|string|null ответ `fread()`; null — настоящее чтение (только вне сценария) */
    public static false|string|null $read = null;

    /**
     * @var list<string> каждое действие одной строкой, в порядке вызова
     */
    public static array $log = [];

    public static function reset(): void
    {
        self::$connect = 'marker';
        self::$written = null;
        self::$read = null;
        self::$log = [];
    }
}

/**
 * Тень `stream_socket_client()`.
 *
 * @param string $remote_socket
 * @param int|null $error_code
 * @param string|null $error_message
 * @param float|null $timeout
 *
 * @return resource|false
 */
function stream_socket_client(
    string $remote_socket,
    ?int &$error_code = null,
    ?string &$error_message = null,
    ?float $timeout = null,
) {
    StreamShim::$log[] = sprintf('connect %s timeout=%s', $remote_socket, $timeout === null ? 'null' : (string) $timeout);

    if (StreamShim::$connect === false) {
        $error_code = 111;
        $error_message = 'Connection refused';

        return false;
    }

    if (StreamShim::$connect === 'marker') {
        $marker = fopen('php://memory', 'w');
        if ($marker === false) {
            $error_code = 1;
            $error_message = 'marker failed';

            return false;
        }

        return $marker;
    }

    return \stream_socket_client($remote_socket, $error_code, $error_message, $timeout);
}

/**
 * Тень `stream_set_timeout()`.
 *
 * @param resource $stream
 */
function stream_set_timeout($stream, int $seconds, int $microseconds = 0): bool
{
    StreamShim::$log[] = sprintf('timeout %d.%06d', $seconds, $microseconds);

    return true;
}

/**
 * Тень `fwrite()`.
 *
 * @param resource $stream
 * @param string $data
 *
 * @return int|false
 */
function fwrite($stream, string $data): int|false
{
    if (StreamShim::$written === null) {
        $result = \fwrite($stream, $data);
        StreamShim::$log[] = sprintf('write %s', var_export($result, true));

        return $result;
    }

    StreamShim::$log[] = sprintf('write %s', var_export(StreamShim::$written, true));

    return StreamShim::$written;
}

/**
 * Тень `fread()`.
 *
 * @param resource $stream
 * @param int $length
 *
 * @return string|false
 */
function fread($stream, int $length): string|false
{
    if (StreamShim::$read === null) {
        $result = \fread($stream, $length);
        StreamShim::$log[] = sprintf('read %s', var_export($result, true));

        return $result;
    }

    if (StreamShim::$read === false) {
        StreamShim::$log[] = 'read false';

        return false;
    }

    // настоящий fread отдаёт не больше запрошенного: длинный ответ режется по $length
    $result = substr(StreamShim::$read, 0, $length);
    StreamShim::$log[] = sprintf('read %s', var_export($result, true));

    return $result;
}

/**
 * Тень `fclose()`.
 *
 * @param resource $stream
 */
function fclose($stream): bool
{
    StreamShim::$log[] = 'close';

    return \fclose($stream);
}

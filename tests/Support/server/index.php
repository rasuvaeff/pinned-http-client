<?php

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $query);

if ($path === '/redirect') {
    header('Location: /target', replace: true, response_code: 302);

    return;
}

if (preg_match('#^/chain/(\d+)$#', $path, $hop) === 1) {
    $left = (int) $hop[1];

    if ($left > 0) {
        header('Location: /chain/' . ($left - 1), replace: true, response_code: 302);

        return;
    }
}

if ($path === '/to') {
    header('Location: ' . ($query['url'] ?? '/'), replace: true, response_code: (int) ($query['status'] ?? 302));

    return;
}

if ($path === '/big') {
    header('Content-Type: application/octet-stream');
    echo str_repeat('x', (int) ($query['n'] ?? 0));

    return;
}

if ($path === '/gzip') {
    header('Content-Encoding: gzip');
    echo gzencode(str_repeat('z', (int) ($query['n'] ?? 0)));

    return;
}

if ($path === '/slow') {
    usleep(1_500_000);
}

header('Content-Type: application/json');
echo json_encode([
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'path' => $path,
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'cookie' => $_SERVER['HTTP_COOKIE'] ?? null,
    'body' => file_get_contents('php://input'),
]);

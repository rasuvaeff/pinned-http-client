<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/redirect') {
    header('Location: /target', replace: true, response_code: 302);

    return;
}

header('Content-Type: text/plain');
echo 'host=' . ($_SERVER['HTTP_HOST'] ?? '') . ' path=' . $path;

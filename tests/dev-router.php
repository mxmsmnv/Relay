<?php

declare(strict_types=1);

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR) . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

require (string) ($_SERVER['DOCUMENT_ROOT'] ?? '.') . '/index.php';

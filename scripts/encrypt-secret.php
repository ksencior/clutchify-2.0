<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../helpers/secrets.php';

$plain = $argv[1] ?? null;

if ($plain === null) {
    $plain = stream_get_contents(STDIN);
}

$plain = rtrim((string)$plain, "\r\n");

if ($plain === '') {
    fwrite(STDERR, "Podaj sekret jako argument albo przez STDIN.\n");
    exit(1);
}

echo encryptSecret($plain) . PHP_EOL;
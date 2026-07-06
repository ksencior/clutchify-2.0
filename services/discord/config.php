<?php

require_once __DIR__ . '/../../config/env.php';

$client_id = env('DISCORD_CLIENT_ID');
$secret_id = env('DISCORD_CLIENT_SECRET');

$scopes = 'identify';

$redirect_url = env(
    'DISCORD_REDIRECT_URL',
    rtrim(env('APP_URL', 'http://clutchify.test'), '/') . '/services/connect_discord.php'
);

if (!$client_id || !$secret_id) {
    throw new RuntimeException('Brakuje DISCORD_CLIENT_ID lub DISCORD_CLIENT_SECRET w pliku .env.');
}
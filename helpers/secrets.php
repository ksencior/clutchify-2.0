<?php

function clutchifySecretKey(): string {
    $key = env('APP_SECRET_KEY', '');

    if ($key === '') {
        throw new RuntimeException('Brak APP_SECRET_KEY w .env.');
    }

    if (preg_match('/^[a-f0-9]{64}$/i', $key)) {
        $raw = hex2bin($key);

        if ($raw !== false && strlen($raw) === 32) {
            return $raw;
        }
    }

    return hash('sha256', $key, true);
}

function encryptSecret(string $plain): string {
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('Brak rozszerzenia OpenSSL w PHP.');
    }

    if ($plain === '') {
        throw new RuntimeException('Nie można zaszyfrować pustego sekretu.');
    }

    $key = clutchifySecretKey();
    $iv = random_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'clutchify-secret-v1',
        16
    );

    if ($ciphertext === false) {
        throw new RuntimeException('Nie udało się zaszyfrować sekretu.');
    }

    return 'v1:' . base64_encode($iv . $tag . $ciphertext);
}

function decryptSecret(string $encrypted): string {
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('Brak rozszerzenia OpenSSL w PHP.');
    }

    if (!str_starts_with($encrypted, 'v1:')) {
        throw new RuntimeException('Nieobsługiwany format zaszyfrowanego sekretu.');
    }

    $raw = base64_decode(substr($encrypted, 3), true);

    if ($raw === false || strlen($raw) <= 28) {
        throw new RuntimeException('Nieprawidłowy zaszyfrowany sekret.');
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);

    $plain = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        clutchifySecretKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'clutchify-secret-v1'
    );

    if ($plain === false) {
        throw new RuntimeException('Nie udało się odszyfrować sekretu. Sprawdź APP_SECRET_KEY.');
    }

    return $plain;
}

function gameServerRconPassword(array $server): string {
    $encrypted = trim((string)($server['rcon_password_encrypted'] ?? ''));

    if ($encrypted !== '') {
        return decryptSecret($encrypted);
    }

    $envKey = trim((string)($server['rcon_password_env'] ?? ''));

    if ($envKey !== '') {
        $password = env($envKey, '');

        if ($password !== '') {
            return $password;
        }
    }

    throw new RuntimeException('Brak hasła RCON dla serwera ' . ($server['name'] ?? '#' . ($server['id'] ?? '?')) . '.');
}
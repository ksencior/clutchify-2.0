<?php

require_once __DIR__ . '/../config/env.php';

function base64url_encode_string(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode_string(string $data): string|false {
    $padding = strlen($data) % 4;

    if ($padding > 0) {
        $data .= str_repeat('=', 4 - $padding);
    }

    return base64_decode(strtr($data, '-_', '+/'), true);
}

function ws_auth_secret(): string {
    $secret = env('WS_AUTH_SECRET');

    if (!$secret || strlen($secret) < 32) {
        throw new RuntimeException('Brakuje poprawnego WS_AUTH_SECRET w pliku .env.');
    }

    return $secret;
}

function create_ws_token(int $userId, string $username = '', int $ttlSeconds = 43200): string {
    $payload = [
        'uid' => $userId,
        'username' => $username,
        'exp' => time() + $ttlSeconds,
        'nonce' => bin2hex(random_bytes(8))
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $payloadBase64 = base64url_encode_string($payloadJson);

    $signature = hash_hmac('sha256', $payloadBase64, ws_auth_secret(), true);
    $signatureBase64 = base64url_encode_string($signature);

    return $payloadBase64 . '.' . $signatureBase64;
}

function verify_ws_token(string $token): ?array {
    $parts = explode('.', $token);

    if (count($parts) !== 2) {
        return null;
    }

    [$payloadBase64, $signatureBase64] = $parts;

    $expectedSignature = hash_hmac('sha256', $payloadBase64, ws_auth_secret(), true);
    $providedSignature = base64url_decode_string($signatureBase64);

    if ($providedSignature === false) {
        return null;
    }

    if (!hash_equals($expectedSignature, $providedSignature)) {
        return null;
    }

    $payloadJson = base64url_decode_string($payloadBase64);

    if ($payloadJson === false) {
        return null;
    }

    $payload = json_decode($payloadJson, true);

    if (!is_array($payload)) {
        return null;
    }

    if (empty($payload['uid']) || empty($payload['exp'])) {
        return null;
    }

    if ((int)$payload['exp'] < time()) {
        return null;
    }

    return $payload;
}
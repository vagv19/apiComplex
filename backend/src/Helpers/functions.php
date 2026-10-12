<?php

declare(strict_types=1);

function appEnv(string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? getenv($key) ?: null;
    if ($value === null || $value === '') {
        return $default;
    }

    if (strtolower((string) $value) === 'true') {
        return true;
    }
    if (strtolower((string) $value) === 'false') {
        return false;
    }

    return $value;
}

function config(string $key, mixed $default = null): mixed {
    return appEnv($key, $default);
}

function loadEnvFile(string $path): void {
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        $value = trim($value);
        $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }
}

function slugify(string $value): string {
    $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '-', strtolower(trim($value)));
    return trim((string) $normalized, '-');
}

function isJsonRequest(): bool {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    return str_contains(strtolower($contentType), 'application/json');
}

function nowIso(): string {
    return gmdate('Y-m-d H:i:s');
}

function signToken(array $payload): string {
    $secret = (string) config('JWT_SECRET', 'development-secret');
    $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $body = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    $signature = hash_hmac('sha256', $header . '.' . $body, $secret, true);
    return $header . '.' . $body . '.' . base64_encode($signature);
}

function verifyToken(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$headerB64, $payloadB64, $signatureB64] = $parts;
    $secret = (string) config('JWT_SECRET', 'development-secret');
    $expected = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);
    if (!hash_equals(base64_encode($expected), $signatureB64)) {
        return null;
    }

    try {
        $payload = json_decode(base64_decode($payloadB64, true) ?: '', true, 512, JSON_THROW_ON_ERROR);
        $issuedAt = (int) ($payload['iat'] ?? 0);
        $expiresAt = (int) ($payload['exp'] ?? 0);
        $issuer = (string) ($payload['iss'] ?? '');

        if ($issuedAt <= 0 || $expiresAt <= 0 || $issuer !== (string) config('JWT_ISSUER', 'football-tournament-api')) {
            return null;
        }

        if (time() > $expiresAt) {
            return null;
        }

        return $payload;
    } catch (Throwable) {
        return null;
    }
}

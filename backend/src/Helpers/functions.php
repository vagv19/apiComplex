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
    $secret = jwtSecret();
    $payload['ver'] ??= 0;
    $audience = config('JWT_AUDIENCE');
    if (is_string($audience) && $audience !== '') {
        $payload['aud'] = $audience;
    }
    $header = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $body = base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
    $signature = hash_hmac('sha256', $header . '.' . $body, $secret, true);
    return $header . '.' . $body . '.' . base64UrlEncode($signature);
}

function verifyToken(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$headerB64, $payloadB64, $signatureB64] = $parts;
    $headerJson = base64UrlDecode($headerB64);
    $payloadJson = base64UrlDecode($payloadB64);
    $signature = base64UrlDecode($signatureB64);
    if ($headerJson === false || $payloadJson === false || $signature === false) {
        return null;
    }
    try {
        $header = json_decode($headerJson, true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT' || !is_array($payload)) {
        return null;
    }

    $secret = jwtSecret();
    $expected = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    try {
        $issuedAt = (int) ($payload['iat'] ?? 0);
        $expiresAt = (int) ($payload['exp'] ?? 0);
        $issuer = config('JWT_ISSUER');
        $audience = config('JWT_AUDIENCE');

        if (
            $issuedAt <= 0
            || $issuedAt > time() + 60
            || $expiresAt <= time()
            || $expiresAt <= $issuedAt
            || (is_string($issuer) && $issuer !== '' && ($payload['iss'] ?? null) !== $issuer)
        ) {
            return null;
        }
        if (isset($payload['nbf']) && (!is_numeric($payload['nbf']) || (int) $payload['nbf'] > time())) {
            return null;
        }
        if (is_string($audience) && $audience !== '') {
            $tokenAudiences = $payload['aud'] ?? null;
            if (!is_array($tokenAudiences)) {
                $tokenAudiences = [$tokenAudiences];
            }
            if (!in_array($audience, $tokenAudiences, true)) {
                return null;
            }
        }
        return $payload;
    } catch (Throwable) {
        return null;
    }
}

function jwtSecret(): string {
    $secret = config('JWT_SECRET');
    if (!is_string($secret) || strlen($secret) < 32 || $secret === 'development-secret') {
        throw new RuntimeException('JWT_SECRET debe configurarse con al menos 32 caracteres.');
    }
    return $secret;
}

function base64UrlEncode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64UrlDecode(string $value): string|false {
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        return false;
    }
    $padding = (4 - (strlen($value) % 4)) % 4;
    return base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
}

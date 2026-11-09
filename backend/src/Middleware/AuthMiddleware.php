<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;

class AuthMiddleware
{
    public static function requireAuth(array $headers = []): array
    {
        $header = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'authorization' && is_string($value)) {
                $header = $value;
                break;
            }
        }
        if ($header === '') {
            $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        }
        if (!str_starts_with($header, 'Bearer ')) {
            throw new HttpException(401, 'No autenticado.');
        }

        $token = trim(substr($header, 7));
        $payload = verifyToken($token);
        if ($payload === null) {
            throw new HttpException(401, 'Token no válido o expirado.');
        }
        return $payload;
    }

    public static function requireAdmin(array $headers = []): array
    {
        return self::requireRole($headers, 'admin', 'Se requiere permiso de administrador.');
    }

    public static function requireRole(array $headers, string $role, ?string $message = null): array
    {
        $payload = self::requireAuth($headers);
        if (($payload['role'] ?? null) !== $role) {
            throw new HttpException(403, $message ?? 'No tienes permiso para realizar esta acción.');
        }
        return $payload;
    }
}

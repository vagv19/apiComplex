<?php

declare(strict_types=1);

namespace App\Middleware;

class AuthMiddleware
{
    public static function requireAuth(array $headers = []): array
    {
        return AuthorizationService::authenticate($headers);
    }

    public static function requireAdmin(array $headers = []): array
    {
        return AuthorizationService::requireCapability($headers, 'system.admin');
    }

    public static function requireCapability(array $headers, string $capability): array
    {
        return AuthorizationService::requireCapability($headers, $capability);
    }

    public static function requireRole(array $headers, string $role, ?string $message = null): array
    {
        return AuthorizationService::requireRole($headers, $role, $message);
    }
}

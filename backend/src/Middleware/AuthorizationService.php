<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Core\HttpException;

final class AuthorizationService
{
    public static function authenticate(array $headers): array
    {
        $authorization = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'authorization' && is_string($value)) {
                $authorization = $value;
                break;
            }
        }
        if ($authorization === '') {
            $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        }
        if (!str_starts_with($authorization, 'Bearer ')) {
            throw new HttpException(401, 'No autenticado.');
        }

        $payload = verifyToken(trim(substr($authorization, 7)));
        if ($payload === null) {
            throw new HttpException(401, 'Token no válido o expirado.');
        }
        $subject = $payload['sub'] ?? null;
        if (!is_string($subject) || !ctype_digit($subject) || (int) $subject < 1) {
            throw new HttpException(401, 'Token no válido o expirado.');
        }

        $query = Database::getConnection()->prepare(
            'SELECT id, role, is_active, auth_version, deleted_at, must_change_password
             FROM users WHERE id = :id LIMIT 1',
        );
        $query->execute([':id' => (int) $subject]);
        $user = $query->fetch();
        if ($user === false || !(bool) $user['is_active'] || $user['deleted_at'] !== null) {
            throw new HttpException(401, 'La cuenta no está activa.');
        }
        if (!RolePermissions::isKnownRole((string) $user['role'])) {
            throw new HttpException(403, 'La cuenta no tiene un rol válido.');
        }
        if (($payload['role'] ?? null) !== $user['role']) {
            throw new HttpException(403, 'El rol de la cuenta cambió; inicia sesión nuevamente.');
        }
        if ((int) ($payload['ver'] ?? -1) !== (int) $user['auth_version']) {
            throw new HttpException(401, 'La sesión fue invalidada; inicia sesión nuevamente.');
        }

        return [
            ...$payload,
            'sub' => (string) $user['id'],
            'role' => $user['role'],
            'must_change_password' => (bool) $user['must_change_password'],
        ];
    }

    public static function requireCapability(array $headers, string $capability): array
    {
        if ($capability === RolePermissions::PUBLIC) {
            throw new \LogicException('La capacidad pública no requiere autenticación.');
        }
        $user = self::authenticate($headers);
        if (!RolePermissions::hasCapability($user, $capability)) {
            throw new HttpException(403, 'No tienes permiso para realizar esta acción.');
        }
        return $user;
    }

    public static function hasCapability(array $user, string $capability): bool
    {
        return RolePermissions::hasCapability($user, $capability);
    }

    public static function requireRole(array $headers, string $role, ?string $message = null): array
    {
        $user = self::authenticate($headers);
        if (!RolePermissions::hasRole($user, $role)) {
            throw new HttpException(403, $message ?? 'No tienes permiso para realizar esta acción.');
        }
        return $user;
    }
}

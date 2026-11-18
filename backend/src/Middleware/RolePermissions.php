<?php

declare(strict_types=1);

namespace App\Middleware;

final class RolePermissions
{
    public const PUBLIC = 'public';

    private const ADMIN_CAPABILITIES = [
        'dashboard.read',
        'categories.read',
        'categories.manage',
        'teams.read',
        'teams.manage',
        'teams.self.read',
        'players.read',
        'players.manage',
        'players.registration.manage',
        'players.photo.manage',
        'players.self.read',
        'players.self.manage',
        'players.photo.self',
        'tournaments.read',
        'tournaments.manage',
        'tournaments.teams.read',
        'tournaments.teams.manage',
        'tournaments.players.read',
        'tournaments.registration.manage',
        'matches.read',
        'matches.manage',
        'matches.results.manage',
        'tournament_formats.manage',
        'discipline.manage',
        'system.admin',
        'users.read',
        'users.manage.directors',
        'users.password.reset.directors',
    ];

    private const SUPERADMIN_CAPABILITIES = [
        'players.registration.override_deadline',
        'users.manage',
        'roles.manage',
        'modules.manage',
        'users.password.reset',
        'system.audit.read',
    ];

    private const DIRECTOR_CAPABILITIES = [
        'teams.self.read',
        'players.self.read',
        'players.self.manage',
        'players.photo.self',
        'tournaments.read',
        'tournaments.teams.read',
        'tournaments.players.read',
        'users.password.self',
    ];

    private const SHARED_CAPABILITIES = [
        'users.password.self',
        'users.session.read',
    ];

    private const MODULES_BY_ROLE = [
        'superadmin' => [
            'dashboard', 'tournaments', 'categories', 'teams', 'players',
            'rosters', 'schedule', 'matchday', 'results', 'standings',
            'discipline', 'users', 'audit',
        ],
        'admin' => [
            'dashboard', 'tournaments', 'categories', 'teams', 'players',
            'rosters', 'schedule', 'matchday', 'results', 'standings',
            'discipline', 'users',
        ],
        'director_tecnico' => [
            'dashboard', 'tournaments', 'teams', 'players', 'rosters',
            'schedule', 'matchday', 'results', 'standings',
        ],
    ];

    public static function isKnownRole(string $role): bool
    {
        return in_array($role, ['director_tecnico', 'admin', 'superadmin'], true);
    }

    public static function hasRole(array $user, string $role): bool
    {
        return ($user['role'] ?? null) === $role;
    }

    public static function hasCapability(array $user, string $capability): bool
    {
        $role = $user['role'] ?? null;
        if (in_array($capability, self::SHARED_CAPABILITIES, true)) {
            return self::isKnownRole((string) $role);
        }
        if ($role === 'superadmin') {
            return in_array($capability, [...self::ADMIN_CAPABILITIES, ...self::SUPERADMIN_CAPABILITIES], true);
        }
        if ($role === 'admin') {
            return in_array($capability, self::ADMIN_CAPABILITIES, true);
        }
        if ($role === 'director_tecnico') {
            return in_array($capability, self::DIRECTOR_CAPABILITIES, true);
        }
        return false;
    }

    public static function capabilitiesForRole(string $role): array
    {
        return match ($role) {
            'superadmin' => array_values(array_unique([
                ...self::ADMIN_CAPABILITIES,
                ...self::SUPERADMIN_CAPABILITIES,
                ...self::SHARED_CAPABILITIES,
            ])),
            'admin' => array_values(array_unique([
                ...self::ADMIN_CAPABILITIES,
                ...self::SHARED_CAPABILITIES,
            ])),
            'director_tecnico' => array_values(array_unique([
                ...self::DIRECTOR_CAPABILITIES,
                ...self::SHARED_CAPABILITIES,
            ])),
            default => [],
        };
    }

    public static function modulesForRole(string $role): array
    {
        return self::MODULES_BY_ROLE[$role] ?? [];
    }

    public static function canUseModuleForRole(string $role, string $module): bool
    {
        return in_array($module, self::modulesForRole($role), true);
    }
}

<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;

final class ModuleAccessService
{
    public static function moduleForPath(string $path): ?string
    {
        $path = preg_replace('#^/api/v1#', '', $path) ?? $path;

        if ($path === '/auth/login' || $path === '/auth/me' || $path === '/users/change-password') {
            return null;
        }
        if ($path === '/dashboard') {
            return 'dashboard';
        }
        if (str_starts_with($path, '/users')) {
            return 'users';
        }
        if (str_starts_with($path, '/audit')) {
            return 'audit';
        }
        if (str_starts_with($path, '/categories')) {
            return 'categories';
        }
        if (str_starts_with($path, '/teams')) {
            return 'teams';
        }
        if (str_starts_with($path, '/players')) {
            return 'players';
        }
        if (str_starts_with($path, '/expulsion-reasons')) {
            return 'discipline';
        }
        if (preg_match('#^/tournaments/\d+/(players|my-team)(/|$)#', $path) === 1) {
            return 'rosters';
        }
        if (preg_match('#^/tournaments/\d+/(stages|schedule|rounds|groups|cup|qualification|league)(/|$)#', $path) === 1
            || str_starts_with($path, '/rounds/')) {
            return 'schedule';
        }
        if (preg_match('#^/tournaments/\d+/standings$#', $path) === 1
            || preg_match('#^/groups/\d+/standings$#', $path) === 1) {
            return 'standings';
        }
        if (preg_match('#^/matches/\d+/(result|report)$#', $path) === 1) {
            return 'results';
        }
        if ($path === '/matches/by-date') {
            return 'matchday';
        }
        if (str_starts_with($path, '/matches')) {
            return 'schedule';
        }
        if (str_starts_with($path, '/tournaments')) {
            return 'tournaments';
        }
        if (in_array($path, ['/roles', '/modules', '/permissions'], true)) {
            return 'users';
        }

        return null;
    }

    public static function canAccess(array $user, string $module): bool
    {
        $userId = (int) ($user['sub'] ?? $user['id'] ?? 0);
        $role = (string) ($user['role'] ?? '');
        if ($userId < 1 || !RolePermissions::canUseModuleForRole($role, $module)) {
            return false;
        }
        if ($role === 'superadmin') {
            return true;
        }

        $pdo = Database::getConnection();
        $configured = $pdo->prepare(
            'SELECT COUNT(*) FROM user_modules WHERE user_id = :user_id',
        );
        $configured->execute([':user_id' => $userId]);
        if ((int) $configured->fetchColumn() === 0) {
            return true;
        }

        $stmt = $pdo->prepare(
            'SELECT um.is_enabled
             FROM user_modules um
             JOIN system_modules sm ON sm.id = um.module_id
             WHERE um.user_id = :user_id AND sm.code = :code AND sm.is_active = 1
             LIMIT 1',
        );
        $stmt->execute([':user_id' => $userId, ':code' => $module]);
        return (bool) $stmt->fetchColumn();
    }

    public static function canAccessResource(array $user, string $path): bool
    {
        if (($user['role'] ?? '') !== 'director_tecnico') {
            return true;
        }
        $userId = (int) ($user['sub'] ?? $user['id'] ?? 0);
        $pdo = Database::getConnection();

        if (preg_match('#^/api/v1/tournaments/(\d+)(?:/|$)#', $path, $match) === 1) {
            return self::directorHasTournament($pdo, $userId, (int) $match[1]);
        }
        if (preg_match('#^/api/v1/rounds/(\d+)(?:/|$)#', $path, $match) === 1) {
            $stmt = $pdo->prepare(
                'SELECT s.tournament_id
                 FROM rounds r JOIN tournament_stages s ON s.id = r.stage_id
                 WHERE r.id = :id LIMIT 1',
            );
            $stmt->execute([':id' => (int) $match[1]]);
            $tournamentId = $stmt->fetchColumn();
            return $tournamentId !== false && self::directorHasTournament($pdo, $userId, (int) $tournamentId);
        }
        if (preg_match('#^/api/v1/matches/(\d+)(?:/|$)#', $path, $match) === 1) {
            $stmt = $pdo->prepare(
                'SELECT s.tournament_id
                 FROM matches m
                 JOIN rounds r ON r.id = m.round_id
                 JOIN tournament_stages s ON s.id = r.stage_id
                 WHERE m.id = :id LIMIT 1',
            );
            $stmt->execute([':id' => (int) $match[1]]);
            $tournamentId = $stmt->fetchColumn();
            return $tournamentId !== false && self::directorHasTournament($pdo, $userId, (int) $tournamentId);
        }
        if (preg_match('#^/api/v1/groups/(\d+)(?:/|$)#', $path, $match) === 1) {
            $stmt = $pdo->prepare(
                'SELECT s.tournament_id
                 FROM tournament_groups g JOIN tournament_stages s ON s.id = g.stage_id
                 WHERE g.id = :id LIMIT 1',
            );
            $stmt->execute([':id' => (int) $match[1]]);
            $tournamentId = $stmt->fetchColumn();
            return $tournamentId !== false && self::directorHasTournament($pdo, $userId, (int) $tournamentId);
        }
        return true;
    }

    private static function directorHasTournament(\PDO $pdo, int $userId, int $tournamentId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT c.id
             FROM tournament_team_coaches c
             JOIN tournament_teams tt ON tt.id = c.tournament_team_id
             JOIN users u ON u.id = c.user_id
             WHERE c.user_id = :user_id AND tt.tournament_id = :tournament_id
               AND c.is_active = 1 AND tt.status = 'ACTIVE'
               AND u.role = 'director_tecnico' AND u.is_active = 1
               AND u.deleted_at IS NULL
             LIMIT 1",
        );
        $stmt->execute([':user_id' => $userId, ':tournament_id' => $tournamentId]);
        return $stmt->fetchColumn() !== false;
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

final class UserAccessRepository
{
    private const USER_COLUMNS = 'id, name, email, role, is_active, deleted_at, must_change_password, created_at, updated_at';

    public function connection(): PDO
    {
        return Database::getConnection();
    }

    public function listUsers(
        array $filters,
        array $visibleRoles = [],
        ?int $includeUserId = null,
    ): array
    {
        $pdo = $this->connection();
        $where = [];
        $params = [];
        if ($visibleRoles !== []) {
            $rolePlaceholders = [];
            foreach (array_values($visibleRoles) as $index => $role) {
                $placeholder = ':visible_role_' . $index;
                $rolePlaceholders[] = $placeholder;
                $params[$placeholder] = $role;
            }
            $roleCondition = 'role IN (' . implode(', ', $rolePlaceholders) . ')';
            $where[] = $includeUserId !== null
                ? '(' . $roleCondition . ' OR id = :visible_user_id)'
                : $roleCondition;
        }
        if ($includeUserId !== null) {
            $params[':visible_user_id'] = $includeUserId;
        }
        if (isset($filters['role']) && $filters['role'] !== '') {
            $where[] = 'role = :role';
            $params[':role'] = $filters['role'];
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = 'is_active = :is_active';
            $params[':is_active'] = (int) $filters['is_active'];
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $where[] = '(name LIKE :name_search OR email LIKE :email_search)';
            $params[':name_search'] = '%' . $filters['search'] . '%';
            $params[':email_search'] = '%' . $filters['search'] . '%';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $orderColumns = [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
            'role' => 'role',
            'is_active' => 'is_active',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
        $orderBy = $orderColumns[$filters['sort_by'] ?? 'created_at'] ?? 'created_at';
        $direction = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));

        $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM users' . $whereSql);
        $totalStmt->execute($params);
        $summaryStmt = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN is_active = 1 AND deleted_at IS NULL THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN is_active = 0 OR deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS inactive,
                    SUM(CASE WHEN role = \'admin\' AND deleted_at IS NULL THEN 1 ELSE 0 END) AS admins,
                    SUM(CASE WHEN role = \'director_tecnico\' AND deleted_at IS NULL THEN 1 ELSE 0 END) AS directors
             FROM users' . $whereSql,
        );
        $summaryStmt->execute($params);
        $stmt = $pdo->prepare(
            'SELECT ' . self::USER_COLUMNS . ' FROM users' . $whereSql
            . " ORDER BY {$orderBy} {$direction}, id DESC LIMIT :limit OFFSET :offset",
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $totalStmt->fetchColumn(),
            'summary' => $summaryStmt->fetch(),
        ];
    }

    public function findUser(int $id, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT ' . self::USER_COLUMNS . ' FROM users WHERE id = :id LIMIT 1';
        if ($forUpdate && $this->connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        return $user === false ? null : $user;
    }

    public function findByEmail(string $email, ?int $exceptId = null): ?array
    {
        $sql = 'SELECT id FROM users WHERE email = :email';
        $params = [':email' => $email];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $params[':except_id'] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function createUser(array $user): int
    {
        $stmt = $this->connection()->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active, auth_version, deleted_at, must_change_password)
             VALUES (:name, :email, :password_hash, :role, :is_active, 0, NULL, 0)',
        );
        $stmt->execute([
            ':name' => $user['name'],
            ':email' => $user['email'],
            ':password_hash' => $user['password_hash'],
            ':role' => $user['role'],
            ':is_active' => $user['is_active'] ? 1 : 0,
        ]);
        return (int) $this->connection()->lastInsertId();
    }

    public function updateProfile(int $id, string $name, string $email): void
    {
        $stmt = $this->connection()->prepare(
            'UPDATE users SET name = :name, email = :email, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $stmt->execute([':name' => $name, ':email' => $email, ':id' => $id]);
    }

    public function updateRole(int $id, string $role): void
    {
        $stmt = $this->connection()->prepare(
            'UPDATE users
             SET role = :role, auth_version = auth_version + 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
        );
        $stmt->execute([':role' => $role, ':id' => $id]);
    }

    public function setDeleted(int $id, bool $deleted): void
    {
        $stmt = $this->connection()->prepare(
            'UPDATE users
             SET is_active = :is_active,
                 deleted_at = ' . ($deleted ? 'CURRENT_TIMESTAMP' : 'NULL') . ',
                 auth_version = auth_version + 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
        );
        $stmt->execute([':is_active' => $deleted ? 0 : 1, ':id' => $id]);
    }

    public function setPassword(int $id, string $passwordHash, bool $mustChange): void
    {
        $stmt = $this->connection()->prepare(
            'UPDATE users
             SET password_hash = :password_hash,
                 must_change_password = :must_change_password,
                 auth_version = auth_version + 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
        );
        $stmt->execute([
            ':password_hash' => $passwordHash,
            ':must_change_password' => $mustChange ? 1 : 0,
            ':id' => $id,
        ]);
    }

    public function listModules(): array
    {
        return $this->connection()->query(
            'SELECT id, code, name, description, parent_id, route, icon, sort_order, is_active
             FROM system_modules WHERE is_active = 1 ORDER BY sort_order, id',
        )->fetchAll();
    }

    public function modulesForUser(int $userId): array
    {
        $stmt = $this->connection()->prepare(
            'SELECT sm.id, sm.code, sm.name, sm.description, sm.parent_id, sm.route, sm.icon,
                    sm.sort_order, COALESCE(um.is_enabled, 0) AS is_enabled
             FROM system_modules sm
             LEFT JOIN user_modules um ON um.module_id = sm.id AND um.user_id = :user_id
             WHERE sm.is_active = 1
             ORDER BY sm.sort_order, sm.id',
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function replaceModuleAssignments(int $userId, array $moduleIds): void
    {
        $enabledIds = array_fill_keys(array_map('intval', $moduleIds), true);
        $modules = $this->listModules();
        $driver = $this->connection()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO user_modules (user_id, module_id, is_enabled, updated_at)
               VALUES (:user_id, :module_id, :is_enabled, CURRENT_TIMESTAMP)
               ON CONFLICT(user_id, module_id) DO UPDATE SET
                   is_enabled = excluded.is_enabled, updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO user_modules (user_id, module_id, is_enabled, updated_at)
               VALUES (:user_id, :module_id, :is_enabled, CURRENT_TIMESTAMP)
               ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->connection()->prepare($sql);
        foreach ($modules as $module) {
            $id = (int) $module['id'];
            $stmt->execute([
                ':user_id' => $userId,
                ':module_id' => $id,
                ':is_enabled' => isset($enabledIds[$id]) ? 1 : 0,
            ]);
        }
    }

    public function teamsForUser(int $userId): array
    {
        $stmt = $this->connection()->prepare(
            'SELECT c.id AS assignment_id, c.tournament_team_id, tt.tournament_id,
                    t.name AS tournament_name, tt.team_id, tm.name AS team_name,
                    c.is_active, c.assigned_at
             FROM tournament_team_coaches c
             JOIN tournament_teams tt ON tt.id = c.tournament_team_id
             JOIN tournaments t ON t.id = tt.tournament_id
             JOIN teams tm ON tm.id = tt.team_id
             WHERE c.user_id = :user_id
             ORDER BY t.name, tm.name',
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function teamAssignment(int $userId, int $tournamentTeamId): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT id, is_active FROM tournament_team_coaches
             WHERE user_id = :user_id AND tournament_team_id = :tournament_team_id LIMIT 1',
        );
        $stmt->execute([':user_id' => $userId, ':tournament_team_id' => $tournamentTeamId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function isActiveTournamentTeam(int $tournamentId, int $tournamentTeamId): bool
    {
        $stmt = $this->connection()->prepare(
            "SELECT id FROM tournament_teams
             WHERE id = :id AND tournament_id = :tournament_id AND status = 'ACTIVE' LIMIT 1",
        );
        $stmt->execute([':id' => $tournamentTeamId, ':tournament_id' => $tournamentId]);
        return $stmt->fetchColumn() !== false;
    }

    public function assignmentConflict(int $tournamentId, int $userId, int $tournamentTeamId): bool
    {
        $stmt = $this->connection()->prepare(
            'SELECT c.id
             FROM tournament_team_coaches c
             JOIN tournament_teams tt ON tt.id = c.tournament_team_id
             WHERE tt.tournament_id = :tournament_id
               AND c.is_active = 1
               AND (tt.id = :tournament_team_id OR c.user_id = :user_id)
             LIMIT 1',
        );
        $stmt->execute([
            ':tournament_id' => $tournamentId,
            ':tournament_team_id' => $tournamentTeamId,
            ':user_id' => $userId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    public function activateAssignment(int $userId, int $tournamentTeamId, ?int $existingId): void
    {
        if ($existingId !== null) {
            $stmt = $this->connection()->prepare(
                'UPDATE tournament_team_coaches SET is_active = 1, assigned_at = CURRENT_TIMESTAMP WHERE id = :id',
            );
            $stmt->execute([':id' => $existingId]);
            return;
        }
        $stmt = $this->connection()->prepare(
            'INSERT INTO tournament_team_coaches (tournament_team_id, user_id, is_active, assigned_at)
             VALUES (:tournament_team_id, :user_id, 1, CURRENT_TIMESTAMP)',
        );
        $stmt->execute([':tournament_team_id' => $tournamentTeamId, ':user_id' => $userId]);
    }

    public function deactivateAssignment(int $userId, int $tournamentTeamId): bool
    {
        $stmt = $this->connection()->prepare(
            'UPDATE tournament_team_coaches SET is_active = 0
             WHERE user_id = :user_id AND tournament_team_id = :tournament_team_id AND is_active = 1',
        );
        $stmt->execute([':user_id' => $userId, ':tournament_team_id' => $tournamentTeamId]);
        return $stmt->rowCount() > 0;
    }

    public function auditForUser(int $userId, int $page, int $limit): array
    {
        $pdo = $this->connection();
        $where = " WHERE entity_type = 'user' AND entity_id = :entity_id";
        $params = [':entity_id' => (string) $userId];
        $count = $pdo->prepare('SELECT COUNT(*) FROM admin_audit_log' . $where);
        $count->execute($params);
        $stmt = $pdo->prepare(
            'SELECT id, actor_user_id, actor_role, action, entity_type, entity_id, tournament_id,
                    reason, old_values, new_values, created_at
             FROM admin_audit_log' . $where . '
             ORDER BY id DESC LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue(':entity_id', (string) $userId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            foreach (['old_values', 'new_values'] as $field) {
                if (is_string($item[$field])) {
                    $decoded = json_decode($item[$field], true);
                    $item[$field] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
                }
            }
        }
        unset($item);
        return [
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $count->fetchColumn(),
        ];
    }

    public function countActiveSuperadminsForUpdate(): int
    {
        $sql = "SELECT id FROM users WHERE role = 'superadmin' AND is_active = 1 AND deleted_at IS NULL";
        if ($this->connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        return count($this->connection()->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }
}

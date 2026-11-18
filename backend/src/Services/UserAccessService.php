<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Core\HttpException;
use App\Middleware\RolePermissions;
use App\Repositories\AdminAuditRepository;
use App\Repositories\UserAccessRepository;
use PDOException;
use Throwable;

final class UserAccessService
{
    public function __construct(
        private readonly UserAccessRepository $repository = new UserAccessRepository(),
        private readonly AdminAuditRepository $audit = new AdminAuditRepository(),
    ) {
    }

    public function listUsers(array $actor, array $filters): array
    {
        $isAdmin = ($actor['role'] ?? '') === 'admin';
        return $this->repository->listUsers(
            $filters,
            $isAdmin ? ['admin', 'director_tecnico'] : [],
            $isAdmin ? (int) ($actor['sub'] ?? $actor['id'] ?? 0) : null,
        );
    }

    public function getUser(array $actor, int $userId): array
    {
        $user = $this->requireManagedUser($actor, $userId);
        return $user;
    }

    public function create(array $actor, array $data): array
    {
        $role = (string) $data['role'];
        $this->assertCanCreateRole($actor, $role);
        $email = strtolower(trim((string) $data['email']));
        if ($this->repository->findByEmail($email) !== null) {
            throw new HttpException(409, 'El correo ya está registrado.');
        }

        $moduleCodes = $data['modules'] ?? RolePermissions::modulesForRole($role);
        $moduleIds = $this->validatedModuleIds($actor, $role, $moduleCodes);
        $assignments = $data['assignments'] ?? [];
        if ($role !== 'director_tecnico' && $assignments !== []) {
            throw new HttpException(422, 'Solo los directores técnicos pueden recibir equipos asignados.');
        }
        if ($assignments !== [] && !(bool) ($data['is_active'] ?? true)) {
            throw new HttpException(422, 'Activa la cuenta antes de asignar equipos.');
        }
        $pdo = $this->repository->connection();
        $pdo->beginTransaction();
        try {
            $userId = $this->repository->createUser([
                'name' => trim((string) $data['name']),
                'email' => $email,
                'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
                'role' => $role,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            $this->repository->replaceModuleAssignments($userId, $moduleIds);
            foreach ($assignments as $assignment) {
                $this->addTeamAssignment(
                    $pdo,
                    $actor,
                    $userId,
                    (int) $assignment['tournament_id'],
                    (int) $assignment['tournament_team_id'],
                );
            }
            $this->audit($pdo, $actor, 'user.created', $userId, null, [
                'name' => trim((string) $data['name']),
                'email' => $email,
                'role' => $role,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'modules' => $moduleCodes,
            ]);
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new HttpException(409, 'El correo o alguna relación ya existe.', [], $exception);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->repository->findUser($userId) ?? throw new HttpException(500, 'No se pudo consultar el usuario creado.');
    }

    public function update(array $actor, array $data): array
    {
        $userId = (int) $data['user_id'];
        $user = $this->requireManagedUser($actor, $userId);
        $name = trim((string) $data['name']);
        $email = strtolower(trim((string) $data['email']));
        if ($this->repository->findByEmail($email, $userId) !== null) {
            throw new HttpException(409, 'El correo ya está registrado por otro usuario.');
        }
        $moduleCodes = $data['modules'] ?? null;
        $moduleIds = $moduleCodes === null
            ? null
            : $this->validatedModuleIds($actor, (string) $user['role'], $moduleCodes);
        $previousModules = $moduleCodes === null ? null : $this->enabledModuleCodes($userId);
        $pdo = $this->repository->connection();
        $pdo->beginTransaction();
        try {
            $this->repository->updateProfile($userId, $name, $email);
            if ($moduleIds !== null) {
                $this->repository->replaceModuleAssignments($userId, $moduleIds);
            }
            $this->audit($pdo, $actor, 'user.updated', $userId, [
                'name' => $user['name'],
                'email' => $user['email'],
                'modules' => $previousModules,
            ], [
                'name' => $name,
                'email' => $email,
                'modules' => $moduleCodes,
            ]);
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new HttpException(409, 'El correo ya está registrado por otro usuario.', [], $exception);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return $this->repository->findUser($userId) ?? throw new HttpException(404, 'Usuario no encontrado.');
    }

    public function changeRole(array $actor, int $userId, string $role): array
    {
        if (($actor['role'] ?? '') !== 'superadmin') {
            throw new HttpException(403, 'Solo superadmin puede asignar roles.');
        }
        if (!RolePermissions::isKnownRole($role)) {
            throw new HttpException(422, 'El rol solicitado no es válido.');
        }
        $user = $this->repository->findUser($userId);
        if ($user === null) {
            throw new HttpException(404, 'Usuario no encontrado.');
        }
        if ($user['role'] === $role) {
            return $user;
        }

        $pdo = $this->repository->connection();
        $pdo->beginTransaction();
        try {
            $user = $this->repository->findUser($userId, true);
            if ($user === null) {
                throw new HttpException(404, 'Usuario no encontrado.');
            }
            if ($user['role'] === 'superadmin' && $role !== 'superadmin'
                && (bool) $user['is_active'] && $user['deleted_at'] === null
                && $this->repository->countActiveSuperadminsForUpdate() <= 1) {
                throw new HttpException(409, 'No se puede degradar al último superadmin activo.');
            }
            $currentModules = $this->enabledModuleCodes($userId);
            $allowedModules = array_fill_keys(RolePermissions::modulesForRole($role), true);
            $retainedModules = array_values(array_filter(
                $currentModules,
                static fn (string $module): bool => isset($allowedModules[$module]),
            ));
            $moduleIds = $this->validatedModuleIds($actor, $role, $retainedModules);
            $this->repository->updateRole($userId, $role);
            $this->repository->replaceModuleAssignments($userId, $moduleIds);
            $this->audit($pdo, $actor, 'user.role_changed', $userId, [
                'role' => $user['role'],
                'modules' => $currentModules,
            ], ['role' => $role, 'modules' => $retainedModules]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return $this->repository->findUser($userId) ?? throw new HttpException(404, 'Usuario no encontrado.');
    }

    public function softDelete(array $actor, int $userId, string $reason): array
    {
        $user = $this->requireManagedUser($actor, $userId);
        if ($user['deleted_at'] !== null) {
            throw new HttpException(409, 'El usuario ya está eliminado lógicamente.');
        }
        $pdo = $this->repository->connection();
        $pdo->beginTransaction();
        try {
            $user = $this->repository->findUser($userId, true);
            if ($user === null) {
                throw new HttpException(404, 'Usuario no encontrado.');
            }
            $this->protectLastSuperadmin($user);
            $this->repository->setDeleted($userId, true);
            $this->audit($pdo, $actor, 'user.soft_deleted', $userId, [
                'is_active' => (bool) $user['is_active'],
                'deleted_at' => $user['deleted_at'],
            ], [
                'is_active' => false,
                'deleted' => true,
            ], $reason);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return $this->repository->findUser($userId) ?? throw new HttpException(404, 'Usuario no encontrado.');
    }

    public function restore(array $actor, int $userId): array
    {
        $user = $this->requireManagedUser($actor, $userId);
        if ($user['deleted_at'] === null && (bool) $user['is_active']) {
            throw new HttpException(409, 'El usuario no está inactivo ni eliminado.');
        }
        $pdo = $this->repository->connection();
        $pdo->beginTransaction();
        try {
            $this->repository->setDeleted($userId, false);
            $this->audit($pdo, $actor, 'user.restored', $userId, [
                'is_active' => false,
                'deleted' => true,
            ], [
                'is_active' => true,
                'deleted' => false,
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return $this->repository->findUser($userId) ?? throw new HttpException(404, 'Usuario no encontrado.');
    }

    public function changePassword(array $actor, string $currentPassword, string $newPassword): array
    {
        $userId = (int) ($actor['sub'] ?? 0);
        $stmt = $this->repository->connection()->prepare(
            'SELECT id, email, role, password_hash FROM users WHERE id = :id AND is_active = 1 AND deleted_at IS NULL LIMIT 1',
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if ($user === false || !password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new HttpException(422, 'La contraseña actual es incorrecta.');
        }
        $pdo = $this->repository->connection();
        $pdo->beginTransaction();
        try {
            $this->repository->setPassword($userId, password_hash($newPassword, PASSWORD_DEFAULT), false);
            $this->audit($pdo, $actor, 'user.password_changed', $userId, null, [
                'password_changed' => true,
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $versionStmt = $pdo->prepare('SELECT auth_version FROM users WHERE id = :id');
        $versionStmt->execute([':id' => $userId]);
        $token = signToken([
            'sub' => (string) $userId,
            'email' => $user['email'],
            'role' => $user['role'],
            'ver' => (int) $versionStmt->fetchColumn(),
            'iat' => time(),
            'exp' => time() + (int) config('JWT_TTL', 3600),
            'iss' => (string) config('JWT_ISSUER', 'football-tournament-api'),
        ]);
        return ['token' => $token];
    }

    public function resetPassword(array $actor, int $userId): array
    {
        $user = $this->requireManagedUser($actor, $userId);
        if (($actor['role'] ?? '') === 'admin' && $user['role'] !== 'director_tecnico') {
            throw new HttpException(403, 'No puedes restablecer la contraseña de este usuario.');
        }
        if (!(bool) $user['is_active'] || $user['deleted_at'] !== null) {
            throw new HttpException(409, 'No se puede restablecer la contraseña de una cuenta inactiva.');
        }
        $temporaryPassword = bin2hex(random_bytes(16));
        $pdo = $this->repository->connection();
        $pdo->beginTransaction();
        try {
            $this->repository->setPassword(
                $userId,
                password_hash($temporaryPassword, PASSWORD_DEFAULT),
                true,
            );
            $this->audit($pdo, $actor, 'user.password_reset', $userId, null, [
                'password_reset' => true,
                'must_change_password' => true,
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return ['temporary_password' => $temporaryPassword, 'must_change_password' => true];
    }

    public function assignModules(array $actor, int $userId, array $moduleCodes): array
    {
        $user = $this->requireManagedUser($actor, $userId);
        $moduleIds = $this->validatedModuleIds($actor, (string) $user['role'], $moduleCodes);
        $before = $this->enabledModuleCodes($userId);
        $pdo = $this->repository->connection();
        $pdo->beginTransaction();
        try {
            $this->repository->replaceModuleAssignments($userId, $moduleIds);
            $this->audit($pdo, $actor, 'user.modules_assigned', $userId, ['modules' => $before], [
                'modules' => $moduleCodes,
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return $this->modulesForUser($userId);
    }

    public function assignTeams(array $actor, int $userId, array $assignments): array
    {
        $user = $this->requireManagedUser($actor, $userId);
        if ($user['role'] !== 'director_tecnico') {
            throw new HttpException(422, 'Solo se pueden asignar equipos a directores técnicos.');
        }
        if (!(bool) $user['is_active'] || $user['deleted_at'] !== null) {
            throw new HttpException(409, 'No se pueden asignar equipos a un usuario inactivo.');
        }
        $pdo = $this->repository->connection();
        $pdo->beginTransaction();
        try {
            foreach ($assignments as $assignment) {
                $tournamentId = (int) $assignment['tournament_id'];
                $tournamentTeamId = (int) $assignment['tournament_team_id'];
                $this->addTeamAssignment($pdo, $actor, $userId, $tournamentId, $tournamentTeamId);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return $this->repository->teamsForUser($userId);
    }

    public function teamsForUser(int $userId): array
    {
        return $this->repository->teamsForUser($userId);
    }

    public function removeTeam(array $actor, int $userId, int $tournamentTeamId, string $reason): array
    {
        $user = $this->requireManagedUser($actor, $userId);
        if ($user['role'] !== 'director_tecnico') {
            throw new HttpException(422, 'El usuario no es director técnico.');
        }
        $assignment = $this->repository->teamAssignment($userId, $tournamentTeamId);
        if ($assignment === null || !(bool) $assignment['is_active']) {
            throw new HttpException(404, 'La asignación activa no existe.');
        }
        $teams = $this->repository->teamsForUser($userId);
        $current = array_values(array_filter(
            $teams,
            static fn (array $team): bool => (int) $team['tournament_team_id'] === $tournamentTeamId,
        ))[0] ?? null;
        $pdo = $this->repository->connection();
        $pdo->beginTransaction();
        try {
            $this->repository->deactivateAssignment($userId, $tournamentTeamId);
            $this->audit($pdo, $actor, 'user.team_removed', $userId, [
                'tournament_id' => $current['tournament_id'] ?? null,
                'tournament_team_id' => $tournamentTeamId,
            ], null, $reason, isset($current['tournament_id']) ? (int) $current['tournament_id'] : null);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return $this->repository->teamsForUser($userId);
    }

    public function modulesForUser(int $userId): array
    {
        $user = $this->repository->findUser($userId);
        if ($user === null) {
            throw new HttpException(404, 'Usuario no encontrado.');
        }
        $modules = $this->repository->modulesForUser($userId);
        if ($user['role'] === 'superadmin') {
            foreach ($modules as &$module) {
                $module['is_enabled'] = 1;
            }
            unset($module);
            return $modules;
        }
        if ($this->hasConfiguredModules($userId)) {
            $allowed = array_fill_keys(RolePermissions::modulesForRole((string) $user['role']), true);
            foreach ($modules as &$module) {
                $module['is_enabled'] = (bool) $module['is_enabled']
                    && isset($allowed[$module['code']]) ? 1 : 0;
            }
            unset($module);
            return $modules;
        }
        $defaults = array_fill_keys(RolePermissions::modulesForRole((string) $user['role']), true);
        foreach ($modules as &$module) {
            $module['is_enabled'] = isset($defaults[$module['code']]) ? 1 : 0;
        }
        unset($module);
        return $modules;
    }

    public function sessionData(int $userId): array
    {
        $user = $this->repository->findUser($userId);
        if ($user === null) {
            throw new HttpException(404, 'Usuario no encontrado.');
        }
        $modules = array_values(array_map(
            static fn (array $module): string => (string) $module['code'],
            array_filter(
                $this->modulesForUser($userId),
                static fn (array $module): bool => (bool) $module['is_enabled'],
            ),
        ));
        if ($user['role'] === 'superadmin') {
            $modules = RolePermissions::modulesForRole('superadmin');
        }
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'is_active' => (bool) $user['is_active'],
            'must_change_password' => (bool) $user['must_change_password'],
            'modules' => $modules,
            'permissions' => RolePermissions::capabilitiesForRole((string) $user['role']),
        ];
    }

    public function roles(): array
    {
        return array_map(
            static fn (array $role): array => [
                ...$role,
                'modules' => RolePermissions::modulesForRole($role['code']),
                'permissions' => RolePermissions::capabilitiesForRole($role['code']),
            ],
            [
                ['code' => 'superadmin', 'name' => 'Superadministrador'],
                ['code' => 'admin', 'name' => 'Administrador'],
                ['code' => 'director_tecnico', 'name' => 'Director técnico'],
            ],
        );
    }

    public function modules(): array
    {
        return $this->repository->listModules();
    }

    public function permissions(): array
    {
        return [
            'superadmin' => RolePermissions::capabilitiesForRole('superadmin'),
            'admin' => RolePermissions::capabilitiesForRole('admin'),
            'director_tecnico' => RolePermissions::capabilitiesForRole('director_tecnico'),
        ];
    }

    public function auditForUser(array $actor, int $userId, int $page, int $limit): array
    {
        $this->requireManagedUser($actor, $userId);
        return $this->repository->auditForUser($userId, $page, $limit);
    }

    private function requireManagedUser(array $actor, int $userId): array
    {
        $user = $this->repository->findUser($userId);
        if ($user === null) {
            throw new HttpException(404, 'Usuario no encontrado.');
        }
        if (($actor['role'] ?? '') === 'admin' && $user['role'] !== 'director_tecnico') {
            throw new HttpException(403, 'Solo puedes administrar directores técnicos.');
        }
        return $user;
    }

    private function assertCanCreateRole(array $actor, string $role): void
    {
        if (!RolePermissions::isKnownRole($role)) {
            throw new HttpException(422, 'El rol solicitado no es válido.');
        }
        if (($actor['role'] ?? '') === 'admin' && $role === 'superadmin') {
            throw new HttpException(403, 'Los administradores no pueden crear superadministradores.');
        }
    }

    private function validatedModuleIds(array $actor, string $role, array $moduleCodes): array
    {
        if (($actor['role'] ?? '') === 'admin' && $role === 'superadmin') {
            throw new HttpException(403, 'No puedes administrar módulos para este rol.');
        }
        $allowed = array_fill_keys(RolePermissions::modulesForRole($role), true);
        $codes = array_values(array_unique(array_map('strval', $moduleCodes)));
        foreach ($codes as $code) {
            if (!isset($allowed[$code])) {
                throw new HttpException(422, "El módulo {$code} no está permitido para el rol {$role}.");
            }
        }
        if ($codes === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->repository->connection()->prepare(
            'SELECT id, code FROM system_modules WHERE is_active = 1 AND code IN (' . $placeholders . ')',
        );
        $stmt->execute($codes);
        $rows = $stmt->fetchAll();
        if (count($rows) !== count($codes)) {
            throw new HttpException(422, 'La lista contiene módulos inexistentes o inactivos.');
        }
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function enabledModuleCodes(int $userId): array
    {
        return array_values(array_map(
            static fn (array $module): string => (string) $module['code'],
            array_filter(
                $this->repository->modulesForUser($userId),
                static fn (array $module): bool => (bool) $module['is_enabled'],
            ),
        ));
    }

    private function hasConfiguredModules(int $userId): bool
    {
        $stmt = $this->repository->connection()->prepare(
            'SELECT COUNT(*) FROM user_modules WHERE user_id = :user_id',
        );
        $stmt->execute([':user_id' => $userId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function addTeamAssignment(
        \PDO $pdo,
        array $actor,
        int $userId,
        int $tournamentId,
        int $tournamentTeamId,
    ): void {
        if (!$this->repository->isActiveTournamentTeam($tournamentId, $tournamentTeamId)) {
            throw new HttpException(422, 'El equipo no pertenece al torneo o no está activo.');
        }
        $existing = $this->repository->teamAssignment($userId, $tournamentTeamId);
        if ($existing !== null && (bool) $existing['is_active']) {
            return;
        }
        if ($this->repository->assignmentConflict($tournamentId, $userId, $tournamentTeamId)) {
            throw new HttpException(409, 'El director o equipo ya tiene una asignación activa en este torneo.');
        }
        $this->repository->activateAssignment(
            $userId,
            $tournamentTeamId,
            $existing === null ? null : (int) $existing['id'],
        );
        $this->audit($pdo, $actor, 'user.team_assigned', $userId, null, [
            'tournament_id' => $tournamentId,
            'tournament_team_id' => $tournamentTeamId,
        ], null, $tournamentId);
    }

    private function protectLastSuperadmin(array $user): void
    {
        if ($user['role'] === 'superadmin' && (bool) $user['is_active']
            && $user['deleted_at'] === null
            && $this->repository->countActiveSuperadminsForUpdate() <= 1) {
            throw new HttpException(409, 'No se puede desactivar al último superadmin activo.');
        }
    }

    private function audit(
        \PDO $pdo,
        array $actor,
        string $action,
        int $userId,
        ?array $oldValues,
        ?array $newValues,
        ?string $reason = null,
        ?int $tournamentId = null,
    ): void {
        $this->audit->record($pdo, [
            'actor_user_id' => (int) ($actor['sub'] ?? 0),
            'actor_role' => (string) ($actor['role'] ?? ''),
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => (string) $userId,
            'tournament_id' => $tournamentId,
            'reason' => $reason,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => null,
        ]);
    }
}

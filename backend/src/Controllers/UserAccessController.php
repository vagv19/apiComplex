<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Middleware\AuthorizationService;
use App\Services\UserAccessService;

final class UserAccessController
{
    public function __construct(private readonly UserAccessService $service = new UserAccessService())
    {
    }

    public function list(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.read');
        $query = $request->query;
        $errors = [];
        if (isset($query['role']) && $query['role'] !== '' && !in_array($query['role'], ['superadmin', 'admin', 'director_tecnico'], true)) {
            $errors['role'][] = 'El rol solicitado no es válido.';
        }
        if (isset($query['is_active']) && $query['is_active'] !== '' && !in_array((string) $query['is_active'], ['0', '1'], true)) {
            $errors['is_active'][] = 'El estado debe ser 0 o 1.';
        }
        if (isset($query['sort_by']) && !in_array($query['sort_by'], ['id', 'name', 'email', 'role', 'is_active', 'created_at', 'updated_at'], true)) {
            $errors['sort_by'][] = 'El orden solicitado no está permitido.';
        }
        if (isset($query['sort_order']) && !in_array(strtolower((string) $query['sort_order']), ['asc', 'desc'], true)) {
            $errors['sort_order'][] = 'La dirección debe ser asc o desc.';
        }
        if (isset($query['search']) && (!is_string($query['search']) || strlen($query['search']) > 150)) {
            $errors['search'][] = 'La búsqueda no puede exceder 150 caracteres.';
        }
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        Response::json(200, true, $this->service->listUsers($actor, $query), 'Usuarios consultados.');
    }

    public function get(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.read');
        $id = $this->pathId($params);
        Response::json(200, true, $this->service->getUser($actor, $id), 'Usuario consultado.');
    }

    public function modules(Request $request, array $params): void
    {
        AuthorizationService::requireCapability($request->headers, 'users.read');
        Response::json(200, true, $this->service->modules(), 'Módulos consultados.');
    }

    public function userModules(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.read');
        $this->service->getUser($actor, $this->pathId($params));
        Response::json(200, true, $this->service->modulesForUser($this->pathId($params)), 'Módulos del usuario consultados.');
    }

    public function userTeams(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.read');
        $this->service->getUser($actor, $this->pathId($params));
        Response::json(200, true, $this->service->teamsForUser($this->pathId($params)), 'Equipos asignados consultados.');
    }

    public function userAudit(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.read');
        $page = $this->positiveInt($request->query['page'] ?? 1, 'page', 1);
        $limit = min(100, $this->positiveInt($request->query['limit'] ?? 20, 'limit', 20));
        Response::json(
            200,
            true,
            $this->service->auditForUser($actor, $this->pathId($params), $page, $limit),
            'Auditoría del usuario consultada.',
        );
    }

    public function roles(Request $request, array $params): void
    {
        AuthorizationService::requireCapability($request->headers, 'users.read');
        Response::json(200, true, $this->service->roles(), 'Roles consultados.');
    }

    public function permissions(Request $request, array $params): void
    {
        AuthorizationService::requireCapability($request->headers, 'users.read');
        Response::json(200, true, $this->service->permissions(), 'Permisos consultados.');
    }

    public function create(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.manage.directors');
        $errors = Validator::validate($request->body, [
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'confirm_password' => ['required', 'string'],
            'role' => ['required', 'string'],
        ]);
        $this->validateUserFields($request->body, $errors, true);
        if (($request->body['password'] ?? null) !== ($request->body['confirm_password'] ?? null)) {
            $errors['confirm_password'][] = 'La confirmación de contraseña no coincide.';
        }
        if (isset($request->body['password']) && is_string($request->body['password']) && strlen($request->body['password']) < 12) {
            $errors['password'][] = 'La contraseña debe tener al menos 12 caracteres.';
        }
        $this->validateModuleList($request->body, $errors);
        $this->validateTeamAssignments($request->body, $errors);
        if (isset($request->body['is_active']) && !in_array($request->body['is_active'], [true, false, 0, 1, '0', '1'], true)) {
            $errors['is_active'][] = 'El estado debe ser booleano.';
        }
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        Response::json(201, true, $this->service->create($actor, $request->body), 'Usuario creado.');
    }

    public function update(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.manage.directors');
        $errors = Validator::validate($request->body, [
            'user_id' => ['required', 'int'],
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);
        $this->validateUserFields($request->body, $errors, false);
        $this->validateModuleList($request->body, $errors);
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        Response::json(200, true, $this->service->update($actor, $request->body), 'Usuario actualizado.');
    }

    public function changeRole(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'roles.manage');
        $errors = Validator::validate($request->body, [
            'user_id' => ['required', 'int'],
            'role' => ['required', 'string'],
        ]);
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        Response::json(
            200,
            true,
            $this->service->changeRole($actor, (int) $request->body['user_id'], (string) $request->body['role']),
            'Rol actualizado.',
        );
    }

    public function softDelete(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.manage.directors');
        $errors = Validator::validate($request->body, [
            'user_id' => ['required', 'int'],
            'reason' => ['required', 'string'],
        ]);
        if (isset($request->body['reason']) && is_string($request->body['reason']) && strlen(trim($request->body['reason'])) < 5) {
            $errors['reason'][] = 'El motivo debe tener al menos 5 caracteres.';
        }
        if (isset($request->body['reason']) && is_string($request->body['reason']) && strlen($request->body['reason']) > 500) {
            $errors['reason'][] = 'El motivo no puede exceder 500 caracteres.';
        }
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        Response::json(
            200,
            true,
            $this->service->softDelete($actor, (int) $request->body['user_id'], (string) $request->body['reason']),
            'Usuario eliminado lógicamente.',
        );
    }

    public function restore(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.manage.directors');
        $errors = Validator::validate($request->body, ['user_id' => ['required', 'int']]);
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        Response::json(
            200,
            true,
            $this->service->restore($actor, (int) $request->body['user_id']),
            'Usuario reactivado.',
        );
    }

    public function changePassword(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.password.self');
        $errors = Validator::validate($request->body, [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string'],
            'confirm_password' => ['required', 'string'],
        ]);
        if (isset($request->body['new_password']) && is_string($request->body['new_password']) && strlen($request->body['new_password']) < 12) {
            $errors['new_password'][] = 'La contraseña debe tener al menos 12 caracteres.';
        }
        if (($request->body['new_password'] ?? null) !== ($request->body['confirm_password'] ?? null)) {
            $errors['confirm_password'][] = 'La confirmación de contraseña no coincide.';
        }
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        Response::json(
            200,
            true,
            $this->service->changePassword(
                $actor,
                (string) $request->body['current_password'],
                (string) $request->body['new_password'],
            ),
            'Contraseña actualizada.',
        );
    }

    public function resetPassword(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.password.reset.directors');
        $errors = Validator::validate($request->body, ['user_id' => ['required', 'int']]);
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        Response::json(
            200,
            true,
            $this->service->resetPassword($actor, (int) $request->body['user_id']),
            'Contraseña temporal generada. Se debe compartir por un canal seguro.',
        );
    }

    public function assignModules(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.manage.directors');
        $errors = Validator::validate($request->body, [
            'user_id' => ['required', 'int'],
        ]);
        $this->validateModuleList($request->body, $errors, true);
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        Response::json(
            200,
            true,
            $this->service->assignModules($actor, (int) $request->body['user_id'], $request->body['modules']),
            'Módulos actualizados.',
        );
    }

    public function assignTeams(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.manage.directors');
        $errors = Validator::validate($request->body, [
            'user_id' => ['required', 'int'],
        ]);
        $this->validateTeamAssignments($request->body, $errors, true);
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        Response::json(
            200,
            true,
            $this->service->assignTeams($actor, (int) $request->body['user_id'], $request->body['assignments']),
            'Equipos asignados.',
        );
    }

    public function removeTeam(Request $request, array $params): void
    {
        $actor = AuthorizationService::requireCapability($request->headers, 'users.manage.directors');
        $errors = Validator::validate($request->body, [
            'user_id' => ['required', 'int'],
            'tournament_team_id' => ['required', 'int'],
            'reason' => ['required', 'string'],
        ]);
        if (isset($request->body['reason']) && is_string($request->body['reason'])
            && (strlen(trim($request->body['reason'])) < 5 || strlen($request->body['reason']) > 500)) {
            $errors['reason'][] = 'El motivo debe tener entre 5 y 500 caracteres.';
        }
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        Response::json(
            200,
            true,
            $this->service->removeTeam(
                $actor,
                (int) $request->body['user_id'],
                (int) $request->body['tournament_team_id'],
                (string) $request->body['reason'],
            ),
            'Asignación retirada.',
        );
    }

    private function pathId(array $params): int
    {
        $value = $params['id'] ?? '';
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw new HttpException(422, 'El identificador de usuario no es válido.');
        }
        return (int) $value;
    }

    private function positiveInt(mixed $value, string $field, int $default): int
    {
        if ($value === null) {
            return $default;
        }
        if (!ctype_digit((string) $value) || (int) $value < 1) {
            throw new HttpException(422, 'Validación fallida.', [$field => ['Debe ser un entero positivo.']]);
        }
        return (int) $value;
    }

    private function validateUserFields(array $data, array &$errors, bool $creating): void
    {
        foreach (['name' => 120, 'email' => 150] as $field => $maxLength) {
            if (isset($data[$field]) && is_string($data[$field])
                && (trim($data[$field]) === '' || strlen(trim($data[$field])) > $maxLength)) {
                $errors[$field][] = "El campo debe tener entre 1 y {$maxLength} caracteres.";
            }
        }
        if (!$creating && isset($data['user_id']) && (int) $data['user_id'] < 1) {
            $errors['user_id'][] = 'El identificador debe ser positivo.';
        }
    }

    private function validateModuleList(array $data, array &$errors, bool $required = false): void
    {
        if (!array_key_exists('modules', $data)) {
            if ($required) {
                $errors['modules'][] = 'La lista de módulos es obligatoria.';
            }
            return;
        }
        if (!is_array($data['modules']) || count($data['modules']) > 30) {
            $errors['modules'][] = 'La lista de módulos debe ser un arreglo válido.';
            return;
        }
        foreach ($data['modules'] as $module) {
            if (!is_string($module) || preg_match('/^[a-z][a-z0-9_]{0,79}$/', $module) !== 1) {
                $errors['modules'][] = 'Cada módulo debe usar un código válido.';
                return;
            }
        }
    }

    private function validateTeamAssignments(array $data, array &$errors, bool $required = false): void
    {
        if (!array_key_exists('assignments', $data)) {
            if ($required) {
                $errors['assignments'][] = 'La lista de asignaciones es obligatoria.';
            }
            return;
        }
        if (!is_array($data['assignments']) || count($data['assignments']) > 100) {
            $errors['assignments'][] = 'La lista de asignaciones debe ser un arreglo válido.';
            return;
        }
        foreach ($data['assignments'] as $assignment) {
            if (!is_array($assignment)
                || !isset($assignment['tournament_id'], $assignment['tournament_team_id'])
                || !ctype_digit((string) $assignment['tournament_id'])
                || !ctype_digit((string) $assignment['tournament_team_id'])
                || (int) $assignment['tournament_id'] < 1
                || (int) $assignment['tournament_team_id'] < 1) {
                $errors['assignments'][] = 'Cada asignación debe incluir torneo y equipo inscritos válidos.';
                return;
            }
        }
    }
}

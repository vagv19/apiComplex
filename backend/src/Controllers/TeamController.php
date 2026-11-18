<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Middleware\AuthorizationService;
use App\Repositories\TeamRepository;
use App\Services\TeamLogoService;
use InvalidArgumentException;
use RuntimeException;

class TeamController
{
    public function __construct(private readonly TeamRepository $repository = new TeamRepository())
    {
    }

    public function list(Request $request, array $params): void
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($request->query['limit'] ?? 15)));
        $search = $request->query['search'] ?? null;
        Response::json(200, true, $this->repository->list($page, $limit, $search), 'Listado de equipos.');
    }

    public function create(Request $request, array $params): void
    {
        $errors = Validator::validate($request->body, ['name' => ['required', 'string']]);
        foreach (['name' => 150, 'short_name' => 30, 'logo_url' => 500, 'contact_name' => 150, 'contact_phone' => 30] as $field => $maxLength) {
            if (isset($request->body[$field]) && (!is_string($request->body[$field]) || strlen($request->body[$field]) > $maxLength)) {
                $errors[$field][] = "El campo no puede exceder {$maxLength} caracteres.";
            }
        }
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }

        $team = $this->repository->create($request->body);
        Response::json(201, true, $team, 'Equipo creado.');
    }

    public function get(Request $request, array $params): void
    {
        $team = $this->repository->findById((int) $params['id']);
        if ($team === null) {
            Response::json(404, false, null, 'Equipo no encontrado.');
            return;
        }

        Response::json(200, true, $team, 'Equipo consultado.');
    }

    public function uploadLogo(Request $request, array $params): void
    {
        $user = AuthMiddleware::requireAuth($request->headers);
        if (!AuthorizationService::hasCapability($user, 'teams.logo.self')) {
            throw new \App\Core\HttpException(403, 'Se requiere el rol de administrador o director técnico.');
        }

        $teamId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if ($teamId === false || $teamId < 1) {
            Response::json(422, false, null, 'El identificador del equipo no es válido.');
            return;
        }

        $team = $this->repository->findById($teamId);
        if ($team === null) {
            Response::json(404, false, null, 'Equipo no encontrado.');
            return;
        }

        if (!AuthorizationService::hasCapability($user, 'teams.manage')) {
            $directorUserId = filter_var($user['sub'] ?? null, FILTER_VALIDATE_INT);
            if (
                $directorUserId === false
                || $directorUserId < 1
                || !$this->repository->isAssignedToDirector($teamId, $directorUserId)
            ) {
                throw new \App\Core\HttpException(403, 'Solo puedes subir logotipos de tus equipos asignados.');
            }
        }

        try {
            $updatedTeam = (new TeamLogoService($this->repository))->upload($teamId, $_FILES['logo'] ?? []);
        } catch (InvalidArgumentException $exception) {
            Response::json(422, false, null, $exception->getMessage(), [
                'logo' => [$exception->getMessage()],
            ]);
            return;
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            Response::json(500, false, null, 'No fue posible guardar el logotipo del equipo.');
            return;
        }

        if ($updatedTeam === null) {
            Response::json(404, false, null, 'Equipo no encontrado.');
            return;
        }

        Response::json(200, true, $updatedTeam, 'Logotipo del equipo guardado.');
    }

    public function update(Request $request, array $params): void
    {
        $errors = Validator::validate($request->body, ['name' => ['required', 'string']]);
        foreach (['name' => 150, 'short_name' => 30, 'logo_url' => 500, 'contact_name' => 150, 'contact_phone' => 30] as $field => $maxLength) {
            if (isset($request->body[$field]) && (!is_string($request->body[$field]) || strlen($request->body[$field]) > $maxLength)) {
                $errors[$field][] = "El campo no puede exceder {$maxLength} caracteres.";
            }
        }
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }

        $team = $this->repository->update((int) $params['id'], $request->body);
        if ($team === null) {
            Response::json(404, false, null, 'Equipo no encontrado.');
            return;
        }

        Response::json(200, true, $team, 'Equipo actualizado.');
    }

    public function delete(Request $request, array $params): void
    {
        $deleted = $this->repository->softDelete((int) $params['id']);
        if (!$deleted) {
            Response::json(404, false, null, 'Equipo no encontrado.');
            return;
        }

        Response::json(200, true, null, 'Equipo desactivado.');
    }

    public function history(Request $request, array $params): void
    {
        $rows = $this->repository->fetchTournamentHistory((int) $params['id']);
        Response::json(200, true, $rows, 'Historial del equipo.');
    }

    public function roster(Request $request, array $params): void
    {
        $rows = $this->repository->fetchTournamentRoster((int) $params['id'], (int) ($request->query['tournament_id'] ?? 0));
        if ((int) ($request->query['tournament_id'] ?? 0) === 0) {
            Response::json(400, false, null, 'Se requiere tournament_id.');
            return;
        }
        Response::json(200, true, $rows, 'Plantilla del equipo.');
    }
}

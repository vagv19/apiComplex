<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\TournamentRepository;

class TournamentController
{
    public function __construct(private readonly TournamentRepository $repository = new TournamentRepository())
    {
    }

    public function list(Request $request, array $params): void
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($request->query['limit'] ?? 15)));
        Response::json(200, true, $this->repository->list($page, $limit), 'Listado de torneos.');
    }

    public function create(Request $request, array $params): void
    {
        $errors = Validator::validate($request->body, [
            'name' => ['required', 'string'],
            'category_id' => ['required', 'int'],
        ]);
        $body = $request->body;

        if (isset($body['name']) && is_string($body['name']) && strlen($body['name']) > 150) {
            $errors['name'][] = 'El nombre no puede exceder 150 caracteres.';
        }

        if (isset($body['category_id']) && (int) $body['category_id'] <= 0) {
            $errors['category_id'][] = 'La categoría debe ser un identificador válido.';
        }

        foreach ([
            'format' => ['LEAGUE', 'CUP', 'FLASH'],
            'status' => ['DRAFT', 'REGISTRATION', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'],
        ] as $field => $allowedValues) {
            if (isset($body[$field]) && (!is_string($body[$field]) || !in_array(strtoupper($body[$field]), $allowedValues, true))) {
                $errors[$field][] = 'El valor seleccionado no es válido.';
            }
        }

        foreach ([
            'points_for_win',
            'points_for_draw',
            'points_for_loss',
            'rounds_count',
            'qualified_teams',
            'player_registration_deadline_round',
        ] as $field) {
            if (isset($body[$field]) && ((!is_int($body[$field]) && !ctype_digit((string) $body[$field])) || (int) $body[$field] > 255)) {
                $errors[$field][] = 'El valor debe ser un entero entre 0 y 255.';
            }
        }
        if (isset($body['rounds_count']) && (int) $body['rounds_count'] < 1) {
            $errors['rounds_count'][] = 'El torneo debe tener al menos una jornada regular.';
        }

        foreach (['start_date', 'end_date'] as $field) {
            if (isset($body[$field]) && $body[$field] !== null) {
                $date = is_string($body[$field]) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $body[$field]) : false;
                if ($date === false || $date->format('Y-m-d') !== $body[$field]) {
                    $errors[$field][] = 'La fecha debe tener el formato YYYY-MM-DD.';
                }
            }
        }

        if (
            isset($body['start_date'], $body['end_date']) &&
            is_string($body['start_date']) &&
            is_string($body['end_date']) &&
            $body['start_date'] > $body['end_date']
        ) {
            $errors['end_date'][] = 'La fecha de cierre no puede ser anterior a la fecha de inicio.';
        }

        if (isset($body['allow_player_registration']) && !in_array($body['allow_player_registration'], [true, false, 0, 1, '0', '1'], true)) {
            $errors['allow_player_registration'][] = 'El valor debe ser booleano.';
        }

        if (isset($body['tie_breakers']) && !is_string($body['tie_breakers'])) {
            $errors['tie_breakers'][] = 'Las reglas de desempate deben ser texto.';
        }

        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }

        if (!$this->repository->categoryExists((int) $body['category_id'])) {
            Response::json(404, false, null, 'Categoría no encontrada.');
            return;
        }

        $tournament = $this->repository->create($body);
        Response::json(201, true, $tournament, 'Torneo creado.');
    }

    public function get(Request $request, array $params): void
    {
        $tournament = $this->repository->findById((int) $params['id']);
        if ($tournament === null) {
            Response::json(404, false, null, 'Torneo no encontrado.');
            return;
        }

        Response::json(200, true, $tournament, 'Torneo consultado.');
    }

    public function update(Request $request, array $params): void
    {
        $errors = Validator::validate($request->body, ['name' => ['required', 'string']]);
        if (isset($request->body['category_id']) && (!ctype_digit((string) $request->body['category_id']) || (int) $request->body['category_id'] <= 0)) {
            $errors['category_id'][] = 'La categoría debe ser un identificador válido.';
        }
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }

        if (isset($request->body['category_id']) && !$this->repository->categoryExists((int) $request->body['category_id'])) {
            Response::json(404, false, null, 'Categoría no encontrada.');
            return;
        }

        try {
            $tournament = $this->repository->update((int) $params['id'], $request->body);
        } catch (\DomainException $exception) {
            Response::json(409, false, null, $exception->getMessage());
            return;
        }
        if ($tournament === null) {
            Response::json(404, false, null, 'Torneo no encontrado.');
            return;
        }

        Response::json(200, true, $tournament, 'Torneo actualizado.');
    }

    public function status(Request $request, array $params): void
    {
        $status = strtoupper((string) ($request->body['status'] ?? ''));
        if (!in_array($status, ['DRAFT', 'REGISTRATION', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'], true)) {
            Response::json(422, false, null, 'El estado no es válido.');
            return;
        }

        $result = $this->repository->changeStatus((int) $params['id'], $status);
        if ($result === null) {
            Response::json(404, false, null, 'Torneo no encontrado.');
            return;
        }
        Response::json(200, true, $result, 'Estado actualizado.');
    }

    public function registerTeam(Request $request, array $params): void
    {
        $teamId = (int) ($request->body['team_id'] ?? 0);
        if ($teamId <= 0) {
            Response::json(422, false, null, 'team_id requerido.');
            return;
        }

        $tournamentId = (int) $params['id'];
        try {
            $created = $this->repository->addTeamToTournament($tournamentId, $teamId);
        } catch (\DomainException $exception) {
            $notFound = str_contains($exception->getMessage(), 'no encontrado');
            Response::json($notFound ? 404 : 409, false, null, $exception->getMessage());
            return;
        }
        $teams = $this->repository->getTeamsForTournament($tournamentId);
        Response::json($created ? 201 : 200, true, $teams, $created ? 'Equipo inscrito en el torneo.' : 'El equipo ya estaba inscrito.');
    }

    public function teams(Request $request, array $params): void
    {
        $rows = $this->repository->getTeamsForTournament((int) $params['id']);
        Response::json(200, true, $rows, 'Equipos inscritos.');
    }

    public function registerPlayer(Request $request, array $params): void
    {
        $teamId = (int) ($request->body['team_id'] ?? 0);
        $playerId = (int) ($request->body['player_id'] ?? 0);
        if ($teamId <= 0 || $playerId <= 0) {
            Response::json(422, false, null, 'team_id y player_id son obligatorios.');
            return;
        }

        try {
            $created = $this->repository->addPlayerToTournament((int) $params['id'], $teamId, $playerId);
        } catch (\DomainException $exception) {
            $notFound = str_contains($exception->getMessage(), 'no encontrado');
            Response::json($notFound ? 404 : 409, false, null, $exception->getMessage());
            return;
        }
        $players = $this->repository->getPlayersForTournament((int) $params['id']);
        Response::json($created ? 201 : 200, true, $players, $created ? 'Jugador registrado.' : 'El jugador ya estaba inscrito.');
    }

    public function players(Request $request, array $params): void
    {
        $rows = $this->repository->getPlayersForTournament((int) $params['id']);
        Response::json(200, true, $rows, 'Plantillas del torneo.');
    }

    public function togglePlayerRegistration(Request $request, array $params): void
    {
        $tournament = $this->repository->findById((int) $params['id']);
        if ($tournament === null) {
            Response::json(404, false, null, 'Torneo no encontrado.');
            return;
        }

        $value = $request->body['allow_player_registration'] ?? null;
        if ($value !== null && !in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            Response::json(422, false, null, 'El valor debe ser booleano.');
            return;
        }
        $enabled = $value === null ? !(bool) $tournament['allow_player_registration'] : filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $updated = $this->repository->update((int) $params['id'], ['name' => $tournament['name'], 'allow_player_registration' => $enabled]);
        Response::json(200, true, $updated, 'Configuración de altas actualizada.');
    }
}

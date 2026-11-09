<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
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
        $this->validatePlayerRegistrationWindow($body, $errors);

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
        $tournamentId = (int) $params['id'];
        $current = $this->repository->findById($tournamentId);
        if ($current === null) {
            Response::json(404, false, null, 'Torneo no encontrado.');
            return;
        }

        $errors = Validator::validate($request->body, ['name' => ['required', 'string']]);
        $this->validatePlayerRegistrationWindow($request->body, $errors, $current);
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
            $tournament = $this->repository->update($tournamentId, $request->body);
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

    public function assignDirector(Request $request, array $params): void
    {
        AuthMiddleware::requireAdmin($request->headers);
        $tournamentId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        $teamId = filter_var($params['team_id'] ?? null, FILTER_VALIDATE_INT);
        $directorUserId = filter_var($request->body['director_user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($tournamentId === false || $tournamentId < 1 || $teamId === false || $teamId < 1 || $directorUserId === false || $directorUserId < 1) {
            Response::json(422, false, null, 'Indica identificadores válidos para el torneo, equipo y director técnico.', [
                'director_user_id' => ['Debe ser un identificador entero positivo.'],
            ]);
            return;
        }

        try {
            $this->repository->assignDirectorToTeam($tournamentId, $teamId, $directorUserId);
        } catch (\DomainException $exception) {
            $notFound = str_contains($exception->getMessage(), 'no encontrado');
            Response::json($notFound ? 404 : 409, false, null, $exception->getMessage());
            return;
        }

        $team = $this->repository->getTeamForDirector($tournamentId, $directorUserId);
        Response::json(200, true, $team, 'Director técnico asignado al equipo del torneo.');
    }

    public function myTeam(Request $request, array $params): void
    {
        $user = $this->requireDirectorOrAdmin($request);
        $tournamentId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        $teamId = null;
        if ($user['role'] === 'admin') {
            $teamId = filter_var($request->query['team_id'] ?? null, FILTER_VALIDATE_INT);
            if ($teamId === false || $teamId < 1) {
                Response::json(422, false, null, 'Para consultar la plantilla como administrador, indica un team_id válido.');
                return;
            }
        } else {
            $directorUserId = filter_var($user['sub'] ?? null, FILTER_VALIDATE_INT);
            if ($directorUserId === false || $directorUserId < 1) {
                Response::json(422, false, null, 'El identificador del director técnico no es válido.');
                return;
            }
        }
        if ($tournamentId === false || $tournamentId < 1) {
            Response::json(422, false, null, 'El identificador del torneo no es válido.');
            return;
        }

        $team = $user['role'] === 'admin'
            ? $this->repository->getTeamForTournament($tournamentId, $teamId)
            : $this->repository->getTeamForDirector($tournamentId, $directorUserId);
        if ($team === null) {
            Response::json(404, false, null, 'No se encontró un equipo activo para este torneo y usuario.');
            return;
        }

        $players = $this->repository->getPlayersForTournamentTeam($tournamentId, (int) $team['tournament_team_id']);
        Response::json(200, true, ['team' => $team, 'players' => $players], 'Equipo y plantilla del director técnico.');
    }

    public function registerPlayerForDirector(Request $request, array $params): void
    {
        $user = $this->requireDirectorOrAdmin($request);
        $tournamentId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        $directorUserId = null;
        $teamId = null;
        if ($user['role'] === 'admin') {
            $teamId = filter_var($request->body['team_id'] ?? null, FILTER_VALIDATE_INT);
            if ($teamId === false || $teamId < 1) {
                Response::json(422, false, null, 'Para registrar al jugador como administrador, indica un team_id válido.');
                return;
            }
        } else {
            $directorUserId = filter_var($user['sub'] ?? null, FILTER_VALIDATE_INT);
            if ($directorUserId === false || $directorUserId < 1) {
                Response::json(422, false, null, 'El identificador del director técnico no es válido.');
                return;
            }
        }
        if ($tournamentId === false || $tournamentId < 1) {
            Response::json(422, false, null, 'El identificador del torneo no es válido.');
            return;
        }

        $body = $request->body;
        foreach (['first_name', 'last_name', 'birth_date', 'phone', 'email'] as $field) {
            if (isset($body[$field]) && is_string($body[$field])) {
                $body[$field] = trim($body[$field]);
                if ($body[$field] === '' && $field !== 'first_name' && $field !== 'last_name') {
                    $body[$field] = null;
                }
            }
        }
        $errors = Validator::validate($body, [
            'first_name' => ['required', 'string'],
            'last_name' => ['required', 'string'],
        ]);
        foreach (['first_name' => 100, 'last_name' => 150, 'phone' => 30, 'email' => 150] as $field => $maxLength) {
            if (isset($body[$field]) && (!is_string($body[$field]) || strlen($body[$field]) > $maxLength)) {
                $errors[$field][] = "El campo no puede exceder {$maxLength} caracteres.";
            }
        }
        if (isset($body['email']) && $body['email'] !== '' && !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'El correo es inválido.';
        }
        if (isset($body['birth_date']) && $body['birth_date'] !== '') {
            $date = is_string($body['birth_date']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $body['birth_date']) : false;
            if ($date === false || $date->format('Y-m-d') !== $body['birth_date']) {
                $errors['birth_date'][] = 'La fecha debe tener el formato YYYY-MM-DD.';
            }
        }

        $jerseyNumber = $body['jersey_number'] ?? null;
        if (
            $jerseyNumber !== null
            && ((!is_int($jerseyNumber) && !(is_string($jerseyNumber) && ctype_digit($jerseyNumber)))
                || (int) $jerseyNumber < 0
                || (int) $jerseyNumber > 65535)
        ) {
            $errors['jersey_number'][] = 'Indica un número entero entre 0 y 65535.';
        }
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }

        try {
            $playerId = $this->repository->createPlayerForTournamentTeam(
                $tournamentId,
                [
                    'first_name' => $body['first_name'],
                    'last_name' => $body['last_name'],
                    'birth_date' => $body['birth_date'] ?? null,
                    'phone' => $body['phone'] ?? null,
                    'email' => $body['email'] ?? null,
                ],
                $jerseyNumber === null ? null : (int) $jerseyNumber,
                $directorUserId,
                $teamId
            );
        } catch (\DomainException $exception) {
            $message = $exception->getMessage();
            $status = str_contains($message, 'Torneo no encontrado') ? 404 : 409;
            Response::json($status, false, null, $message);
            return;
        }

        $team = $user['role'] === 'admin'
            ? $this->repository->getTeamForTournament($tournamentId, $teamId)
            : $this->repository->getTeamForDirector($tournamentId, $directorUserId);
        if ($team === null) {
            Response::json(500, false, null, 'No fue posible consultar el equipo asignado después de registrar al jugador.');
            return;
        }
        $players = $this->repository->getPlayersForTournamentTeam($tournamentId, (int) $team['tournament_team_id']);
        Response::json(201, true, ['team' => $team, 'player_id' => $playerId, 'players' => $players], 'Jugador creado y registrado en el equipo del torneo.');
    }

    private function requireDirectorOrAdmin(Request $request): array
    {
        $user = AuthMiddleware::requireAuth($request->headers);
        if (!in_array($user['role'] ?? null, ['director_tecnico', 'admin'], true)) {
            throw new \App\Core\HttpException(403, 'Se requiere el rol de director técnico o administrador.');
        }
        return $user;
    }

    private function validatePlayerRegistrationWindow(array $body, array &$errors, ?array $current = null): void
    {
        $fields = ['player_registration_start_at', 'player_registration_end_at'];
        $effectiveValues = [];

        foreach ($fields as $field) {
            $value = array_key_exists($field, $body)
                ? $body[$field]
                : ($current[$field] ?? null);
            $effectiveValues[$field] = $value;

            if (!array_key_exists($field, $body) || $value === null) {
                continue;
            }

            $date = is_string($value)
                ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value)
                : false;
            if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
                $errors[$field][] = 'La fecha y hora debe tener el formato YYYY-MM-DD HH:MM:SS.';
            }
        }

        $start = $effectiveValues['player_registration_start_at'];
        $end = $effectiveValues['player_registration_end_at'];
        if (
            is_string($start)
            && is_string($end)
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $end) === 1
            && $start > $end
        ) {
            $errors['player_registration_end_at'][] = 'El cierre del periodo no puede ser anterior a su apertura.';
        }
    }

    public function registerPlayer(Request $request, array $params): void
    {
        $teamId = (int) ($request->body['team_id'] ?? 0);
        $playerId = (int) ($request->body['player_id'] ?? 0);
        $jerseyNumber = $request->body['jersey_number'] ?? null;
        if ($teamId <= 0 || $playerId <= 0) {
            Response::json(422, false, null, 'team_id y player_id son obligatorios.');
            return;
        }
        if (
            $jerseyNumber !== null
            && ((!is_int($jerseyNumber) && !(is_string($jerseyNumber) && ctype_digit($jerseyNumber)))
                || (int) $jerseyNumber < 0
                || (int) $jerseyNumber > 65535)
        ) {
            Response::json(422, false, null, 'jersey_number debe ser un entero entre 0 y 65535.', [
                'jersey_number' => ['Indica un número de camiseta válido.'],
            ]);
            return;
        }

        try {
            $created = $this->repository->addPlayerToTournament(
                (int) $params['id'],
                $teamId,
                $playerId,
                $jerseyNumber === null ? null : (int) $jerseyNumber
            );
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

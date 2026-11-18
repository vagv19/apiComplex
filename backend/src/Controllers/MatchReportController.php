<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\MatchReportService;
use PDOException;

final class MatchReportController
{
    public function __construct(private readonly MatchReportService $service = new MatchReportService())
    {
    }

    public function getReport(Request $request, array $params): void
    {
        AuthMiddleware::requireAdmin($request->headers);
        try {
            Response::json(200, true, $this->service->getMatchReport((int) $params['id']), 'Cédula del partido consultada.');
        } catch (\DomainException $exception) {
            $this->domainError($exception);
        }
    }

    public function saveReport(Request $request, array $params): void
    {
        $actor = AuthMiddleware::requireAdmin($request->headers);
        $errors = $this->validateReport($request->body);
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }

        try {
            $saved = $this->service->saveMatchReport(
                (int) $params['id'],
                $request->body,
                isset($actor['sub']) && ctype_digit((string) $actor['sub']) ? (int) $actor['sub'] : null,
                (string) ($actor['role'] ?? ''),
                filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null,
            );
            Response::json(200, true, $saved, 'Marcador del partido guardado.');
        } catch (\DomainException $exception) {
            $this->domainError($exception);
        }
    }

    public function listReasons(Request $request, array $params): void
    {
        AuthMiddleware::requireAdmin($request->headers);
        Response::json(200, true, $this->service->listExpulsionReasons(), 'Motivos de expulsión.');
    }

    public function getReason(Request $request, array $params): void
    {
        AuthMiddleware::requireAdmin($request->headers);
        $reason = $this->service->findExpulsionReason((int) $params['id']);
        if ($reason === null) {
            Response::json(404, false, null, 'Motivo de expulsión no encontrado.');
            return;
        }
        Response::json(200, true, $reason, 'Motivo de expulsión consultado.');
    }

    public function createReason(Request $request, array $params): void
    {
        AuthMiddleware::requireAdmin($request->headers);
        $errors = $this->validateReason($request->body, true);
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        try {
            Response::json(201, true, $this->service->createExpulsionReason($this->reasonData($request->body)), 'Motivo de expulsión creado.');
        } catch (PDOException $exception) {
            if ($this->isConstraintViolation($exception)) {
                Response::json(409, false, null, 'Ya existe un motivo de expulsión con ese nombre.');
                return;
            }
            throw $exception;
        }
    }

    public function updateReason(Request $request, array $params): void
    {
        AuthMiddleware::requireAdmin($request->headers);
        $errors = $this->validateReason($request->body, false);
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        try {
            $reason = $this->service->updateExpulsionReason((int) $params['id'], $this->reasonData($request->body, false));
        } catch (PDOException $exception) {
            if ($this->isConstraintViolation($exception)) {
                Response::json(409, false, null, 'Ya existe un motivo de expulsión con ese nombre.');
                return;
            }
            throw $exception;
        }
        if ($reason === null) {
            Response::json(404, false, null, 'Motivo de expulsión no encontrado.');
            return;
        }
        Response::json(200, true, $reason, 'Motivo de expulsión actualizado.');
    }

    public function deleteReason(Request $request, array $params): void
    {
        AuthMiddleware::requireAdmin($request->headers);
        $result = $this->service->deleteOrDeactivateExpulsionReason((int) $params['id']);
        if ($result === null) {
            Response::json(404, false, null, 'Motivo de expulsión no encontrado.');
            return;
        }
        Response::json(200, true, ['action' => $result], $result === 'deactivated'
            ? 'Motivo utilizado desactivado para conservar el historial.'
            : 'Motivo de expulsión eliminado.');
    }

    public function playerSuspensions(Request $request, array $params): void
    {
        AuthMiddleware::requireAdmin($request->headers);
        try {
            Response::json(200, true, $this->service->suspensionsForPlayer((int) $params['id']), 'Historial de sanciones del jugador.');
        } catch (\DomainException $exception) {
            $this->domainError($exception);
        }
    }

    private function validateReport(array $body): array
    {
        $errors = [];
        $detailFields = ['attendance', 'goals', 'disciplinary_events'];
        $providedDetailFields = array_filter(
            $detailFields,
            static fn (string $field): bool => array_key_exists($field, $body),
        );
        if ($providedDetailFields !== [] && count($providedDetailFields) !== count($detailFields)) {
            foreach (array_diff($detailFields, $providedDetailFields) as $field) {
                $errors[$field][] = 'Envía todos los datos de jugadores, goles y disciplina, o ninguno.';
            }
        }
        foreach (['home_score', 'away_score'] as $field) {
            $score = filter_var($body[$field] ?? null, FILTER_VALIDATE_INT);
            if ($score === false || $score < 0 || $score > 65535) {
                $errors[$field][] = 'El marcador debe ser un entero entre 0 y 65535.';
            }
        }
        foreach (['home_penalties', 'away_penalties'] as $field) {
            if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
                continue;
            }
            $penalties = filter_var($body[$field], FILTER_VALIDATE_INT);
            if ($penalties === false || $penalties < 0 || $penalties > 65535) {
                $errors[$field][] = 'Los penales deben ser un entero entre 0 y 65535.';
            }
        }
        foreach ($detailFields as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            if (!isset($body[$field]) || !is_array($body[$field]) || !array_is_list($body[$field])) {
                $errors[$field][] = 'El campo debe ser una lista.';
                continue;
            }
            foreach ($body[$field] as $index => $item) {
                if (!is_array($item)) {
                    $errors[$field][$index][] = 'El elemento debe ser un objeto.';
                    continue;
                }
                $id = filter_var($item['tournament_player_id'] ?? null, FILTER_VALIDATE_INT);
                if ($id === false || $id < 1) {
                    $errors[$field][$index]['tournament_player_id'][] = 'Selecciona un jugador registrado válido.';
                }
                if ($field === 'attendance' && !in_array($item['attendance_status'] ?? null, ['PRESENT', 'ABSENT'], true)) {
                    $errors[$field][$index]['attendance_status'][] = 'Selecciona PRESENT o ABSENT.';
                }
                if ($field === 'goals') {
                    if (!in_array($item['goal_type'] ?? null, ['REGULAR', 'PENALTY', 'OWN_GOAL'], true)) {
                        $errors[$field][$index]['goal_type'][] = 'El tipo de gol no es válido.';
                    }
                    $teamId = filter_var($item['credited_to_team_id'] ?? null, FILTER_VALIDATE_INT);
                    if ($teamId === false || $teamId < 1) {
                        $errors[$field][$index]['credited_to_team_id'][] = 'Selecciona el equipo al que se acredita el gol.';
                    }
                    $this->validateClientKey($item, $field, $index, $errors);
                }
                if ($field === 'disciplinary_events') {
                    if (!in_array($item['event_type'] ?? null, ['YELLOW_CARD', 'SECOND_YELLOW_RED', 'DIRECT_RED'], true)) {
                        $errors[$field][$index]['event_type'][] = 'El tipo de evento no es válido.';
                    }
                    if (isset($item['expulsion_reason_id']) && $item['expulsion_reason_id'] !== null && $item['expulsion_reason_id'] !== '') {
                        $reasonId = filter_var($item['expulsion_reason_id'], FILTER_VALIDATE_INT);
                        if ($reasonId === false || $reasonId < 1) {
                            $errors[$field][$index]['expulsion_reason_id'][] = 'El motivo de expulsión debe ser válido.';
                        }
                    }
                    $this->validateClientKey($item, $field, $index, $errors);
                }
                $maxNotesLength = $field === 'goals' ? 300 : 500;
                if (isset($item['notes']) && (!is_string($item['notes']) || strlen($item['notes']) > $maxNotesLength)) {
                    $errors[$field][$index]['notes'][] = "Las observaciones no pueden exceder {$maxNotesLength} caracteres.";
                }
                if (array_key_exists('minute', $item)) {
                    $errors[$field][$index]['minute'][] = 'No se captura el minuto del evento.';
                }
            }
        }
        if (array_key_exists('minute', $body)) {
            $errors['minute'][] = 'No se captura el minuto del evento.';
        }
        if (isset($body['reason']) && (!is_string($body['reason']) || strlen($body['reason']) > 255)) {
            $errors['reason'][] = 'El motivo del cambio no puede exceder 255 caracteres.';
        }
        return $errors;
    }

    private function validateClientKey(array $item, string $field, int $index, array &$errors): void
    {
        $key = $item['client_key'] ?? null;
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{1,36}$/', $key)) {
            $errors[$field][$index]['client_key'][] = 'La clave debe identificar de forma estable esta fila.';
        }
    }

    private function validateReason(array $body, bool $creating): array
    {
        $errors = [];
        if ($creating || array_key_exists('name', $body)) {
            if (!is_string($body['name'] ?? null) || trim($body['name']) === '' || strlen(trim($body['name'])) > 150) {
                $errors['name'][] = 'El nombre es obligatorio y no puede exceder 150 caracteres.';
            }
        }
        if ($creating || array_key_exists('suspension_matches', $body)) {
            $count = filter_var($body['suspension_matches'] ?? null, FILTER_VALIDATE_INT);
            if ($count === false || $count < 0 || $count > 65535) {
                $errors['suspension_matches'][] = 'Debe ser un entero entre 0 y 65535.';
            }
        }
        if (array_key_exists('description', $body) && $body['description'] !== null && (!is_string($body['description']) || strlen($body['description']) > 500)) {
            $errors['description'][] = 'La descripción no puede exceder 500 caracteres.';
        }
        if (array_key_exists('is_active', $body) && !in_array($body['is_active'], [true, false, 0, 1, '0', '1'], true)) {
            $errors['is_active'][] = 'El estado debe ser booleano.';
        }
        if (!$creating && $body === []) {
            $errors['body'][] = 'Envía al menos un campo para actualizar.';
        }
        return $errors;
    }

    private function reasonData(array $body, bool $creating = true): array
    {
        if (!$creating) {
            $data = array_intersect_key($body, array_flip(['name', 'description', 'suspension_matches', 'is_active']));
            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
            }
            if (isset($data['suspension_matches'])) {
                $data['suspension_matches'] = (int) $data['suspension_matches'];
            }
            if (isset($data['is_active'])) {
                $data['is_active'] = in_array($data['is_active'], [true, 1, '1'], true);
            }
            return $data;
        }
        return [
            'name' => trim($body['name']),
            'description' => $body['description'] ?? null,
            'suspension_matches' => (int) $body['suspension_matches'],
            'is_active' => !in_array($body['is_active'] ?? true, [false, 0, '0'], true),
        ];
    }

    private function domainError(\DomainException $exception): void
    {
        $message = $exception->getMessage();
        $notFound = str_contains(strtolower($message), 'no encontrado');
        Response::json($notFound ? 404 : 409, false, null, $message);
    }

    private function isConstraintViolation(PDOException $exception): bool
    {
        return str_starts_with((string) $exception->getCode(), '23');
    }
}

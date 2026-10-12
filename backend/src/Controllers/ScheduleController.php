<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\ScheduleGeneratorService;
use PDO;

class ScheduleController
{
    public function generate(Request $request, array $params): void
    {
        try {
            $result = (new ScheduleGeneratorService())->generateLeagueSchedule((int) $params['id']);
            Response::json(201, true, $result, 'Calendario generado.');
        } catch (\DomainException $exception) {
            $notFound = str_contains($exception->getMessage(), 'no encontrado');
            Response::json($notFound ? 404 : 409, false, null, $exception->getMessage());
        }
    }

    public function stages(Request $request, array $params): void
    {
        $stmt = Database::getConnection()->prepare('SELECT id, tournament_id, name, stage_type, stage_type AS type, sequence_number, sequence_number AS stage_order, qualification_rules, status FROM tournament_stages WHERE tournament_id = :tournament_id ORDER BY sequence_number');
        $stmt->execute([':tournament_id' => (int) $params['id']]);
        Response::json(200, true, $stmt->fetchAll(), 'Fases del torneo.');
    }

    public function rounds(Request $request, array $params): void
    {
        $stmt = Database::getConnection()->prepare('SELECT r.id, s.tournament_id, r.stage_id, s.name AS stage_name, s.stage_type, s.sequence_number AS stage_sequence, r.group_id, g.name AS group_name, r.round_number, r.name, r.scheduled_date, r.status FROM rounds r JOIN tournament_stages s ON s.id = r.stage_id LEFT JOIN tournament_groups g ON g.id = r.group_id WHERE s.tournament_id = :tournament_id ORDER BY s.sequence_number, g.group_order, r.round_number');
        $stmt->execute([':tournament_id' => (int) $params['id']]);
        Response::json(200, true, $stmt->fetchAll(), 'Jornadas del torneo.');
    }

    public function matchesByDate(Request $request, array $params): void
    {
        AuthMiddleware::requireAdmin($request->headers);
        $date = $request->query['date'] ?? null;
        if (!$this->isValidDate($date)) {
            Response::json(422, false, null, 'La fecha debe tener el formato AAAA-MM-DD y ser válida.', [
                'date' => ['Indica una fecha válida con el formato AAAA-MM-DD.'],
            ]);
            return;
        }

        $stmt = Database::getConnection()->prepare(
            "SELECT m.id, m.match_date, m.kickoff_time, m.match_number, m.home_score, m.away_score, m.status,
                    m.venue, m.round_id, r.round_number, r.name AS round_name,
                    g.name AS group_name, s.name AS stage_name, s.stage_type, s.sequence_number AS stage_sequence,
                    t.id AS tournament_id, t.name AS tournament_name, c.id AS category_id, c.name AS category_name,
                    ht.team_id AS home_team_id, h.name AS home_team_name,
                    at.team_id AS away_team_id, a.name AS away_team_name
             FROM matches m
             JOIN rounds r ON r.id = m.round_id
             JOIN tournament_stages s ON s.id = r.stage_id
             JOIN tournaments t ON t.id = s.tournament_id
             JOIN categories c ON c.id = t.category_id
             JOIN tournament_teams ht ON ht.id = m.home_tournament_team_id
             JOIN teams h ON h.id = ht.team_id
             JOIN tournament_teams at ON at.id = m.away_tournament_team_id
             JOIN teams a ON a.id = at.team_id
             LEFT JOIN tournament_groups g ON g.id = r.group_id
             WHERE m.match_date = :match_date AND m.is_bye = 0 AND m.status <> :bye_status
             ORDER BY CASE WHEN m.kickoff_time IS NULL THEN 1 ELSE 0 END,
                      m.kickoff_time, c.name, t.name, s.sequence_number,
                      r.round_number, m.match_number, m.id"
        );
        $stmt->execute([
            ':match_date' => $date,
            ':bye_status' => 'BYE',
        ]);
        Response::json(200, true, $stmt->fetchAll(), 'Partidos programados para la fecha.');
    }

    public function scheduleMatch(Request $request, array $params): void
    {
        AuthMiddleware::requireAdmin($request->headers);
        $matchId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if ($matchId === false || $matchId < 1) {
            Response::json(422, false, null, 'El identificador del encuentro no es válido.');
            return;
        }

        $date = $request->body['match_date'] ?? null;
        $time = $request->body['kickoff_time'] ?? null;
        $errors = [];
        if (!$this->isValidDate($date)) {
            $errors['match_date'][] = 'Indica una fecha válida con el formato AAAA-MM-DD.';
        }
        if (!is_string($time) || !$this->isValidTime($time)) {
            $errors['kickoff_time'][] = 'Indica una hora válida con el formato HH:MM.';
        }
        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }
        if (strlen($time) === 5) {
            $time .= ':00';
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $lockClause = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $matchQuery = $pdo->prepare(
                'SELECT m.id, m.status, m.is_bye, m.home_score, m.away_score,
                        m.home_tournament_team_id, m.away_tournament_team_id
                 FROM matches m WHERE m.id = :id' . $lockClause
            );
            $matchQuery->execute([':id' => $matchId]);
            $match = $matchQuery->fetch();
            if ($match === false) {
                $pdo->rollBack();
                Response::json(404, false, null, 'Encuentro no encontrado.');
                return;
            }
            if (
                (bool) $match['is_bye']
                || $match['home_score'] !== null
                || $match['away_score'] !== null
                || in_array($match['status'], ['BYE', 'COMPLETED', 'CANCELLED', 'IN_PROGRESS'], true)
            ) {
                $pdo->rollBack();
                Response::json(409, false, null, 'No se puede programar un descanso, partido en curso, completado o cancelado.');
                return;
            }

            $scheduledMatches = $pdo->prepare(
                "SELECT id, kickoff_time FROM matches
                 WHERE id <> :match_id
                   AND match_date = :match_date
                   AND status NOT IN (:cancelled_status, :bye_status)
                   AND is_bye = 0
                   AND kickoff_time IS NOT NULL
                 ORDER BY kickoff_time, id" . $lockClause
            );
            $scheduledMatches->execute([
                ':match_id' => $matchId,
                ':match_date' => $date,
                ':cancelled_status' => 'CANCELLED',
                ':bye_status' => 'BYE',
            ]);
            $requestedMinute = $this->minutesSinceMidnight($time);
            foreach ($scheduledMatches->fetchAll() as $scheduledMatch) {
                $existingMinute = $this->minutesSinceMidnight((string) $scheduledMatch['kickoff_time']);
                if (abs($requestedMinute - $existingMinute) < 50) {
                    $pdo->rollBack();
                    Response::json(
                        409,
                        false,
                        null,
                        'Ese horario ya no está disponible. Deben transcurrir al menos 50 minutos entre el inicio de cada partido.',
                    );
                    return;
                }
            }

            $update = $pdo->prepare('UPDATE matches SET match_date = :match_date, kickoff_time = :kickoff_time WHERE id = :id');
            $update->execute([
                ':match_date' => $date,
                ':kickoff_time' => $time,
                ':id' => $matchId,
            ]);
            $pdo->commit();
            Response::json(200, true, [
                'id' => $matchId,
                'match_date' => $date,
                'kickoff_time' => $time,
            ], 'Encuentro programado.');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function isValidDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function isValidTime(string $value): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private function minutesSinceMidnight(string $value): int
    {
        return ((int) substr($value, 0, 2) * 60) + (int) substr($value, 3, 2);
    }

    public function createRound(Request $request, array $params): void
    {
        $tournamentId = (int) $params['id'];
        $roundNumber = filter_var($request->body['round_number'] ?? null, FILTER_VALIDATE_INT);
        if ($roundNumber === false || $roundNumber < 1 || $roundNumber > 65535) {
            Response::json(422, false, null, 'El número de jornada debe ser un entero positivo.');
            return;
        }

        $pdo = Database::getConnection();
        try {
            $stageId = $this->leagueStageId($pdo, $tournamentId);
            if ($stageId === null) {
                Response::json(404, false, null, 'Torneo no encontrado.');
                return;
            }
            $duplicate = $pdo->prepare('SELECT id FROM rounds WHERE stage_id = :stage_id AND group_id IS NULL AND round_number = :round_number');
            $duplicate->execute([':stage_id' => $stageId, ':round_number' => $roundNumber]);
            if ($duplicate->fetchColumn() !== false) {
                Response::json(409, false, null, 'Ya existe una jornada con ese número en esta fase.');
                return;
            }
            $stmt = $pdo->prepare('INSERT INTO rounds (stage_id, group_id, round_number, name, status) VALUES (:stage_id, NULL, :round_number, :name, :status)');
            $stmt->execute([
                ':stage_id' => $stageId,
                ':round_number' => $roundNumber,
                ':name' => $request->body['name'] ?? 'Jornada ' . $roundNumber,
                ':status' => 'PENDING',
            ]);
            Response::json(201, true, ['id' => (int) $pdo->lastInsertId()], 'Jornada creada.');
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                Response::json(409, false, null, 'Ya existe una jornada con ese número en esta fase.');
                return;
            }
            throw $exception;
        } catch (\DomainException $exception) {
            Response::json(409, false, null, $exception->getMessage());
        }
    }

    public function updateRound(Request $request, array $params): void
    {
        $status = strtoupper((string) ($request->body['status'] ?? 'PENDING'));
        if (!in_array($status, ['PENDING', 'IN_PROGRESS', 'COMPLETED'], true)) {
            Response::json(422, false, null, 'El estado de la jornada no es válido.');
            return;
        }
        $stmt = Database::getConnection()->prepare('UPDATE rounds SET name = :name, status = :status, scheduled_date = :scheduled_date WHERE id = :id');
        $stmt->execute([
            ':name' => $request->body['name'] ?? 'Jornada',
            ':status' => $status,
            ':scheduled_date' => $request->body['scheduled_date'] ?? null,
            ':id' => (int) $params['id'],
        ]);
        if ($stmt->rowCount() === 0) {
            $exists = Database::getConnection()->prepare('SELECT id FROM rounds WHERE id = :id');
            $exists->execute([':id' => (int) $params['id']]);
            if ($exists->fetchColumn() === false) {
                Response::json(404, false, null, 'Jornada no encontrada.');
                return;
            }
        }
        Response::json(200, true, ['id' => (int) $params['id']], 'Jornada actualizada.');
    }

    public function matchesByRound(Request $request, array $params): void
    {
        $stmt = Database::getConnection()->prepare('SELECT m.id, s.tournament_id, s.stage_type, r.group_id, g.name AS group_name, m.round_id, m.home_tournament_team_id, m.away_tournament_team_id, ht.team_id AS home_team_id, at.team_id AS away_team_id, h.name AS home_team_name, a.name AS away_team_name, m.match_number, m.match_date, m.kickoff_time, m.match_date AS scheduled_at, m.venue, m.home_score, m.away_score, m.status, m.is_bye, m.home_penalties, m.away_penalties, m.notes FROM matches m JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id LEFT JOIN tournament_groups g ON g.id = r.group_id LEFT JOIN tournament_teams ht ON ht.id = m.home_tournament_team_id LEFT JOIN tournament_teams at ON at.id = m.away_tournament_team_id LEFT JOIN teams h ON h.id = ht.team_id LEFT JOIN teams a ON a.id = at.team_id WHERE m.round_id = :round_id ORDER BY m.match_number, m.id');
        $stmt->execute([':round_id' => (int) $params['id']]);
        Response::json(200, true, $stmt->fetchAll(), 'Partidos de la jornada.');
    }

    public function createMatch(Request $request, array $params): void
    {
        $pdo = Database::getConnection();
        $roundId = (int) $params['id'];
        $context = $this->roundContext($pdo, $roundId);
        if ($context === null) {
            Response::json(404, false, null, 'Jornada no encontrada.');
            return;
        }

        $homeTeamId = isset($request->body['home_team_id']) ? (int) $request->body['home_team_id'] : null;
        $awayTeamId = isset($request->body['away_team_id']) ? (int) $request->body['away_team_id'] : null;
        if ($homeTeamId === null || $awayTeamId === null || $homeTeamId <= 0 || $awayTeamId <= 0 || $homeTeamId === $awayTeamId) {
            Response::json(422, false, null, 'Indica dos equipos distintos inscritos en el torneo.');
            return;
        }
        $teamIds = $this->registeredTeamIds($pdo, (int) $context['tournament_id'], [$homeTeamId, $awayTeamId]);
        if (count($teamIds) !== 2) {
            Response::json(422, false, null, 'Ambos equipos deben estar inscritos activamente en este torneo.');
            return;
        }
        if ($this->teamsAlreadyScheduled($pdo, $roundId, array_values($teamIds), null)) {
            Response::json(409, false, null, 'Un equipo ya tiene un partido en esta jornada.');
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO matches (round_id, home_tournament_team_id, away_tournament_team_id, match_date, kickoff_time, venue, status, is_bye, notes) VALUES (:round_id, :home_team_id, :away_team_id, :match_date, :kickoff_time, :venue, :status, 0, :notes)');
        $stmt->execute([
            ':round_id' => $roundId,
            ':home_team_id' => $teamIds[$homeTeamId],
            ':away_team_id' => $teamIds[$awayTeamId],
            ':match_date' => $request->body['match_date'] ?? $request->body['scheduled_at'] ?? null,
            ':kickoff_time' => $request->body['kickoff_time'] ?? null,
            ':venue' => $request->body['venue'] ?? null,
            ':status' => 'SCHEDULED',
            ':notes' => $request->body['notes'] ?? null,
        ]);
        Response::json(201, true, ['id' => (int) $pdo->lastInsertId()], 'Partido creado.');
    }

    public function updateMatch(Request $request, array $params): void
    {
        $pdo = Database::getConnection();
        $matchId = (int) $params['id'];
        $find = $pdo->prepare('SELECT m.*, s.tournament_id FROM matches m JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id WHERE m.id = :id');
        $find->execute([':id' => $matchId]);
        $match = $find->fetch();
        if ($match === false) {
            Response::json(404, false, null, 'Partido no encontrado.');
            return;
        }
        if ($match['status'] === 'COMPLETED' || (bool) $match['is_bye']) {
            Response::json(409, false, null, 'No se puede cambiar el emparejamiento de un partido completado o descanso.');
            return;
        }

        $homeRegistrationId = $match['home_tournament_team_id'];
        $awayRegistrationId = $match['away_tournament_team_id'];
        if (array_key_exists('home_team_id', $request->body) || array_key_exists('away_team_id', $request->body)) {
            $homeTeamId = (int) ($request->body['home_team_id'] ?? 0);
            $awayTeamId = (int) ($request->body['away_team_id'] ?? 0);
            if ($homeTeamId <= 0 || $awayTeamId <= 0 || $homeTeamId === $awayTeamId) {
                Response::json(422, false, null, 'Indica dos equipos distintos inscritos en el torneo.');
                return;
            }
            $ids = $this->registeredTeamIds($pdo, (int) $match['tournament_id'], [$homeTeamId, $awayTeamId]);
            if (count($ids) !== 2) {
                Response::json(422, false, null, 'Ambos equipos deben estar inscritos activamente en este torneo.');
                return;
            }
            if ($this->teamsAlreadyScheduled($pdo, (int) $match['round_id'], array_values($ids), $matchId)) {
                Response::json(409, false, null, 'Un equipo ya tiene un partido en esta jornada.');
                return;
            }
            $homeRegistrationId = $ids[$homeTeamId];
            $awayRegistrationId = $ids[$awayTeamId];
        }

        $status = strtoupper((string) ($request->body['status'] ?? $match['status']));
        if (!in_array($status, ['SCHEDULED', 'IN_PROGRESS', 'POSTPONED', 'CANCELLED'], true)) {
            Response::json(422, false, null, 'El estado del partido no es válido.');
            return;
        }
        $stmt = $pdo->prepare('UPDATE matches SET home_tournament_team_id = :home_team_id, away_tournament_team_id = :away_team_id, match_date = :match_date, kickoff_time = :kickoff_time, venue = :venue, status = :status, notes = :notes WHERE id = :id');
        $stmt->execute([
            ':home_team_id' => $homeRegistrationId,
            ':away_team_id' => $awayRegistrationId,
            ':match_date' => $request->body['match_date'] ?? $match['match_date'],
            ':kickoff_time' => $request->body['kickoff_time'] ?? $match['kickoff_time'],
            ':venue' => $request->body['venue'] ?? $match['venue'],
            ':status' => $status,
            ':notes' => $request->body['notes'] ?? $match['notes'],
            ':id' => $matchId,
        ]);
        Response::json(200, true, ['id' => $matchId], 'Partido actualizado.');
    }

    private function leagueStageId(PDO $pdo, int $tournamentId): ?int
    {
        $tournament = $pdo->prepare('SELECT id, tournament_type FROM tournaments WHERE id = :id');
        $tournament->execute([':id' => $tournamentId]);
        $tournamentRow = $tournament->fetch();
        if ($tournamentRow === false) {
            return null;
        }
        if ($tournamentRow['tournament_type'] !== 'LEAGUE') {
            throw new \DomainException('Las jornadas manuales solo están disponibles para torneos de liga.');
        }
        $stage = $pdo->prepare('SELECT id FROM tournament_stages WHERE tournament_id = :tournament_id AND stage_type = :stage_type ORDER BY sequence_number LIMIT 1');
        $stage->execute([':tournament_id' => $tournamentId, ':stage_type' => 'LEAGUE']);
        $stageId = $stage->fetchColumn();
        if ($stageId !== false) {
            return (int) $stageId;
        }
        $insert = $pdo->prepare('INSERT INTO tournament_stages (tournament_id, name, stage_type, sequence_number, status) VALUES (:tournament_id, :name, :stage_type, :sequence_number, :status)');
        $insert->execute([
            ':tournament_id' => $tournamentId,
            ':name' => 'Temporada regular',
            ':stage_type' => 'LEAGUE',
            ':sequence_number' => 1,
            ':status' => 'PENDING',
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function roundContext(PDO $pdo, int $roundId): ?array
    {
        $stmt = $pdo->prepare('SELECT s.tournament_id, r.stage_id FROM rounds r JOIN tournament_stages s ON s.id = r.stage_id WHERE r.id = :id');
        $stmt->execute([':id' => $roundId]);
        return $stmt->fetch() ?: null;
    }

    private function registeredTeamIds(PDO $pdo, int $tournamentId, array $teamIds): array
    {
        $placeholders = [];
        $values = [':tournament_id' => $tournamentId, ':status' => 'ACTIVE'];
        foreach (array_values($teamIds) as $index => $teamId) {
            $placeholder = ':team_' . $index;
            $placeholders[] = $placeholder;
            $values[$placeholder] = $teamId;
        }
        $stmt = $pdo->prepare('SELECT team_id, id FROM tournament_teams WHERE tournament_id = :tournament_id AND status = :status AND team_id IN (' . implode(', ', $placeholders) . ')');
        $stmt->execute($values);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['team_id']] = (int) $row['id'];
        }
        return $result;
    }

    private function teamsAlreadyScheduled(PDO $pdo, int $roundId, array $registrationIds, ?int $exceptMatchId): bool
    {
        $sql = 'SELECT id FROM matches WHERE round_id = :round_id AND (home_tournament_team_id IN (:home_one, :home_two) OR away_tournament_team_id IN (:away_one, :away_two))';
        $parameters = [
            ':round_id' => $roundId,
            ':home_one' => $registrationIds[0],
            ':home_two' => $registrationIds[1],
            ':away_one' => $registrationIds[0],
            ':away_two' => $registrationIds[1],
        ];
        if ($exceptMatchId !== null) {
            $sql .= ' AND id <> :except_id';
            $parameters[':except_id'] = $exceptMatchId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($parameters);
        return $stmt->fetchColumn() !== false;
    }
}

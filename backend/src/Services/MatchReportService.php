<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;

final class MatchReportService
{
    public function __construct(private readonly MatchResultService $results = new MatchResultService())
    {
    }

    public function getMatchReport(int $matchId): array
    {
        $pdo = Database::getConnection();
        $match = $this->matchContext($pdo, $matchId);
        $players = $this->eligiblePlayers($pdo, $match);

        $attendance = $pdo->prepare('SELECT tournament_player_id, attendance_status FROM match_player_attendance WHERE match_id = :match_id');
        $attendance->execute([':match_id' => $matchId]);

        $goals = $pdo->prepare('SELECT g.id, g.client_key, g.tournament_player_id, g.credited_to_team_id, g.goal_type, g.notes, p.first_name, p.last_name, t.name AS credited_team_name FROM match_goals g JOIN tournament_players tp ON tp.id = g.tournament_player_id JOIN players p ON p.id = tp.player_id JOIN tournament_teams credited_registration ON credited_registration.id = g.credited_to_team_id JOIN teams t ON t.id = credited_registration.team_id WHERE g.match_id = :match_id ORDER BY g.id');
        $goals->execute([':match_id' => $matchId]);

        $events = $pdo->prepare('SELECT d.id, d.client_key, d.tournament_player_id, d.event_type, d.expulsion_reason_id, d.notes, r.name AS expulsion_reason_name, s.id AS suspension_id, s.expulsion_reason_name AS suspension_reason_name, s.total_matches, s.served_matches, s.remaining_matches, s.status AS suspension_status, p.first_name, p.last_name FROM match_disciplinary_events d JOIN tournament_players tp ON tp.id = d.tournament_player_id JOIN players p ON p.id = tp.player_id LEFT JOIN expulsion_reasons r ON r.id = d.expulsion_reason_id LEFT JOIN player_suspensions s ON s.disciplinary_event_id = d.id WHERE d.match_id = :match_id ORDER BY d.id');
        $events->execute([':match_id' => $matchId]);

        $reasons = $pdo->query('SELECT id, name, suspension_matches FROM expulsion_reasons WHERE is_active = 1 ORDER BY name');

        return [
            'match' => [
                'id' => $matchId,
                'tournament_id' => (int) $match['tournament_id'],
                'status' => $match['status'],
                'home_team_id' => (int) $match['home_team_id'],
                'home_tournament_team_id' => (int) $match['home_tournament_team_id'],
                'home_team_name' => $match['home_team_name'],
                'away_team_id' => (int) $match['away_team_id'],
                'away_tournament_team_id' => (int) $match['away_tournament_team_id'],
                'away_team_name' => $match['away_team_name'],
                'home_score' => $match['home_score'] === null ? null : (int) $match['home_score'],
                'away_score' => $match['away_score'] === null ? null : (int) $match['away_score'],
                'home_penalties' => $match['home_penalties'] === null ? null : (int) $match['home_penalties'],
                'away_penalties' => $match['away_penalties'] === null ? null : (int) $match['away_penalties'],
            ],
            'players' => $players,
            'attendance' => $attendance->fetchAll(),
            'goals' => $goals->fetchAll(),
            'disciplinary_events' => $events->fetchAll(),
            'active_expulsion_reasons' => $reasons->fetchAll(),
        ];
    }

    public function saveMatchReport(int $matchId, array $report, ?int $changedBy): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $this->lockMatchTournament($pdo, $matchId);
            $match = $this->matchContext($pdo, $matchId, true);
            $players = $this->eligiblePlayers($pdo, $match);
            $roster = [];
            foreach ($players as $player) {
                $roster[(int) $player['tournament_player_id']] = $player;
            }

            $detailFields = ['attendance', 'goals', 'disciplinary_events'];
            $providedDetailFields = array_filter(
                $detailFields,
                static fn (string $field): bool => array_key_exists($field, $report),
            );
            $hasPlayerDetails = $providedDetailFields !== [];
            if ($hasPlayerDetails && count($providedDetailFields) !== count($detailFields)) {
                throw new \DomainException('Envía todos los datos de jugadores, goles y disciplina, o ninguno.');
            }
            if (!$hasPlayerDetails && $roster !== []) {
                throw new \DomainException('El partido tiene jugadores registrados; envía también la asistencia, los goles y la disciplina.');
            }
            if ($hasPlayerDetails) {
                foreach ($detailFields as $field) {
                    if (!is_array($report[$field]) || !array_is_list($report[$field])) {
                        throw new \DomainException("El campo {$field} debe ser una lista.");
                    }
                }

                $this->validateRosterItems($report['attendance'], $roster, 'attendance');
                $this->validateRosterItems($report['goals'], $roster, 'goals');
                $this->validateRosterItems($report['disciplinary_events'], $roster, 'disciplinary_events');
            }

            $result = $this->results->recordResultWithinTransaction(
                $pdo,
                $matchId,
                (int) $report['home_score'],
                (int) $report['away_score'],
                $changedBy,
                (string) ($report['reason'] ?? 'Resultado actualizado'),
                $report['home_penalties'] ?? null,
                $report['away_penalties'] ?? null,
            );

            if ($hasPlayerDetails) {
                $this->replaceAttendance($pdo, $matchId, $report['attendance']);
                $this->replaceGoals($pdo, $matchId, $match, $roster, $report['goals'], (int) $report['home_score'], (int) $report['away_score']);
                $this->syncDisciplinaryEvents($pdo, $matchId, $roster, $report['disciplinary_events']);
            }
            $saved = $this->getMatchReport($matchId);
            $pdo->commit();

            return ['result' => $result, 'report' => $saved];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function listExpulsionReasons(bool $includeInactive = true): array
    {
        $query = 'SELECT id, name, description, suspension_matches, is_active, created_at, updated_at FROM expulsion_reasons';
        if (!$includeInactive) {
            $query .= ' WHERE is_active = 1';
        }
        $query .= ' ORDER BY name';
        return Database::getConnection()->query($query)->fetchAll();
    }

    public function findExpulsionReason(int $reasonId): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT id, name, description, suspension_matches, is_active, created_at, updated_at FROM expulsion_reasons WHERE id = :id');
        $stmt->execute([':id' => $reasonId]);
        return $stmt->fetch() ?: null;
    }

    public function createExpulsionReason(array $data): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO expulsion_reasons (name, description, suspension_matches, is_active) VALUES (:name, :description, :suspension_matches, :is_active)');
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'],
            ':suspension_matches' => $data['suspension_matches'],
            ':is_active' => $data['is_active'] ? 1 : 0,
        ]);
        return $this->findExpulsionReason((int) $pdo->lastInsertId()) ?? throw new \RuntimeException('No se pudo consultar el motivo creado.');
    }

    public function updateExpulsionReason(int $reasonId, array $data): ?array
    {
        $current = $this->findExpulsionReason($reasonId);
        if ($current === null) {
            return null;
        }
        $data = array_merge($current, $data);
        $stmt = Database::getConnection()->prepare('UPDATE expulsion_reasons SET name = :name, description = :description, suspension_matches = :suspension_matches, is_active = :is_active WHERE id = :id');
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'],
            ':suspension_matches' => $data['suspension_matches'],
            ':is_active' => $data['is_active'] ? 1 : 0,
            ':id' => $reasonId,
        ]);
        return $this->findExpulsionReason($reasonId);
    }

    public function deleteOrDeactivateExpulsionReason(int $reasonId): ?string
    {
        $pdo = Database::getConnection();
        $reason = $this->findExpulsionReason($reasonId);
        if ($reason === null) {
            return null;
        }
        $used = $pdo->prepare('SELECT COUNT(*) FROM match_disciplinary_events WHERE expulsion_reason_id = :id');
        $used->execute([':id' => $reasonId]);
        if ((int) $used->fetchColumn() > 0) {
            $pdo->prepare('UPDATE expulsion_reasons SET is_active = 0 WHERE id = :id')->execute([':id' => $reasonId]);
            return 'deactivated';
        }
        $pdo->prepare('DELETE FROM expulsion_reasons WHERE id = :id')->execute([':id' => $reasonId]);
        return 'deleted';
    }

    public function suspensionsForPlayer(int $playerId): array
    {
        $pdo = Database::getConnection();
        $player = $pdo->prepare('SELECT id FROM players WHERE id = :id');
        $player->execute([':id' => $playerId]);
        if ($player->fetchColumn() === false) {
            throw new \DomainException('Jugador no encontrado.');
        }
        $stmt = $pdo->prepare('SELECT s.id, s.disciplinary_event_id, s.expulsion_reason_name, s.total_matches, s.served_matches, s.remaining_matches, s.status, s.created_at, d.match_id, d.event_type, m.match_date, t.id AS tournament_id, t.name AS tournament_name, tp.player_id FROM player_suspensions s JOIN match_disciplinary_events d ON d.id = s.disciplinary_event_id JOIN matches m ON m.id = d.match_id JOIN rounds r ON r.id = m.round_id JOIN tournament_stages st ON st.id = r.stage_id JOIN tournaments t ON t.id = st.tournament_id JOIN tournament_players tp ON tp.id = d.tournament_player_id WHERE tp.player_id = :player_id ORDER BY s.created_at DESC, s.id DESC');
        $stmt->execute([':player_id' => $playerId]);
        return $stmt->fetchAll();
    }

    private function matchContext(PDO $pdo, int $matchId, bool $lock = false): array
    {
        $lockClause = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT m.id, m.status, m.is_bye, m.home_score, m.away_score, m.home_penalties, m.away_penalties, m.home_tournament_team_id, m.away_tournament_team_id, ht.team_id AS home_team_id, at.team_id AS away_team_id, h.name AS home_team_name, a.name AS away_team_name, st.tournament_id FROM matches m JOIN rounds r ON r.id = m.round_id JOIN tournament_stages st ON st.id = r.stage_id LEFT JOIN tournament_teams ht ON ht.id = m.home_tournament_team_id LEFT JOIN tournament_teams at ON at.id = m.away_tournament_team_id LEFT JOIN teams h ON h.id = ht.team_id LEFT JOIN teams a ON a.id = at.team_id WHERE m.id = :match_id' . $lockClause);
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch();
        if ($match === false) {
            throw new \DomainException('Partido no encontrado.');
        }
        if ((bool) $match['is_bye'] || $match['status'] === 'BYE') {
            throw new \DomainException('No se pueden registrar detalles para un descanso.');
        }
        return $match;
    }

    private function eligiblePlayers(PDO $pdo, array $match): array
    {
        $stmt = $pdo->prepare('SELECT tp.id AS tournament_player_id, tp.tournament_id, tp.tournament_team_id, tp.status AS registration_status, tp.jersey_number, tt.team_id, t.name AS team_name, p.first_name, p.last_name FROM tournament_players tp JOIN tournament_teams tt ON tt.id = tp.tournament_team_id JOIN teams t ON t.id = tt.team_id JOIN players p ON p.id = tp.player_id WHERE tp.tournament_id = :tournament_id AND (tp.tournament_team_id = :home_registration OR tp.tournament_team_id = :away_registration) AND tp.status = :status ORDER BY t.name, tp.jersey_number IS NULL, tp.jersey_number, p.last_name, p.first_name');
        $stmt->execute([
            ':tournament_id' => $match['tournament_id'],
            ':home_registration' => $match['home_tournament_team_id'],
            ':away_registration' => $match['away_tournament_team_id'],
            ':status' => 'ACTIVE',
        ]);
        return $stmt->fetchAll();
    }

    private function validateRosterItems(array $items, array $roster, string $field): void
    {
        $seen = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new \DomainException("El elemento {$index} de {$field} no es válido.");
            }
            $registrationId = filter_var($item['tournament_player_id'] ?? null, FILTER_VALIDATE_INT);
            if ($registrationId === false || !isset($roster[$registrationId])) {
                throw new \DomainException("Un jugador de {$field} no pertenece a los equipos participantes activos del partido.");
            }
            if (isset($seen[$registrationId]) && $field === 'attendance') {
                throw new \DomainException('No se permite más de un registro de asistencia por jugador.');
            }
            $seen[$registrationId] = true;
        }
    }

    private function replaceAttendance(PDO $pdo, int $matchId, array $attendance): void
    {
        $unique = [];
        foreach ($attendance as $index => $item) {
            $status = $item['attendance_status'] ?? null;
            if (!in_array($status, ['PRESENT', 'ABSENT'], true)) {
                throw new \DomainException("El estado de asistencia en la fila {$index} debe ser PRESENT o ABSENT.");
            }
            $id = (int) $item['tournament_player_id'];
            if (isset($unique[$id])) {
                throw new \DomainException('No se permite más de un registro de asistencia por jugador.');
            }
            $unique[$id] = $status;
        }

        $pdo->prepare('DELETE FROM match_player_attendance WHERE match_id = :match_id')->execute([':match_id' => $matchId]);
        $insert = $pdo->prepare('INSERT INTO match_player_attendance (match_id, tournament_player_id, attendance_status) VALUES (:match_id, :tournament_player_id, :attendance_status)');
        foreach ($unique as $registrationId => $status) {
            $insert->execute([
                ':match_id' => $matchId,
                ':tournament_player_id' => $registrationId,
                ':attendance_status' => $status,
            ]);
        }
    }

    private function replaceGoals(PDO $pdo, int $matchId, array $match, array $roster, array $goals, int $homeScore, int $awayScore): void
    {
        $existing = $pdo->prepare('SELECT COUNT(*) FROM match_goals WHERE match_id = :match_id');
        $existing->execute([':match_id' => $matchId]);
        $hasDetails = (int) $existing->fetchColumn() > 0;
        $homeRegistrationId = (int) $match['home_tournament_team_id'];
        $awayRegistrationId = (int) $match['away_tournament_team_id'];
        $counts = [$homeRegistrationId => 0, $awayRegistrationId => 0];
        $keys = [];
        foreach ($goals as $index => $goal) {
            $registrationId = (int) $goal['tournament_player_id'];
            $playerTeamId = (int) $roster[$registrationId]['tournament_team_id'];
            $creditedTeamId = filter_var($goal['credited_to_team_id'] ?? null, FILTER_VALIDATE_INT);
            $type = $goal['goal_type'] ?? null;
            if (!in_array($type, ['REGULAR', 'PENALTY', 'OWN_GOAL'], true)) {
                throw new \DomainException("El tipo de gol en la fila {$index} no es válido.");
            }
            if ($creditedTeamId === false || !isset($counts[$creditedTeamId])) {
                throw new \DomainException('El gol debe acreditarse a uno de los equipos participantes.');
            }
            $expectedTeam = $type === 'OWN_GOAL'
                ? ($homeRegistrationId === $playerTeamId ? $awayRegistrationId : $homeRegistrationId)
                : $playerTeamId;
            if ((int) $creditedTeamId !== $expectedTeam) {
                throw new \DomainException($type === 'OWN_GOAL'
                    ? 'Un autogol debe acreditarse al equipo contrario al jugador.'
                    : 'El gol debe acreditarse al equipo del jugador.');
            }
            $key = $goal['client_key'] ?? null;
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{1,36}$/', $key) || isset($keys[$key])) {
                throw new \DomainException('Cada gol requiere una clave única de cliente válida.');
            }
            $keys[$key] = true;
            $counts[(int) $creditedTeamId]++;
        }
        if (($hasDetails || $goals !== []) && ($counts[$homeRegistrationId] !== $homeScore || $counts[$awayRegistrationId] !== $awayScore)) {
            throw new \DomainException('La cantidad de goles detallados debe coincidir con el marcador de ambos equipos.');
        }

        $pdo->prepare('DELETE FROM match_goals WHERE match_id = :match_id')->execute([':match_id' => $matchId]);
        $insert = $pdo->prepare('INSERT INTO match_goals (match_id, tournament_player_id, credited_to_team_id, goal_type, notes, client_key) VALUES (:match_id, :tournament_player_id, :credited_to_team_id, :goal_type, :notes, :client_key)');
        foreach ($goals as $goal) {
            $insert->execute([
                ':match_id' => $matchId,
                ':tournament_player_id' => (int) $goal['tournament_player_id'],
                ':credited_to_team_id' => (int) $goal['credited_to_team_id'],
                ':goal_type' => $goal['goal_type'],
                ':notes' => $goal['notes'] ?? null,
                ':client_key' => $goal['client_key'],
            ]);
        }
    }

    private function syncDisciplinaryEvents(PDO $pdo, int $matchId, array $roster, array $events): void
    {
        $existingQuery = $pdo->prepare('SELECT d.id, d.client_key, d.tournament_player_id, d.event_type, d.expulsion_reason_id, s.id AS suspension_id FROM match_disciplinary_events d LEFT JOIN player_suspensions s ON s.disciplinary_event_id = d.id WHERE d.match_id = :match_id');
        $existingQuery->execute([':match_id' => $matchId]);
        $existing = [];
        foreach ($existingQuery->fetchAll() as $event) {
            $existing[$event['client_key']] = $event;
        }

        $incoming = [];
        foreach ($events as $index => $event) {
            $key = $event['client_key'] ?? null;
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{1,36}$/', $key) || isset($incoming[$key])) {
                throw new \DomainException('Cada evento disciplinario requiere una clave única de cliente válida.');
            }
            $incoming[$key] = $event;
            $eventType = $event['event_type'] ?? null;
            if (!in_array($eventType, ['YELLOW_CARD', 'SECOND_YELLOW_RED', 'DIRECT_RED'], true)) {
                throw new \DomainException("El tipo de evento disciplinario en la fila {$index} no es válido.");
            }
            $reasonId = isset($event['expulsion_reason_id']) && $event['expulsion_reason_id'] !== ''
                ? filter_var($event['expulsion_reason_id'], FILTER_VALIDATE_INT)
                : null;
            if ($eventType === 'YELLOW_CARD' && $reasonId !== null) {
                throw new \DomainException('Una tarjeta amarilla no lleva motivo de expulsión.');
            }
            if ($eventType !== 'YELLOW_CARD' && ($reasonId === null || $reasonId === false || $reasonId < 1)) {
                throw new \DomainException('Las expulsiones requieren un motivo de expulsión válido.');
            }
            $previous = $existing[$key] ?? null;
            if ($eventType !== 'YELLOW_CARD') {
                $reason = $this->activeReason($pdo, (int) $reasonId);
                if ($reason === null && !($previous !== null && (int) $previous['expulsion_reason_id'] === (int) $reasonId)) {
                    throw new \DomainException('El motivo de expulsión no existe o está inactivo.');
                }
            }
            if ($previous !== null && $previous['suspension_id'] !== null) {
                if (
                    (int) $previous['tournament_player_id'] !== (int) $event['tournament_player_id']
                    || $previous['event_type'] !== $eventType
                    || (int) ($previous['expulsion_reason_id'] ?? 0) !== (int) ($reasonId ?? 0)
                ) {
                    throw new \DomainException('No se puede cambiar un evento que ya generó una sanción histórica.');
                }
            }
        }

        foreach ($existing as $key => $old) {
            if (!isset($incoming[$key])) {
                if ($old['suspension_id'] !== null) {
                    throw new \DomainException('No se puede eliminar un evento disciplinario asociado a una sanción.');
                }
                $pdo->prepare('DELETE FROM match_disciplinary_events WHERE id = :id')->execute([':id' => $old['id']]);
            }
        }

        foreach ($incoming as $key => $event) {
            $registrationId = (int) $event['tournament_player_id'];
            $eventType = $event['event_type'];
            $reasonId = $eventType === 'YELLOW_CARD' ? null : (int) $event['expulsion_reason_id'];
            $notes = $event['notes'] ?? null;
            if (isset($existing[$key])) {
                $eventId = (int) $existing[$key]['id'];
                $pdo->prepare('UPDATE match_disciplinary_events SET tournament_player_id = :player_id, event_type = :event_type, expulsion_reason_id = :reason_id, notes = :notes WHERE id = :id')
                    ->execute([
                        ':player_id' => $registrationId,
                        ':event_type' => $eventType,
                        ':reason_id' => $reasonId,
                        ':notes' => $notes,
                        ':id' => $eventId,
                    ]);
                if ($eventType !== 'YELLOW_CARD' && $existing[$key]['suspension_id'] === null) {
                    $reason = $this->activeReason($pdo, (int) $reasonId);
                    if ($reason === null) {
                        throw new \DomainException('El motivo de expulsión no existe o está inactivo.');
                    }
                    $this->createSuspensionSnapshot($pdo, $eventId, $reason);
                }
                continue;
            }

            $insert = $pdo->prepare('INSERT INTO match_disciplinary_events (match_id, tournament_player_id, event_type, expulsion_reason_id, notes, client_key) VALUES (:match_id, :player_id, :event_type, :reason_id, :notes, :client_key)');
            $insert->execute([
                ':match_id' => $matchId,
                ':player_id' => $registrationId,
                ':event_type' => $eventType,
                ':reason_id' => $reasonId,
                ':notes' => $notes,
                ':client_key' => $key,
            ]);
            if ($eventType !== 'YELLOW_CARD') {
                $reason = $this->activeReason($pdo, (int) $reasonId);
                if ($reason === null) {
                    throw new \DomainException('El motivo de expulsión no existe o está inactivo.');
                }
                $eventIdQuery = $pdo->prepare('SELECT id FROM match_disciplinary_events WHERE match_id = :match_id AND client_key = :client_key');
                $eventIdQuery->execute([
                    ':match_id' => $matchId,
                    ':client_key' => $key,
                ]);
                $eventId = $eventIdQuery->fetchColumn();
                if ($eventId === false) {
                    throw new \RuntimeException('No se pudo recuperar el evento disciplinario recién guardado.');
                }
                $this->createSuspensionSnapshot($pdo, (int) $eventId, $reason);
            }
        }
    }

    private function createSuspensionSnapshot(PDO $pdo, int $eventId, array $reason): void
    {
        $total = (int) $reason['suspension_matches'];
        $pdo->prepare('INSERT INTO player_suspensions (disciplinary_event_id, expulsion_reason_name, total_matches, served_matches, remaining_matches, status) VALUES (:event_id, :reason_name, :total, 0, :remaining, :status)')
            ->execute([
                ':event_id' => $eventId,
                ':reason_name' => $reason['name'],
                ':total' => $total,
                ':remaining' => $total,
                ':status' => $total === 0 ? 'COMPLETED' : 'ACTIVE',
            ]);
    }

    private function activeReason(PDO $pdo, int $reasonId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, name, suspension_matches FROM expulsion_reasons WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $reasonId]);
        return $stmt->fetch() ?: null;
    }

    private function lockMatchTournament(PDO $pdo, int $matchId): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $stmt = $pdo->prepare('SELECT t.id FROM matches m JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id JOIN tournaments t ON t.id = s.tournament_id WHERE m.id = :match_id FOR UPDATE');
        $stmt->execute([':match_id' => $matchId]);
    }
}

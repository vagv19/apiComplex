<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Repositories\AdminAuditRepository;
use App\Services\PlayerRegistrationPolicy;
use PDO;

class TournamentRepository
{
    private const SELECT_COLUMNS = 'id, category_id, name, tournament_type AS format, status, start_date, end_date, points_win AS points_for_win, points_draw AS points_for_draw, points_loss AS points_for_loss, regular_rounds AS rounds_count, qualification_count AS qualified_teams, rules AS tie_breakers, player_registration_deadline AS player_registration_deadline_round, player_registration_enabled AS allow_player_registration, player_registration_start_at, player_registration_end_at, CASE WHEN player_registration_start_at IS NOT NULL AND player_registration_end_at IS NOT NULL AND player_registration_start_at <= CURRENT_TIMESTAMP AND player_registration_end_at >= CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS player_registration_open, created_at, updated_at';

    public function list(int $page = 1, int $limit = 15): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM tournaments ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $pdo->query('SELECT COUNT(*) FROM tournaments')->fetchColumn(),
        ];
    }

    public function listForDirector(int $directorUserId, int $page = 1, int $limit = 15): array
    {
        $pdo = Database::getConnection();
        $scope = " WHERE id IN (
            SELECT DISTINCT tt.tournament_id
            FROM tournament_team_coaches c
            JOIN tournament_teams tt ON tt.id = c.tournament_team_id
            WHERE c.user_id = :user_id AND c.is_active = 1 AND tt.status = 'ACTIVE'
        )";
        $count = $pdo->prepare('SELECT COUNT(*) FROM tournaments' . $scope);
        $count->execute([':user_id' => $directorUserId]);
        $stmt = $pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM tournaments' . $scope
            . ' ORDER BY id DESC LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue(':user_id', $directorUserId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();
        return [
            'items' => $stmt->fetchAll(),
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $count->fetchColumn(),
        ];
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM tournaments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): array
    {
        if (
            !array_key_exists('player_registration_start_at', $data)
            && !array_key_exists('player_registration_end_at', $data)
        ) {
            $data = [...$data, ...PlayerRegistrationPolicy::defaultWindow()];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO tournaments (name, category_id, tournament_type, status, start_date, end_date, points_win, points_draw, points_loss, regular_rounds, qualification_count, rules, player_registration_deadline, player_registration_enabled, player_registration_start_at, player_registration_end_at) VALUES (:name, :category_id, :format, :status, :start_date, :end_date, :points_for_win, :points_for_draw, :points_for_loss, :rounds_count, :qualified_teams, :tie_breakers, :player_registration_deadline_round, :allow_player_registration, :player_registration_start_at, :player_registration_end_at)');
        $stmt->execute($this->tournamentValues($data));
        return $this->findById((int) $pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $current = $this->findById($id);
        if ($current === null) {
            return null;
        }

        $data = array_merge($current, $data);
        if (strtoupper((string) $data['format']) !== strtoupper((string) $current['format'])) {
            $rounds = Database::getConnection()->prepare('SELECT COUNT(*) FROM rounds r JOIN tournament_stages s ON s.id = r.stage_id WHERE s.tournament_id = :tournament_id');
            $rounds->execute([':tournament_id' => $id]);
            if ((int) $rounds->fetchColumn() > 0) {
                throw new \DomainException('No se puede cambiar el formato después de crear jornadas.');
            }
        }
        $values = $this->tournamentValues($data);
        $values[':updated_at'] = nowIso();
        $values[':id'] = $id;
        $stmt = Database::getConnection()->prepare('UPDATE tournaments SET name = :name, category_id = :category_id, tournament_type = :format, status = :status, start_date = :start_date, end_date = :end_date, points_win = :points_for_win, points_draw = :points_for_draw, points_loss = :points_for_loss, regular_rounds = :rounds_count, qualification_count = :qualified_teams, rules = :tie_breakers, player_registration_deadline = :player_registration_deadline_round, player_registration_enabled = :allow_player_registration, player_registration_start_at = :player_registration_start_at, player_registration_end_at = :player_registration_end_at, updated_at = :updated_at WHERE id = :id');
        $stmt->execute($values);
        return $this->findById($id);
    }

    private function tournamentValues(array $data): array
    {
        $rules = $data['tie_breakers'] ?? null;
        if (is_array($rules)) {
            $rules = json_encode($rules, JSON_THROW_ON_ERROR);
        } elseif (is_string($rules) && $rules !== '' && json_decode($rules) === null && json_last_error() !== JSON_ERROR_NONE) {
            $rules = json_encode($rules, JSON_THROW_ON_ERROR);
        }

        return [
            ':name' => $data['name'],
            ':category_id' => $data['category_id'],
            ':format' => strtoupper((string) ($data['format'] ?? 'LEAGUE')),
            ':status' => strtoupper((string) ($data['status'] ?? 'DRAFT')),
            ':start_date' => $data['start_date'] ?? null,
            ':end_date' => $data['end_date'] ?? null,
            ':points_for_win' => (int) ($data['points_for_win'] ?? 3),
            ':points_for_draw' => (int) ($data['points_for_draw'] ?? 1),
            ':points_for_loss' => (int) ($data['points_for_loss'] ?? 0),
            ':rounds_count' => (int) ($data['rounds_count'] ?? 10),
            ':qualified_teams' => (int) ($data['qualified_teams'] ?? 8),
            ':tie_breakers' => $rules,
            ':player_registration_deadline_round' => (int) ($data['player_registration_deadline_round'] ?? 4),
            ':allow_player_registration' => filter_var($data['allow_player_registration'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            ':player_registration_start_at' => $data['player_registration_start_at'] ?? null,
            ':player_registration_end_at' => $data['player_registration_end_at'] ?? null,
        ];
    }

    public function changeStatus(int $id, string $status): ?array
    {
        $stmt = Database::getConnection()->prepare('UPDATE tournaments SET status = :status, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([':status' => strtoupper($status), ':updated_at' => nowIso(), ':id' => $id]);
        return $this->findById($id);
    }

    public function categoryExists(int $categoryId): bool
    {
        $stmt = Database::getConnection()->prepare('SELECT id FROM categories WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute([':id' => $categoryId]);
        return $stmt->fetchColumn() !== false;
    }

    public function addTeamToTournament(int $tournamentId, int $teamId): bool
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $tournamentSql = 'SELECT status, tournament_type FROM tournaments WHERE id = :id';
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $tournamentSql .= ' FOR UPDATE';
            }
            $tournament = $pdo->prepare($tournamentSql);
            $tournament->execute([':id' => $tournamentId]);
            $tournamentRow = $tournament->fetch();
            if ($tournamentRow === false) {
                throw new \DomainException('Torneo no encontrado.');
            }
            $status = $tournamentRow['status'];
            if (!in_array($status, ['DRAFT', 'REGISTRATION'], true)) {
                throw new \DomainException('La inscripción de equipos está cerrada.');
            }
            $stages = $pdo->prepare('SELECT COUNT(*) FROM tournament_stages WHERE tournament_id = :tournament_id');
            $stages->execute([':tournament_id' => $tournamentId]);
            if ((int) $stages->fetchColumn() > 0) {
                throw new \DomainException('No se pueden inscribir equipos después de iniciar la programación o crear fases del torneo.');
            }

            $team = $pdo->prepare('SELECT id FROM teams WHERE id = :id AND is_active = 1');
            $team->execute([':id' => $teamId]);
            if ($team->fetchColumn() === false) {
                throw new \DomainException('Equipo no encontrado o inactivo.');
            }

            $exists = $pdo->prepare('SELECT id FROM tournament_teams WHERE tournament_id = :tournament_id AND team_id = :team_id');
            $exists->execute([':tournament_id' => $tournamentId, ':team_id' => $teamId]);
            if ($exists->fetchColumn() !== false) {
                $pdo->commit();
                return false;
            }

            $insert = $pdo->prepare('INSERT INTO tournament_teams (tournament_id, team_id, status) VALUES (:tournament_id, :team_id, :status)');
            $insert->execute([':tournament_id' => $tournamentId, ':team_id' => $teamId, ':status' => 'ACTIVE']);
            $pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function getTeamsForTournament(int $tournamentId): array
    {
        $stmt = Database::getConnection()->prepare('SELECT tt.id, tt.id AS tournament_team_id, tt.tournament_id, tt.team_id, tt.status, tt.seed_number, tt.registration_date, t.name, t.short_name, t.logo_url FROM tournament_teams tt JOIN teams t ON t.id = tt.team_id WHERE tt.tournament_id = :tournament_id AND tt.status = :status ORDER BY tt.seed_number IS NULL, tt.seed_number, t.name');
        $stmt->execute([':tournament_id' => $tournamentId, ':status' => 'ACTIVE']);
        return $stmt->fetchAll();
    }

    public function assignDirectorToTeam(int $tournamentId, int $teamId, int $directorUserId): bool
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $lockTournament = $pdo->prepare('SELECT id FROM tournaments WHERE id = :id' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lockTournament->execute([':id' => $tournamentId]);
            if ($lockTournament->fetchColumn() === false) {
                throw new \DomainException('Torneo no encontrado.');
            }

            $director = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'director_tecnico' AND is_active = 1");
            $director->execute([':id' => $directorUserId]);
            if ($director->fetchColumn() === false) {
                throw new \DomainException('Director técnico no encontrado o inactivo.');
            }

            $team = $pdo->prepare("SELECT id FROM tournament_teams WHERE tournament_id = :tournament_id AND team_id = :team_id AND status = 'ACTIVE'");
            $team->execute([':tournament_id' => $tournamentId, ':team_id' => $teamId]);
            $tournamentTeamId = $team->fetchColumn();
            if ($tournamentTeamId === false) {
                throw new \DomainException('El equipo no está inscrito activamente en este torneo.');
            }

            $existing = $pdo->prepare(
                'SELECT id, is_active FROM tournament_team_coaches
                 WHERE tournament_team_id = :tournament_team_id AND user_id = :user_id'
            );
            $existing->execute([
                ':tournament_team_id' => $tournamentTeamId,
                ':user_id' => $directorUserId,
            ]);
            $existingAssignment = $existing->fetch();
            if ($existingAssignment !== false && (bool) $existingAssignment['is_active']) {
                $pdo->commit();
                return false;
            }

            $occupied = $pdo->prepare(
                'SELECT c.id
                 FROM tournament_team_coaches c
                 JOIN tournament_teams tt ON tt.id = c.tournament_team_id
                 WHERE tt.tournament_id = :tournament_id
                   AND c.is_active = 1
                   AND (tt.id = :tournament_team_id OR (c.user_id = :user_id AND tt.id <> :other_team_id))'
            );
            $occupied->execute([
                ':tournament_id' => $tournamentId,
                ':tournament_team_id' => $tournamentTeamId,
                ':user_id' => $directorUserId,
                ':other_team_id' => $tournamentTeamId,
            ]);
            if ($occupied->fetchColumn() !== false) {
                throw new \DomainException('El equipo o el director técnico ya tiene una asignación activa en este torneo.');
            }

            if ($existingAssignment !== false) {
                $reactivate = $pdo->prepare(
                    'UPDATE tournament_team_coaches SET is_active = 1, assigned_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                $reactivate->execute([':id' => $existingAssignment['id']]);
                $pdo->commit();
                return true;
            }

            $insert = $pdo->prepare(
                'INSERT INTO tournament_team_coaches (tournament_team_id, user_id, is_active, assigned_at)
                 VALUES (:tournament_team_id, :user_id, 1, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                ':tournament_team_id' => $tournamentTeamId,
                ':user_id' => $directorUserId,
            ]);
            $pdo->commit();
            return true;
        } catch (\PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $databaseMessage = (string) ($exception->errorInfo[2] ?? $exception->getMessage());
            if (str_contains($databaseMessage, 'uq_tournament_team_coach') || str_contains($databaseMessage, 'tournament_team_coaches.tournament_team_id, tournament_team_coaches.user_id')) {
                throw new \DomainException('El equipo o el director técnico ya tiene una asignación activa en este torneo.', 0, $exception);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function getTeamForDirector(int $tournamentId, int $directorUserId): ?array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT tt.id AS tournament_team_id, tt.tournament_id, tt.team_id, c.user_id,
                    t.name, t.short_name, t.logo_url
             FROM tournament_teams tt
             JOIN teams t ON t.id = tt.team_id
             JOIN tournament_team_coaches c ON c.tournament_team_id = tt.id AND c.is_active = 1
             JOIN users du ON du.id = c.user_id AND du.role = 'director_tecnico' AND du.is_active = 1
             WHERE tt.tournament_id = :tournament_id
               AND c.user_id = :director_user_id
               AND tt.status = 'ACTIVE'
               AND t.is_active = 1"
        );
        $stmt->execute([
            ':tournament_id' => $tournamentId,
            ':director_user_id' => $directorUserId,
        ]);
        return $stmt->fetch() ?: null;
    }

    public function getTeamForTournament(int $tournamentId, int $teamId): ?array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT tt.id AS tournament_team_id, tt.tournament_id, tt.team_id, c.user_id,
                    t.name, t.short_name, t.logo_url
             FROM tournament_teams tt
             JOIN teams t ON t.id = tt.team_id
             LEFT JOIN tournament_team_coaches c ON c.tournament_team_id = tt.id AND c.is_active = 1
             WHERE tt.tournament_id = :tournament_id
               AND tt.team_id = :team_id
               AND tt.status = 'ACTIVE'
               AND t.is_active = 1"
        );
        $stmt->execute([
            ':tournament_id' => $tournamentId,
            ':team_id' => $teamId,
        ]);
        return $stmt->fetch() ?: null;
    }

    public function getPlayersForTournamentTeam(int $tournamentId, int $tournamentTeamId): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT tp.*, tt.team_id, p.first_name, p.last_name, p.birth_date, p.photo_url,
                    p.phone, p.email, p.is_active, t.name AS team_name
             FROM tournament_players tp
             JOIN tournament_teams tt ON tt.id = tp.tournament_team_id
             JOIN teams t ON t.id = tt.team_id
             JOIN players p ON p.id = tp.player_id
             WHERE tp.tournament_id = :tournament_id AND tp.tournament_team_id = :tournament_team_id
             ORDER BY p.last_name, p.first_name'
        );
        $stmt->execute([
            ':tournament_id' => $tournamentId,
            ':tournament_team_id' => $tournamentTeamId,
        ]);
        return $stmt->fetchAll();
    }

    public function createPlayerForDirector(int $tournamentId, int $directorUserId, array $player, ?int $jerseyNumber): int
    {
        return $this->createPlayerForTournamentTeam($tournamentId, $player, $jerseyNumber, $directorUserId);
    }

    public function createPlayerForTournamentTeam(int $tournamentId, array $player, ?int $jerseyNumber, ?int $directorUserId = null, ?int $teamId = null): int
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            if ($directorUserId !== null) {
                $userQuery = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'director_tecnico' AND is_active = 1");
                $userQuery->execute([':id' => $directorUserId]);
                if ($userQuery->fetchColumn() === false) {
                    throw new \DomainException('Director técnico no encontrado o inactivo.');
                }
            }

            (new PlayerRegistrationPolicy())->assertAllowed($pdo, $tournamentId);

            $teamSql = "SELECT tt.id FROM tournament_teams tt
                        WHERE tt.tournament_id = :tournament_id
                          AND tt.status = 'ACTIVE'";
            $teamParams = [
                ':tournament_id' => $tournamentId,
            ];
            if ($directorUserId !== null) {
                $teamSql .= ' AND EXISTS (
                    SELECT 1 FROM tournament_team_coaches c
                    JOIN users u ON u.id = c.user_id
                    WHERE c.tournament_team_id = tt.id
                      AND c.user_id = :director_user_id
                      AND c.is_active = 1
                      AND u.role = \'director_tecnico\'
                      AND u.is_active = 1
                )';
                $teamParams[':director_user_id'] = $directorUserId;
            } elseif ($teamId !== null) {
                $teamSql .= ' AND tt.team_id = :team_id';
                $teamParams[':team_id'] = $teamId;
            } else {
                throw new \InvalidArgumentException('Se requiere un director o equipo para registrar al jugador.');
            }
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $teamSql .= ' FOR UPDATE';
            }
            $teamQuery = $pdo->prepare($teamSql);
            $teamQuery->execute($teamParams);
            $tournamentTeamId = $teamQuery->fetchColumn();
            if ($tournamentTeamId === false) {
                throw new \DomainException(
                    $directorUserId !== null
                        ? 'No hay un equipo activo asignado a este director técnico para el torneo.'
                        : 'El equipo no está inscrito activamente en este torneo.'
                );
            }

            if ($jerseyNumber !== null) {
                $jerseyQuery = $pdo->prepare('SELECT id FROM tournament_players WHERE tournament_team_id = :tournament_team_id AND jersey_number = :jersey_number');
                $jerseyQuery->execute([
                    ':tournament_team_id' => $tournamentTeamId,
                    ':jersey_number' => $jerseyNumber,
                ]);
                if ($jerseyQuery->fetchColumn() !== false) {
                    throw new \DomainException('El número de camiseta ya está asignado a otro jugador del equipo.');
                }
            }

            $playerInsert = $pdo->prepare(
                'INSERT INTO players (first_name, last_name, birth_date, phone, email, is_active)
                 VALUES (:first_name, :last_name, :birth_date, :phone, :email, 1)'
            );
            $playerInsert->execute([
                ':first_name' => $player['first_name'],
                ':last_name' => $player['last_name'],
                ':birth_date' => $player['birth_date'] ?? null,
                ':phone' => $player['phone'] ?? null,
                ':email' => $player['email'] ?? null,
            ]);
            $playerId = (int) $pdo->lastInsertId();

            $registration = $pdo->prepare(
                "INSERT INTO tournament_players
                    (tournament_id, tournament_team_id, player_id, jersey_number, status)
                 VALUES (:tournament_id, :tournament_team_id, :player_id, :jersey_number, 'ACTIVE')"
            );
            $registration->execute([
                ':tournament_id' => $tournamentId,
                ':tournament_team_id' => $tournamentTeamId,
                ':player_id' => $playerId,
                ':jersey_number' => $jerseyNumber,
            ]);
            $pdo->commit();
            return $playerId;
        } catch (\PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $databaseMessage = (string) ($exception->errorInfo[2] ?? $exception->getMessage());
            if (str_contains($databaseMessage, 'uq_jersey') || str_contains($databaseMessage, 'tournament_players.tournament_team_id, tournament_players.jersey_number')) {
                throw new \DomainException('El número de camiseta ya está asignado a otro jugador del equipo.', 0, $exception);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function addPlayerToTournament(
        int $tournamentId,
        int $teamId,
        int $playerId,
        ?int $jerseyNumber = null,
        ?array $overrideAudit = null,
    ): bool
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            (new PlayerRegistrationPolicy())->assertAllowed(
                $pdo,
                $tournamentId,
                $overrideAudit !== null,
            );

            $teamQuery = $pdo->prepare('SELECT id FROM tournament_teams WHERE tournament_id = :tournament_id AND team_id = :team_id AND status = :status');
            $teamQuery->execute([':tournament_id' => $tournamentId, ':team_id' => $teamId, ':status' => 'ACTIVE']);
            $tournamentTeamId = $teamQuery->fetchColumn();
            if ($tournamentTeamId === false) {
                throw new \DomainException('El equipo no está inscrito activamente en este torneo.');
            }

            $playerQuery = $pdo->prepare('SELECT id FROM players WHERE id = :id AND is_active = 1');
            $playerQuery->execute([':id' => $playerId]);
            if ($playerQuery->fetchColumn() === false) {
                throw new \DomainException('Jugador no encontrado o inactivo.');
            }

            $exists = $pdo->prepare('SELECT id FROM tournament_players WHERE tournament_id = :tournament_id AND player_id = :player_id');
            $exists->execute([':tournament_id' => $tournamentId, ':player_id' => $playerId]);
            if ($exists->fetchColumn() !== false) {
                $pdo->commit();
                return false;
            }

            if ($jerseyNumber !== null) {
                $jerseyQuery = $pdo->prepare('SELECT id FROM tournament_players WHERE tournament_team_id = :tournament_team_id AND jersey_number = :jersey_number');
                $jerseyQuery->execute([
                    ':tournament_team_id' => $tournamentTeamId,
                    ':jersey_number' => $jerseyNumber,
                ]);
                if ($jerseyQuery->fetchColumn() !== false) {
                    throw new \DomainException('El número de camiseta ya está asignado a otro jugador del equipo.');
                }
            }

            $insert = $pdo->prepare('INSERT INTO tournament_players (tournament_id, tournament_team_id, player_id, jersey_number, status) VALUES (:tournament_id, :tournament_team_id, :player_id, :jersey_number, :status)');
            $insert->execute([
                ':tournament_id' => $tournamentId,
                ':tournament_team_id' => $tournamentTeamId,
                ':player_id' => $playerId,
                ':jersey_number' => $jerseyNumber,
                ':status' => 'ACTIVE',
            ]);
            if ($overrideAudit !== null) {
                (new AdminAuditRepository())->record($pdo, [
                    ...$overrideAudit,
                    'action' => 'players.registration.override',
                    'entity_type' => 'tournament_player',
                    'entity_id' => (int) $pdo->lastInsertId(),
                    'tournament_id' => $tournamentId,
                    'new_values' => [
                        'tournament_id' => $tournamentId,
                        'team_id' => $teamId,
                        'tournament_team_id' => (int) $tournamentTeamId,
                        'player_id' => $playerId,
                        'jersey_number' => $jerseyNumber,
                    ],
                ]);
            }
            $pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function addPlayersToTournament(
        int $tournamentId,
        int $teamId,
        array $playerIds,
        ?array $overrideAudit = null,
    ): int {
        if ($playerIds === [] || count(array_unique($playerIds)) !== count($playerIds)) {
            throw new \InvalidArgumentException('Selecciona al menos un jugador y no repitas jugadores.');
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            (new PlayerRegistrationPolicy())->assertAllowed(
                $pdo,
                $tournamentId,
                $overrideAudit !== null,
            );

            $teamQuery = $pdo->prepare(
                'SELECT id FROM tournament_teams
                 WHERE tournament_id = :tournament_id AND team_id = :team_id AND status = :status',
            );
            $teamQuery->execute([
                ':tournament_id' => $tournamentId,
                ':team_id' => $teamId,
                ':status' => 'ACTIVE',
            ]);
            $tournamentTeamId = $teamQuery->fetchColumn();
            if ($tournamentTeamId === false) {
                throw new \DomainException('El equipo no está inscrito activamente en este torneo.');
            }

            $created = 0;
            foreach ($playerIds as $playerId) {
                $playerQuery = $pdo->prepare('SELECT id FROM players WHERE id = :id AND is_active = 1');
                $playerQuery->execute([':id' => $playerId]);
                if ($playerQuery->fetchColumn() === false) {
                    throw new \DomainException('Jugador no encontrado o inactivo.');
                }

                $exists = $pdo->prepare(
                    'SELECT id FROM tournament_players
                     WHERE tournament_id = :tournament_id AND player_id = :player_id',
                );
                $exists->execute([':tournament_id' => $tournamentId, ':player_id' => $playerId]);
                if ($exists->fetchColumn() !== false) {
                    throw new \DomainException('Uno o más jugadores ya están inscritos en este torneo.');
                }

                $insert = $pdo->prepare(
                    "INSERT INTO tournament_players
                        (tournament_id, tournament_team_id, player_id, jersey_number, status)
                     VALUES (:tournament_id, :tournament_team_id, :player_id, NULL, 'ACTIVE')",
                );
                $insert->execute([
                    ':tournament_id' => $tournamentId,
                    ':tournament_team_id' => (int) $tournamentTeamId,
                    ':player_id' => $playerId,
                ]);
                $registrationId = (int) $pdo->lastInsertId();

                if ($overrideAudit !== null) {
                    (new AdminAuditRepository())->record($pdo, [
                        ...$overrideAudit,
                        'action' => 'players.registration.override',
                        'entity_type' => 'tournament_player',
                        'entity_id' => $registrationId,
                        'tournament_id' => $tournamentId,
                        'new_values' => [
                            'tournament_id' => $tournamentId,
                            'team_id' => $teamId,
                            'tournament_team_id' => (int) $tournamentTeamId,
                            'player_id' => $playerId,
                            'jersey_number' => null,
                        ],
                    ]);
                }
                $created++;
            }

            $pdo->commit();
            return $created;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteTournamentPlayerRegistration(int $tournamentId, int $registrationId): bool
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $registration = $pdo->prepare(
                'SELECT id FROM tournament_players
                 WHERE id = :registration_id AND tournament_id = :tournament_id
                 LIMIT 1',
            );
            $registration->execute([
                ':registration_id' => $registrationId,
                ':tournament_id' => $tournamentId,
            ]);
            if ($registration->fetchColumn() === false) {
                $pdo->commit();
                return false;
            }

            $history = $pdo->prepare(
                'SELECT
                    EXISTS(SELECT 1 FROM match_player_attendance WHERE tournament_player_id = :attendance_id)
                    OR EXISTS(SELECT 1 FROM match_goals WHERE tournament_player_id = :goal_id)
                    OR EXISTS(SELECT 1 FROM match_disciplinary_events WHERE tournament_player_id = :discipline_id)',
            );
            $history->execute([
                ':attendance_id' => $registrationId,
                ':goal_id' => $registrationId,
                ':discipline_id' => $registrationId,
            ]);
            if ((bool) $history->fetchColumn()) {
                throw new \DomainException(
                    'No se puede quitar al jugador porque tiene asistencia, goles o eventos disciplinarios registrados.',
                );
            }

            $delete = $pdo->prepare(
                'DELETE FROM tournament_players
                 WHERE id = :registration_id AND tournament_id = :tournament_id',
            );
            $delete->execute([
                ':registration_id' => $registrationId,
                ':tournament_id' => $tournamentId,
            ]);

            $pdo->commit();
            return $delete->rowCount() === 1;
        } catch (\PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (
                (string) $exception->getCode() === '23000'
                || in_array((int) ($exception->errorInfo[1] ?? 0), [19, 1451], true)
            ) {
                throw new \DomainException(
                    'No se puede quitar al jugador porque ya tiene registros asociados a partidos.',
                    0,
                    $exception,
                );
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function getPlayersForTournament(int $tournamentId): array
    {
        $stmt = Database::getConnection()->prepare('SELECT tp.*, tt.team_id, p.first_name, p.last_name, p.birth_date, p.photo_url, p.phone, p.email, p.is_active, t.name AS team_name FROM tournament_players tp JOIN tournament_teams tt ON tt.id = tp.tournament_team_id JOIN teams t ON t.id = tt.team_id JOIN players p ON p.id = tp.player_id WHERE tp.tournament_id = :tournament_id ORDER BY t.name, p.last_name, p.first_name');
        $stmt->execute([':tournament_id' => $tournamentId]);
        return $stmt->fetchAll();
    }

}

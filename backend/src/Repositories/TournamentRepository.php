<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class TournamentRepository
{
    private const SELECT_COLUMNS = 'id, category_id, name, tournament_type AS format, status, start_date, end_date, points_win AS points_for_win, points_draw AS points_for_draw, points_loss AS points_for_loss, regular_rounds AS rounds_count, qualification_count AS qualified_teams, rules AS tie_breakers, player_registration_deadline AS player_registration_deadline_round, player_registration_enabled AS allow_player_registration, created_at, updated_at';

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

    public function findById(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM tournaments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO tournaments (name, category_id, tournament_type, status, start_date, end_date, points_win, points_draw, points_loss, regular_rounds, qualification_count, rules, player_registration_deadline, player_registration_enabled) VALUES (:name, :category_id, :format, :status, :start_date, :end_date, :points_for_win, :points_for_draw, :points_for_loss, :rounds_count, :qualified_teams, :tie_breakers, :player_registration_deadline_round, :allow_player_registration)');
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
        $stmt = Database::getConnection()->prepare('UPDATE tournaments SET name = :name, category_id = :category_id, tournament_type = :format, status = :status, start_date = :start_date, end_date = :end_date, points_win = :points_for_win, points_draw = :points_for_draw, points_loss = :points_for_loss, regular_rounds = :rounds_count, qualification_count = :qualified_teams, rules = :tie_breakers, player_registration_deadline = :player_registration_deadline_round, player_registration_enabled = :allow_player_registration, updated_at = :updated_at WHERE id = :id');
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

    public function addPlayerToTournament(int $tournamentId, int $teamId, int $playerId): bool
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $tournamentQuery = $pdo->prepare('SELECT status, player_registration_enabled, player_registration_deadline FROM tournaments WHERE id = :id');
            $tournamentQuery->execute([':id' => $tournamentId]);
            $tournament = $tournamentQuery->fetch();
            if ($tournament === false) {
                throw new \DomainException('Torneo no encontrado.');
            }
            if (!in_array($tournament['status'], ['DRAFT', 'REGISTRATION'], true) || !(bool) $tournament['player_registration_enabled']) {
                throw new \DomainException('La inscripción de jugadores está cerrada.');
            }

            $completedRounds = $pdo->prepare('SELECT COUNT(DISTINCT r.id) FROM rounds r JOIN tournament_stages s ON s.id = r.stage_id JOIN matches m ON m.round_id = r.id WHERE s.tournament_id = :tournament_id AND r.status = :round_status AND m.status = :match_status AND m.is_bye = 0');
            $completedRounds->execute([':tournament_id' => $tournamentId, ':round_status' => 'COMPLETED', ':match_status' => 'COMPLETED']);
            if ((int) $completedRounds->fetchColumn() >= (int) $tournament['player_registration_deadline']) {
                throw new \DomainException('Se alcanzó la jornada límite para inscribir jugadores.');
            }

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

            $insert = $pdo->prepare('INSERT INTO tournament_players (tournament_id, tournament_team_id, player_id, status) VALUES (:tournament_id, :tournament_team_id, :player_id, :status)');
            $insert->execute([
                ':tournament_id' => $tournamentId,
                ':tournament_team_id' => $tournamentTeamId,
                ':player_id' => $playerId,
                ':status' => 'ACTIVE',
            ]);
            $pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function getPlayersForTournament(int $tournamentId): array
    {
        $stmt = Database::getConnection()->prepare('SELECT tp.*, tt.team_id, p.first_name, p.last_name, t.name AS team_name FROM tournament_players tp JOIN tournament_teams tt ON tt.id = tp.tournament_team_id JOIN teams t ON t.id = tt.team_id JOIN players p ON p.id = tp.player_id WHERE tp.tournament_id = :tournament_id ORDER BY t.name, p.last_name, p.first_name');
        $stmt->execute([':tournament_id' => $tournamentId]);
        return $stmt->fetchAll();
    }
}

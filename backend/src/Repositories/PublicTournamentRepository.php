<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

final class PublicTournamentRepository
{
    private const SELECT_COLUMNS = 'id, category_id, name, tournament_type AS format, status, start_date, end_date, points_win AS points_for_win, points_draw AS points_for_draw, points_loss AS points_for_loss, regular_rounds AS rounds_count, qualification_count AS qualified_teams, rules AS tie_breakers, player_registration_deadline AS player_registration_deadline_round, player_registration_enabled AS allow_player_registration, created_at, updated_at';

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function list(int $page = 1, int $limit = 12): array
    {
        $pdo = $this->pdo ?? Database::getConnection();
        $offset = max(0, ($page - 1) * $limit);

        $statement = $pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS .
            " FROM tournaments
            WHERE status IN ('REGISTRATION', 'IN_PROGRESS')
            ORDER BY CASE status WHEN 'IN_PROGRESS' THEN 0 WHEN 'REGISTRATION' THEN 1 ELSE 2 END, start_date DESC, id DESC
            LIMIT :limit OFFSET :offset"
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $statement->fetchAll(PDO::FETCH_ASSOC),
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $pdo->query("SELECT COUNT(*) FROM tournaments WHERE status IN ('REGISTRATION', 'IN_PROGRESS')")->fetchColumn(),
        ];
    }

    public function getFirstMatchOfDay(string $date): ?array
    {
        $statement = ($this->pdo ?? Database::getConnection())->prepare(
            "SELECT m.id, m.match_date, m.kickoff_time, m.venue, m.status,
                    m.home_score, m.away_score,
                    h.name AS home_team_name, a.name AS away_team_name,
                    t.name AS tournament_name
             FROM matches m
             JOIN rounds r ON r.id = m.round_id
             JOIN tournament_stages s ON s.id = r.stage_id
             JOIN tournaments t ON t.id = s.tournament_id
             JOIN tournament_teams ht ON ht.id = m.home_tournament_team_id
             JOIN teams h ON h.id = ht.team_id
             JOIN tournament_teams at ON at.id = m.away_tournament_team_id
             JOIN teams a ON a.id = at.team_id
             WHERE m.match_date = :match_date
                AND m.is_bye = 0
                AND m.status IN ('SCHEDULED', 'IN_PROGRESS', 'COMPLETED')
                AND t.status IN ('REGISTRATION', 'IN_PROGRESS')
             ORDER BY CASE WHEN m.kickoff_time IS NULL THEN 1 ELSE 0 END,
                      m.kickoff_time, t.id, s.sequence_number,
                      r.round_number, m.match_number, m.id
             LIMIT 1"
        );
        $statement->execute([':match_date' => $date]);
        $match = $statement->fetch(PDO::FETCH_ASSOC);

        return $match === false ? null : $match;
    }

    public function tournamentExists(int $tournamentId): bool
    {
        $statement = ($this->pdo ?? Database::getConnection())->prepare(
            'SELECT 1 FROM tournaments WHERE id = :tournament_id'
        );
        $statement->execute([':tournament_id' => $tournamentId]);

        return $statement->fetchColumn() !== false;
    }

    public function getTopScorers(int $tournamentId, int $limit): array
    {
        $pdo = $this->pdo ?? Database::getConnection();
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $playerName = $isMysql
            ? "TRIM(CONCAT(p.first_name, ' ', p.last_name))"
            : "TRIM(p.first_name || ' ' || p.last_name)";
        $playerNameOrder = $isMysql ? 'player_name' : 'player_name COLLATE NOCASE';

        $statement = $pdo->prepare(
            "WITH scorer_totals AS (
                SELECT
                    tp.player_id,
                    tp.tournament_team_id,
                    {$playerName} AS player_name,
                    t.name AS team_name,
                    COUNT(mg.id) AS total_goals
                FROM match_goals mg
                JOIN matches m ON m.id = mg.match_id
                JOIN rounds r ON r.id = m.round_id
                JOIN tournament_stages s ON s.id = r.stage_id
                JOIN tournament_players tp ON tp.id = mg.tournament_player_id
                    AND tp.tournament_id = s.tournament_id
                JOIN tournament_teams tt ON tt.id = tp.tournament_team_id
                    AND tt.tournament_id = s.tournament_id
                JOIN teams t ON t.id = tt.team_id
                JOIN players p ON p.id = tp.player_id
                WHERE s.tournament_id = :tournament_id
                    AND m.status = 'COMPLETED'
                    AND m.is_bye = 0
                    AND mg.goal_type IN ('REGULAR', 'PENALTY')
                    AND (
                        tp.tournament_team_id = m.home_tournament_team_id
                        OR tp.tournament_team_id = m.away_tournament_team_id
                    )
                GROUP BY
                    tp.player_id,
                    tp.tournament_team_id,
                    p.first_name,
                    p.last_name,
                    t.name
            ),
            ranked_scorers AS (
                SELECT
                    RANK() OVER (ORDER BY total_goals DESC) AS position,
                    player_id,
                    player_name,
                    tournament_team_id,
                    team_name,
                    total_goals
                FROM scorer_totals
            )
            SELECT position, player_id, player_name, tournament_team_id, team_name, total_goals
            FROM ranked_scorers
            ORDER BY total_goals DESC, {$playerNameOrder}, player_id
            LIMIT :result_limit"
        );
        $statement->bindValue(':tournament_id', $tournamentId, PDO::PARAM_INT);
        $statement->bindValue(':result_limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $scorer): array => [
                'position' => (int) $scorer['position'],
                'player_id' => (int) $scorer['player_id'],
                'player_name' => $scorer['player_name'],
                'tournament_team_id' => (int) $scorer['tournament_team_id'],
                'team_name' => $scorer['team_name'],
                'total_goals' => (int) $scorer['total_goals'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }
}

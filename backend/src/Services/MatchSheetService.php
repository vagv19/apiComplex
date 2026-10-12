<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

final class MatchSheetService
{
    public function getMatchSheet(int $matchId): array
    {
        $pdo = Database::getConnection();
        $matchQuery = $pdo->prepare(
            'SELECT m.id, m.is_bye, m.match_date, m.kickoff_time, m.venue,
                    m.home_score, m.away_score, r.round_number, r.name AS round_name,
                    s.name AS stage_name, s.tournament_id, t.name AS tournament_name, c.name AS category_name,
                    ht.id AS home_registration_id, at.id AS away_registration_id,
                    h.name AS home_team_name, a.name AS away_team_name
             FROM matches m
             JOIN rounds r ON r.id = m.round_id
             JOIN tournament_stages s ON s.id = r.stage_id
             JOIN tournaments t ON t.id = s.tournament_id
             JOIN categories c ON c.id = t.category_id
             LEFT JOIN tournament_teams ht ON ht.id = m.home_tournament_team_id
             LEFT JOIN tournament_teams at ON at.id = m.away_tournament_team_id
             LEFT JOIN teams h ON h.id = ht.team_id
             LEFT JOIN teams a ON a.id = at.team_id
             WHERE m.id = :match_id'
        );
        $matchQuery->execute([':match_id' => $matchId]);
        $match = $matchQuery->fetch();
        if ($match === false) {
            throw new \DomainException('Partido no encontrado.');
        }
        if ((bool) $match['is_bye'] || $match['home_registration_id'] === null || $match['away_registration_id'] === null) {
            throw new \DomainException('No se puede generar una cédula para un descanso.');
        }

        $playersQuery = $pdo->prepare(
            'SELECT tp.tournament_team_id, tp.jersey_number, p.first_name, p.last_name,
                    COALESCE(goals.goal_count, 0) AS goals,
                    COALESCE(events.yellow_cards, 0) AS cautions,
                    COALESCE(events.expulsions, 0) AS expulsions
             FROM tournament_players tp
             JOIN players p ON p.id = tp.player_id
             LEFT JOIN (
                 SELECT tournament_player_id, COUNT(*) AS goal_count
                 FROM match_goals
                 WHERE match_id = :goal_match_id
                 GROUP BY tournament_player_id
             ) goals ON goals.tournament_player_id = tp.id
             LEFT JOIN (
                 SELECT tournament_player_id,
                        SUM(CASE WHEN event_type = :yellow_card THEN 1 ELSE 0 END) AS yellow_cards,
                        SUM(CASE WHEN event_type IN (:second_yellow, :direct_red) THEN 1 ELSE 0 END) AS expulsions
                 FROM match_disciplinary_events
                 WHERE match_id = :event_match_id
                 GROUP BY tournament_player_id
             ) events ON events.tournament_player_id = tp.id
             WHERE tp.tournament_id = :tournament_id
               AND tp.tournament_team_id IN (:home_registration_id, :away_registration_id)
               AND tp.status = :active_status
             ORDER BY tp.tournament_team_id, tp.jersey_number IS NULL, tp.jersey_number,
                      p.last_name, p.first_name'
        );
        $playersQuery->execute([
            ':goal_match_id' => $matchId,
            ':yellow_card' => 'YELLOW_CARD',
            ':second_yellow' => 'SECOND_YELLOW_RED',
            ':direct_red' => 'DIRECT_RED',
            ':event_match_id' => $matchId,
            ':tournament_id' => (int) $match['tournament_id'],
            ':home_registration_id' => (int) $match['home_registration_id'],
            ':away_registration_id' => (int) $match['away_registration_id'],
            ':active_status' => 'ACTIVE',
        ]);

        $teams = [
            'home' => [
                'id' => (int) $match['home_registration_id'],
                'name' => $match['home_team_name'],
                'players' => [],
            ],
            'away' => [
                'id' => (int) $match['away_registration_id'],
                'name' => $match['away_team_name'],
                'players' => [],
            ],
        ];
        foreach ($playersQuery->fetchAll() as $player) {
            $side = (int) $player['tournament_team_id'] === $teams['home']['id'] ? 'home' : 'away';
            $teams[$side]['players'][] = [
                'jersey_number' => $player['jersey_number'] === null ? null : (int) $player['jersey_number'],
                'name' => trim($player['first_name'] . ' ' . $player['last_name']),
                'goals' => (int) $player['goals'],
                'cautions' => (int) $player['cautions'],
                'expulsions' => (int) $player['expulsions'],
            ];
        }

        return [
            'match_id' => $matchId,
            'league_name' => "Sport Complex 'La Pasion'",
            'tournament_name' => $match['tournament_name'],
            'category_name' => $match['category_name'],
            'round_name' => $match['round_name'] ?: 'Jornada ' . $match['round_number'],
            'stage_name' => $match['stage_name'],
            'venue' => $match['venue'],
            'match_date' => $match['match_date'],
            'match_day' => $this->spanishDay($match['match_date']),
            'kickoff_time' => $this->formatTime($match['kickoff_time']),
            'home_score' => $match['home_score'] === null ? null : (int) $match['home_score'],
            'away_score' => $match['away_score'] === null ? null : (int) $match['away_score'],
            'home_team' => $teams['home'],
            'away_team' => $teams['away'],
        ];
    }

    private function spanishDay(?string $date): string
    {
        if ($date === null || $date === '') {
            return 'Sin fecha';
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false) {
            return 'Sin fecha';
        }
        $days = [
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            7 => 'Domingo',
        ];
        return $days[(int) $parsed->format('N')];
    }

    private function formatTime(?string $time): string
    {
        if ($time === null || $time === '') {
            return 'Sin hora';
        }
        return substr($time, 0, 5);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

class StandingsService
{
    public function calculate(int $tournamentId, ?int $groupId = null): array
    {
        $pdo = Database::getConnection();
        $tournamentQuery = $pdo->prepare('SELECT tournament_type, points_win, points_draw, points_loss FROM tournaments WHERE id = :id');
        $tournamentQuery->execute([':id' => $tournamentId]);
        $tournament = $tournamentQuery->fetch();
        if ($tournament === false) {
            return [];
        }

        $teamSql = 'SELECT tt.id AS tournament_team_id, tt.team_id, t.name FROM tournament_teams tt JOIN teams t ON t.id = tt.team_id';
        $teamParams = [':tournament_id' => $tournamentId];
        if ($groupId === null) {
            $teamSql .= ' WHERE tt.tournament_id = :tournament_id';
        } else {
            $teamSql .= ' JOIN group_teams gt ON gt.tournament_team_id = tt.id WHERE tt.tournament_id = :tournament_id AND gt.group_id = :group_id';
            $teamParams[':group_id'] = $groupId;
        }
        $teamSql .= ' ORDER BY t.name, tt.team_id';
        $teamQuery = $pdo->prepare($teamSql);
        $teamQuery->execute($teamParams);
        $standings = [];
        foreach ($teamQuery->fetchAll() as $team) {
            $registrationId = (int) $team['tournament_team_id'];
            $standings[$registrationId] = [
                'tournament_team_id' => $registrationId,
                'team_id' => (int) $team['team_id'],
                'group_id' => $groupId,
                'team_name' => $team['name'],
                'played' => 0,
                'won' => 0,
                'drawn' => 0,
                'lost' => 0,
                'goals_for' => 0,
                'goals_against' => 0,
                'goal_difference' => 0,
                'points' => 0,
            ];
        }

        $matchSql = 'SELECT m.home_tournament_team_id, m.away_tournament_team_id, m.home_score, m.away_score FROM matches m JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id';
        $matchParams = [':tournament_id' => $tournamentId, ':completed' => 'COMPLETED'];
        if ($groupId === null) {
            $matchSql .= ' WHERE s.tournament_id = :tournament_id';
            if ($tournament['tournament_type'] === 'LEAGUE') {
                $matchSql .= ' AND s.stage_type = :stage_type';
                $matchParams[':stage_type'] = 'LEAGUE';
            }
        } else {
            $matchSql .= ' WHERE s.tournament_id = :tournament_id AND s.stage_type = :stage_type AND r.group_id = :group_id';
            $matchParams[':stage_type'] = 'GROUP_STAGE';
            $matchParams[':group_id'] = $groupId;
        }
        $matchSql .= ' AND m.status = :completed AND m.is_bye = 0 AND m.home_score IS NOT NULL AND m.away_score IS NOT NULL ORDER BY m.id';
        $matchQuery = $pdo->prepare($matchSql);
        $matchQuery->execute($matchParams);

        foreach ($matchQuery->fetchAll() as $match) {
            $homeId = (int) $match['home_tournament_team_id'];
            $awayId = (int) $match['away_tournament_team_id'];
            if (!isset($standings[$homeId], $standings[$awayId])) {
                continue;
            }
            $homeScore = (int) $match['home_score'];
            $awayScore = (int) $match['away_score'];
            $standings[$homeId]['played']++;
            $standings[$awayId]['played']++;
            $standings[$homeId]['goals_for'] += $homeScore;
            $standings[$homeId]['goals_against'] += $awayScore;
            $standings[$awayId]['goals_for'] += $awayScore;
            $standings[$awayId]['goals_against'] += $homeScore;

            if ($homeScore > $awayScore) {
                $standings[$homeId]['won']++;
                $standings[$homeId]['points'] += (int) $tournament['points_win'];
                $standings[$awayId]['lost']++;
                $standings[$awayId]['points'] += (int) $tournament['points_loss'];
            } elseif ($awayScore > $homeScore) {
                $standings[$awayId]['won']++;
                $standings[$awayId]['points'] += (int) $tournament['points_win'];
                $standings[$homeId]['lost']++;
                $standings[$homeId]['points'] += (int) $tournament['points_loss'];
            } else {
                $standings[$homeId]['drawn']++;
                $standings[$awayId]['drawn']++;
                $standings[$homeId]['points'] += (int) $tournament['points_draw'];
                $standings[$awayId]['points'] += (int) $tournament['points_draw'];
            }
        }

        foreach ($standings as &$team) {
            $team['goal_difference'] = $team['goals_for'] - $team['goals_against'];
        }
        unset($team);

        $standings = array_values($standings);
        usort($standings, static fn(array $a, array $b): int =>
            [$b['points'], $b['goal_difference'], $b['goals_for'], $a['team_name'], $a['team_id']]
            <=> [$a['points'], $a['goal_difference'], $a['goals_for'], $b['team_name'], $b['team_id']]
        );
        foreach ($standings as $index => &$team) {
            $team['position'] = $index + 1;
        }
        unset($team);
        return $standings;
    }
}

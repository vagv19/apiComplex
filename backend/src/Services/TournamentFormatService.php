<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;

final class TournamentFormatService
{
    public function __construct(
        private readonly ScheduleAlgorithms $algorithms = new ScheduleAlgorithms(),
        private readonly StandingsService $standings = new StandingsService(),
    ) {
    }

    public function createFlashGroups(int $tournamentId, int $groupCount): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $this->lockTournament($pdo, $tournamentId);
            $tournament = $this->tournament($pdo, $tournamentId, 'FLASH');
            $this->assertRegistrationOpen($tournament);
            $this->assertNoStages($pdo, $tournamentId);

            $teams = $this->activeTeams($pdo, $tournamentId);
            if ($groupCount < 2 || $groupCount > 26 || $groupCount > intdiv(count($teams), 2)) {
                throw new \DomainException('El número de grupos debe ser al menos dos y dejar dos o más equipos en cada grupo.');
            }

            $stage = $pdo->prepare('INSERT INTO tournament_stages (tournament_id, name, stage_type, sequence_number, qualification_rules, status) VALUES (:tournament_id, :name, :stage_type, :sequence_number, :qualification_rules, :status)');
            $stage->execute([
                ':tournament_id' => $tournamentId,
                ':name' => 'Fase de grupos',
                ':stage_type' => 'GROUP_STAGE',
                ':sequence_number' => 1,
                ':qualification_rules' => json_encode([
                    'group_count' => $groupCount,
                    'qualifiers_per_group' => 2,
                    'total_qualifiers' => (int) $tournament['qualification_count'],
                ], JSON_THROW_ON_ERROR),
                ':status' => 'PENDING',
            ]);
            $stageId = (int) $pdo->lastInsertId();

            $groups = [];
            for ($index = 0; $index < $groupCount; $index++) {
                $group = $pdo->prepare('INSERT INTO tournament_groups (stage_id, name, group_order) VALUES (:stage_id, :name, :group_order)');
                $group->execute([
                    ':stage_id' => $stageId,
                    ':name' => 'Grupo ' . ($index + 1),
                    ':group_order' => $index + 1,
                ]);
                $groups[] = (int) $pdo->lastInsertId();
            }

            $insert = $pdo->prepare('INSERT INTO group_teams (group_id, tournament_team_id) VALUES (:group_id, :tournament_team_id)');
            foreach ($teams as $index => $team) {
                $groupIndex = $this->snakeGroupIndex($index, $groupCount);
                $insert->execute([
                    ':group_id' => $groups[$groupIndex],
                    ':tournament_team_id' => (int) $team['id'],
                ]);
            }

            $pdo->commit();
            return [
                'tournament_id' => $tournamentId,
                'stage_id' => $stageId,
                'groups' => $groupCount,
                'teams' => count($teams),
            ];
        } catch (\Throwable $exception) {
            $this->rollBack($pdo);
            throw $exception;
        }
    }

    public function cancelFlashGroups(int $tournamentId): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $this->lockTournament($pdo, $tournamentId);
            $tournament = $this->tournament($pdo, $tournamentId, 'FLASH');
            $this->assertRegistrationOpen($tournament);
            $stage = $this->stage($pdo, $tournamentId, 'GROUP_STAGE');
            if ($stage === null) {
                $pdo->commit();
                return ['tournament_id' => $tournamentId, 'groups_deleted' => 0, 'teams_unassigned' => 0];
            }

            $stageId = (int) $stage['id'];
            $rounds = $pdo->prepare('SELECT COUNT(*) FROM rounds WHERE stage_id = :stage_id');
            $rounds->execute([':stage_id' => $stageId]);
            if ((int) $rounds->fetchColumn() > 0) {
                throw new \DomainException('No se pueden cancelar los grupos porque el calendario ya fue generado.');
            }

            $otherStages = $pdo->prepare('SELECT COUNT(*) FROM tournament_stages WHERE tournament_id = :tournament_id AND id <> :stage_id');
            $otherStages->execute([':tournament_id' => $tournamentId, ':stage_id' => $stageId]);
            if ((int) $otherStages->fetchColumn() > 0) {
                throw new \DomainException('No se pueden cancelar los grupos porque el torneo ya tiene otras fases.');
            }

            $groups = $this->groupRows($pdo, $stageId);
            $members = $pdo->prepare('SELECT COUNT(*) FROM group_teams gt JOIN tournament_groups g ON g.id = gt.group_id WHERE g.stage_id = :stage_id');
            $members->execute([':stage_id' => $stageId]);
            $teamCount = (int) $members->fetchColumn();

            $deleteMembers = $pdo->prepare('DELETE FROM group_teams WHERE group_id IN (SELECT id FROM tournament_groups WHERE stage_id = :stage_id)');
            $deleteMembers->execute([':stage_id' => $stageId]);
            $pdo->prepare('DELETE FROM tournament_groups WHERE stage_id = :stage_id')
                ->execute([':stage_id' => $stageId]);
            $pdo->prepare('DELETE FROM tournament_stages WHERE id = :stage_id')
                ->execute([':stage_id' => $stageId]);

            $pdo->commit();
            return [
                'tournament_id' => $tournamentId,
                'groups_deleted' => count($groups),
                'teams_unassigned' => $teamCount,
            ];
        } catch (\Throwable $exception) {
            $this->rollBack($pdo);
            throw $exception;
        }
    }

    public function generateFlashGroupSchedule(int $tournamentId): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $this->lockTournament($pdo, $tournamentId);
            $tournament = $this->tournament($pdo, $tournamentId, 'FLASH');
            $this->assertRegistrationOpen($tournament);
            $stage = $this->stage($pdo, $tournamentId, 'GROUP_STAGE');
            if ($stage === null) {
                throw new \DomainException('Primero debes crear los grupos del torneo.');
            }
            $existing = $pdo->prepare('SELECT COUNT(*) FROM rounds WHERE stage_id = :stage_id');
            $existing->execute([':stage_id' => $stage['id']]);
            if ((int) $existing->fetchColumn() > 0) {
                throw new \DomainException('El calendario de grupos ya fue generado.');
            }

            $groups = $this->groupRows($pdo, (int) $stage['id']);
            $roundCount = 0;
            $matchCount = 0;
            $byeCount = 0;
            foreach ($groups as $group) {
                $members = $this->groupTeamIds($pdo, (int) $group['id']);
                $rounds = $this->algorithms->roundRobin($members);
                $roundCount += count($rounds);
                foreach ($rounds as $roundNumber => $pairs) {
                    $round = $pdo->prepare('INSERT INTO rounds (stage_id, group_id, round_number, name, status) VALUES (:stage_id, :group_id, :round_number, :name, :status)');
                    $round->execute([
                        ':stage_id' => $stage['id'],
                        ':group_id' => $group['id'],
                        ':round_number' => $roundNumber + 1,
                        ':name' => 'Jornada ' . ($roundNumber + 1),
                        ':status' => 'PENDING',
                    ]);
                    $roundId = (int) $pdo->lastInsertId();
                    foreach ($pairs as $matchNumber => $pair) {
                        $this->insertMatch($pdo, $roundId, $pair['home'], $pair['away'], $matchNumber + 1);
                        if ($pair['home'] === null || $pair['away'] === null) {
                            $byeCount++;
                        } else {
                            $matchCount++;
                        }
                    }
                }
            }

            $pdo->prepare('UPDATE tournament_stages SET status = :status WHERE id = :id')
                ->execute([':status' => 'IN_PROGRESS', ':id' => $stage['id']]);
            $pdo->commit();
            return [
                'tournament_id' => $tournamentId,
                'groups' => count($groups),
                'rounds' => $roundCount,
                'matches' => $matchCount,
                'byes' => $byeCount,
            ];
        } catch (\Throwable $exception) {
            $this->rollBack($pdo);
            throw $exception;
        }
    }

    public function generateCupBracket(int $tournamentId): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $this->lockTournament($pdo, $tournamentId);
            $tournament = $this->tournament($pdo, $tournamentId, 'CUP');
            $this->assertRegistrationOpen($tournament);
            $this->assertNoStages($pdo, $tournamentId);
            $teams = $this->activeTeams($pdo, $tournamentId);
            $this->assertEnoughTeams($teams);

            $bracket = $this->createKnockoutStage(
                $pdo,
                $tournamentId,
                $teams,
                1,
                ['source' => 'CUP', 'seeding' => 'seed_number_then_registration_id'],
            );
            $pdo->commit();
            return $bracket;
        } catch (\Throwable $exception) {
            $this->rollBack($pdo);
            throw $exception;
        }
    }

    public function qualifyFlash(int $tournamentId, int $qualifiersPerGroup, int $totalQualifiers): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $this->lockTournament($pdo, $tournamentId);
            $tournament = $this->tournament($pdo, $tournamentId, 'FLASH');
            $groupStage = $this->stage($pdo, $tournamentId, 'GROUP_STAGE');
            if ($groupStage === null) {
                throw new \DomainException('No se han creado los grupos.');
            }
            $this->assertNoKnockoutStage($pdo, $tournamentId);
            $groups = $this->groupRows($pdo, (int) $groupStage['id']);
            if (count($groups) < 2) {
                throw new \DomainException('Se requieren al menos dos grupos para clasificar.');
            }
            if ($qualifiersPerGroup < 1 || $totalQualifiers < count($groups) || $totalQualifiers < 2) {
                throw new \DomainException('El total de clasificados debe incluir al menos un equipo de cada grupo.');
            }

            $matchState = $pdo->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN is_bye = 0 THEN 1 ELSE 0 END) AS real_matches, SUM(CASE WHEN is_bye = 0 AND status <> :completed THEN 1 ELSE 0 END) AS unfinished FROM matches WHERE round_id IN (SELECT id FROM rounds WHERE stage_id = :stage_id)');
            $matchState->execute([':completed' => 'COMPLETED', ':stage_id' => $groupStage['id']]);
            $state = $matchState->fetch();
            if ((int) $state['total'] === 0 || (int) $state['real_matches'] === 0 || (int) $state['unfinished'] > 0) {
                throw new \DomainException('Todos los partidos reales de la fase de grupos deben tener un resultado antes de clasificar.');
            }

            $tiers = [];
            $groupStandings = [];
            foreach ($groups as $group) {
                $rows = $this->standings->calculate($tournamentId, (int) $group['id']);
                if (count($rows) < $qualifiersPerGroup) {
                    throw new \DomainException('Cada grupo debe tener suficientes equipos para la regla de clasificación.');
                }
                $groupStandings[] = ['group' => $group, 'rows' => $rows];
                foreach (array_slice($rows, 0, $qualifiersPerGroup) as $position => $row) {
                    $row['group_order'] = (int) $group['group_order'];
                    $row['group_name'] = $group['name'];
                    $row['group_position'] = $position + 1;
                    $tiers[$position + 1][] = $row;
                }
            }

            if ($totalQualifiers > array_sum(array_map('count', $tiers))) {
                throw new \DomainException('El total solicitado supera la cantidad de equipos elegibles.');
            }
            foreach ($tiers as &$tier) {
                usort($tier, static fn(array $left, array $right): int =>
                    [$right['points'], $right['goal_difference'], $right['goals_for'], $left['group_order'], $left['team_name'], $left['team_id']]
                    <=> [$left['points'], $left['goal_difference'], $left['goals_for'], $right['group_order'], $right['team_name'], $right['team_id']]
                );
            }
            unset($tier);

            $qualified = [];
            foreach ($tiers as $tier) {
                foreach ($tier as $candidate) {
                    if (count($qualified) === $totalQualifiers) {
                        break 2;
                    }
                    $candidate['seed_number'] = count($qualified) + 1;
                    $qualified[] = $candidate;
                }
            }
            $qualifiedIds = array_map(static fn(array $team): int => (int) $team['tournament_team_id'], $qualified);
            if (count(array_unique($qualifiedIds)) !== $totalQualifiers) {
                throw new \DomainException('La clasificación no produjo la cantidad esperada de equipos únicos.');
            }

            $rules = [
                'source' => 'FLASH_QUALIFICATION',
                'qualifiers_per_group' => $qualifiersPerGroup,
                'total_qualifiers' => $totalQualifiers,
                'priority' => 'group_position_then_points_goal_difference_goals_for',
                'qualified' => array_map(static fn(array $team): array => [
                    'seed_number' => $team['seed_number'],
                    'team_id' => $team['team_id'],
                    'group_id' => $team['group_id'] ?? null,
                    'group_name' => $team['group_name'],
                    'group_position' => $team['group_position'],
                ], $qualified),
            ];
            $groupStageRules = $pdo->prepare('UPDATE tournament_stages SET qualification_rules = :rules WHERE id = :id');
            $groupStageRules->execute([
                ':rules' => json_encode($rules, JSON_THROW_ON_ERROR),
                ':id' => $groupStage['id'],
            ]);

            $participants = array_map(static fn(array $team): array => [
                'id' => (int) $team['tournament_team_id'],
                'seed_number' => (int) $team['seed_number'],
                'team_id' => (int) $team['team_id'],
            ], $qualified);
            $bracket = $this->createKnockoutStage($pdo, $tournamentId, $participants, 2, $rules);
            $bracket['qualified'] = $qualified;
            $bracket['group_standings'] = array_map(static fn(array $item): array => [
                'group_id' => (int) $item['group']['id'],
                'group_name' => $item['group']['name'],
                'standings' => $item['rows'],
            ], $groupStandings);

            $pdo->commit();
            return $bracket;
        } catch (\Throwable $exception) {
            $this->rollBack($pdo);
            throw $exception;
        }
    }

    public function advanceKnockout(int $tournamentId): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $this->lockTournament($pdo, $tournamentId);
            $tournament = $this->tournament($pdo, $tournamentId);
            $stage = $this->stage($pdo, $tournamentId, 'KNOCKOUT');
            if ($stage === null) {
                throw new \DomainException('No existe un cuadro de eliminación para este torneo.');
            }

            $latestRound = $pdo->prepare('SELECT id, round_number FROM rounds WHERE stage_id = :stage_id ORDER BY round_number DESC LIMIT 1');
            $latestRound->execute([':stage_id' => $stage['id']]);
            $round = $latestRound->fetch();
            if ($round === false) {
                throw new \DomainException('El cuadro no contiene jornadas.');
            }

            $matchQuery = $pdo->prepare('SELECT home_tournament_team_id, away_tournament_team_id, home_score, away_score, home_penalties, away_penalties, status, is_bye FROM matches WHERE round_id = :round_id ORDER BY match_number, id');
            $matchQuery->execute([':round_id' => $round['id']]);
            $matches = $matchQuery->fetchAll();
            if ($matches === []) {
                throw new \DomainException('La última ronda no contiene partidos.');
            }

            $winners = [];
            foreach ($matches as $match) {
                if ((bool) $match['is_bye'] || $match['status'] === 'BYE') {
                    $winners[] = (int) ($match['home_tournament_team_id'] ?? $match['away_tournament_team_id']);
                    continue;
                }
                if ($match['status'] !== 'COMPLETED' || $match['home_score'] === null || $match['away_score'] === null) {
                    throw new \DomainException('Todos los partidos de la ronda deben estar resueltos antes de avanzar.');
                }

                $homeScore = (int) $match['home_score'];
                $awayScore = (int) $match['away_score'];
                if ($homeScore > $awayScore) {
                    $winners[] = (int) $match['home_tournament_team_id'];
                } elseif ($awayScore > $homeScore) {
                    $winners[] = (int) $match['away_tournament_team_id'];
                } elseif ($match['home_penalties'] !== null && $match['away_penalties'] !== null && (int) $match['home_penalties'] !== (int) $match['away_penalties']) {
                    $winners[] = (int) ((int) $match['home_penalties'] > (int) $match['away_penalties']
                        ? $match['home_tournament_team_id']
                        : $match['away_tournament_team_id']);
                } else {
                    throw new \DomainException('Un partido empatado requiere una tanda de penales con ganador antes de avanzar.');
                }
            }

            if (count($winners) === 1) {
                $team = $pdo->prepare('SELECT tt.team_id, t.name FROM tournament_teams tt JOIN teams t ON t.id = tt.team_id WHERE tt.id = :id');
                $team->execute([':id' => $winners[0]]);
                $champion = $team->fetch();
                $pdo->prepare('UPDATE tournament_stages SET status = :status WHERE id = :id')
                    ->execute([':status' => 'COMPLETED', ':id' => $stage['id']]);
                $pdo->prepare('UPDATE tournaments SET status = :status, updated_at = :updated_at WHERE id = :id')
                    ->execute([':status' => 'COMPLETED', ':updated_at' => nowIso(), ':id' => $tournamentId]);
                $pdo->commit();
                return [
                    'tournament_id' => $tournamentId,
                    'champion' => [
                        'team_id' => (int) $champion['team_id'],
                        'team_name' => $champion['name'],
                        'tournament_team_id' => $winners[0],
                    ],
                    'completed' => true,
                ];
            }
            if (count(array_unique($winners)) !== count($winners) || count($winners) < 2) {
                throw new \DomainException('No se pudo determinar una lista válida de equipos que avanzan.');
            }

            $nextRoundNumber = (int) $round['round_number'] + 1;
            $pairs = [];
            for ($index = 0; $index < count($winners); $index += 2) {
                if (!isset($winners[$index + 1])) {
                    throw new \DomainException('El cuadro produjo un número impar de equipos; no se puede crear la siguiente ronda.');
                }
                $pairs[] = ['home' => $winners[$index], 'away' => $winners[$index + 1]];
            }
            $roundName = $this->knockoutRoundName(count($winners));
            $roundInsert = $pdo->prepare('INSERT INTO rounds (stage_id, group_id, round_number, name, status) VALUES (:stage_id, NULL, :round_number, :name, :status)');
            $roundInsert->execute([
                ':stage_id' => $stage['id'],
                ':round_number' => $nextRoundNumber,
                ':name' => $roundName,
                ':status' => 'PENDING',
            ]);
            $roundId = (int) $pdo->lastInsertId();
            foreach ($pairs as $index => $pair) {
                $this->insertMatch($pdo, $roundId, $pair['home'], $pair['away'], $index + 1);
            }

            $pdo->commit();
            return [
                'tournament_id' => $tournamentId,
                'round_number' => $nextRoundNumber,
                'round_name' => $roundName,
                'matches' => count($pairs),
                'completed' => false,
            ];
        } catch (\Throwable $exception) {
            $this->rollBack($pdo);
            throw $exception;
        }
    }

    public function startLeagueKnockout(int $tournamentId): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $this->lockTournament($pdo, $tournamentId);
            $tournament = $this->tournament($pdo, $tournamentId, 'LEAGUE');
            if (!in_array($tournament['status'], ['DRAFT', 'REGISTRATION', 'IN_PROGRESS'], true)) {
                throw new \DomainException('No se puede iniciar la liguilla con el estado actual del torneo.');
            }
            if ($this->stage($pdo, $tournamentId, 'KNOCKOUT') !== null) {
                throw new \DomainException('La liguilla ya fue iniciada.');
            }

            $leagueStage = $this->stage($pdo, $tournamentId, 'LEAGUE');
            if ($leagueStage === null) {
                throw new \DomainException('Primero debes generar el calendario de la fase regular.');
            }

            $rounds = $pdo->prepare('SELECT COUNT(*) FROM rounds WHERE stage_id = :stage_id');
            $rounds->execute([':stage_id' => $leagueStage['id']]);
            if ((int) $rounds->fetchColumn() === 0) {
                throw new \DomainException('La fase regular no tiene jornadas generadas.');
            }

            $emptyRounds = $pdo->prepare('SELECT COUNT(*) FROM rounds r LEFT JOIN matches m ON m.round_id = r.id WHERE r.stage_id = :stage_id AND m.id IS NULL');
            $emptyRounds->execute([':stage_id' => $leagueStage['id']]);
            if ((int) $emptyRounds->fetchColumn() > 0) {
                throw new \DomainException('Todas las jornadas de la fase regular deben tener partidos antes de iniciar la liguilla.');
            }

            $matchState = $pdo->prepare('SELECT COUNT(*) AS real_matches, SUM(CASE WHEN is_bye = 0 AND status <> :completed THEN 1 ELSE 0 END) AS unfinished FROM matches WHERE round_id IN (SELECT id FROM rounds WHERE stage_id = :stage_id)');
            $matchState->execute([
                ':completed' => 'COMPLETED',
                ':stage_id' => $leagueStage['id'],
            ]);
            $state = $matchState->fetch();
            if ((int) $state['real_matches'] === 0 || (int) $state['unfinished'] > 0) {
                throw new \DomainException('Todos los partidos reales de la fase regular deben tener resultado antes de iniciar la liguilla.');
            }

            $standings = $this->standings->calculate($tournamentId);
            $qualifiedCount = min((int) $tournament['qualification_count'], count($standings));
            if ($qualifiedCount < 2) {
                throw new \DomainException('Se requieren al menos dos equipos clasificados para iniciar la liguilla.');
            }

            $qualified = array_slice($standings, 0, $qualifiedCount);
            $participants = [];
            foreach ($qualified as $index => $team) {
                $participants[] = [
                    'id' => (int) $team['tournament_team_id'],
                    'seed_number' => $index + 1,
                    'team_id' => (int) $team['team_id'],
                ];
            }
            $rules = [
                'source' => 'LEAGUE_QUALIFICATION',
                'qualified_teams' => $qualifiedCount,
                'qualified' => array_map(static fn(array $team, int $index): array => [
                    'seed_number' => $index + 1,
                    'tournament_team_id' => (int) $team['tournament_team_id'],
                    'team_id' => (int) $team['team_id'],
                    'team_name' => $team['team_name'],
                    'regular_position' => (int) $team['position'],
                ], $qualified, array_keys($qualified)),
            ];

            $sequence = $pdo->prepare('SELECT COALESCE(MAX(sequence_number), 0) + 1 FROM tournament_stages WHERE tournament_id = :tournament_id');
            $sequence->execute([':tournament_id' => $tournamentId]);
            $bracket = $this->createKnockoutStage(
                $pdo,
                $tournamentId,
                $participants,
                (int) $sequence->fetchColumn(),
                $rules,
            );
            $bracket['started'] = true;
            $bracket['round_name'] = $this->knockoutRoundName($qualifiedCount);

            $pdo->prepare('UPDATE tournament_stages SET status = :status WHERE id = :id')
                ->execute([':status' => 'COMPLETED', ':id' => $leagueStage['id']]);
            $pdo->prepare('UPDATE tournaments SET status = :status, updated_at = :updated_at WHERE id = :id')
                ->execute([':status' => 'IN_PROGRESS', ':updated_at' => nowIso(), ':id' => $tournamentId]);

            $pdo->commit();
            return $bracket;
        } catch (\Throwable $exception) {
            $this->rollBack($pdo);
            throw $exception;
        }
    }

    public function listGroups(int $tournamentId): array
    {
        $pdo = Database::getConnection();
        $tournament = $this->tournament($pdo, $tournamentId);
        if ($tournament['tournament_type'] !== 'FLASH') {
            return [];
        }
        $stage = $this->stage($pdo, $tournamentId, 'GROUP_STAGE');
        if ($stage === null) {
            return [];
        }

        $groups = $this->groupRows($pdo, (int) $stage['id']);
        $members = $pdo->prepare('SELECT tt.id AS tournament_team_id, tt.team_id, tt.seed_number, t.name, t.short_name, t.logo_url FROM group_teams gt JOIN tournament_teams tt ON tt.id = gt.tournament_team_id JOIN teams t ON t.id = tt.team_id WHERE gt.group_id = :group_id ORDER BY tt.seed_number IS NULL, tt.seed_number, t.name, tt.team_id');
        foreach ($groups as &$group) {
            $members->execute([':group_id' => $group['id']]);
            $group['teams'] = $members->fetchAll();
        }
        unset($group);
        return $groups;
    }

    private function createKnockoutStage(PDO $pdo, int $tournamentId, array $teams, int $sequence, array $rules): array
    {
        $this->assertEnoughTeams($teams);
        $stage = $pdo->prepare('INSERT INTO tournament_stages (tournament_id, name, stage_type, sequence_number, qualification_rules, status) VALUES (:tournament_id, :name, :stage_type, :sequence_number, :qualification_rules, :status)');
        $stage->execute([
            ':tournament_id' => $tournamentId,
            ':name' => ($rules['source'] ?? null) === 'LEAGUE_QUALIFICATION'
                ? 'Liguilla'
                : 'Cuadro de eliminación',
            ':stage_type' => 'KNOCKOUT',
            ':sequence_number' => $sequence,
            ':qualification_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
            ':status' => 'IN_PROGRESS',
        ]);
        $stageId = (int) $pdo->lastInsertId();
        $ids = array_map(static fn(array $team): int => (int) ($team['id'] ?? $team['tournament_team_id']), $teams);
        $pairs = $this->algorithms->knockoutPairs($ids);
        $roundName = $this->knockoutRoundName(count($ids));
        $round = $pdo->prepare('INSERT INTO rounds (stage_id, group_id, round_number, name, status) VALUES (:stage_id, NULL, :round_number, :name, :status)');
        $round->execute([
            ':stage_id' => $stageId,
            ':round_number' => 1,
            ':name' => $roundName,
            ':status' => 'PENDING',
        ]);
        $roundId = (int) $pdo->lastInsertId();
        $matches = 0;
        $byes = 0;
        foreach ($pairs as $index => $pair) {
            $this->insertMatch($pdo, $roundId, $pair['home'], $pair['away'], $index + 1);
            if ($pair['home'] === null || $pair['away'] === null) {
                $byes++;
            } else {
                $matches++;
            }
        }
        return [
            'tournament_id' => $tournamentId,
            'stage_id' => $stageId,
            'rounds' => 1,
            'matches' => $matches,
            'byes' => $byes,
            'qualified_teams' => count($ids),
        ];
    }

    private function insertMatch(PDO $pdo, int $roundId, ?int $home, ?int $away, int $matchNumber): void
    {
        $isBye = $home === null || $away === null;
        $byeTeam = $home ?? $away;
        $stmt = $pdo->prepare('INSERT INTO matches (round_id, home_tournament_team_id, away_tournament_team_id, match_number, status, is_bye) VALUES (:round_id, :home_id, :away_id, :match_number, :status, :is_bye)');
        $stmt->execute([
            ':round_id' => $roundId,
            ':home_id' => $isBye ? $byeTeam : $home,
            ':away_id' => $isBye ? null : $away,
            ':match_number' => $matchNumber,
            ':status' => $isBye ? 'BYE' : 'SCHEDULED',
            ':is_bye' => $isBye ? 1 : 0,
        ]);
    }

    private function tournament(PDO $pdo, int $tournamentId, ?string $expectedFormat = null): array
    {
        $stmt = $pdo->prepare('SELECT id, tournament_type, status, qualification_count FROM tournaments WHERE id = :id');
        $stmt->execute([':id' => $tournamentId]);
        $tournament = $stmt->fetch();
        if ($tournament === false) {
            throw new \DomainException('Torneo no encontrado.');
        }
        if ($expectedFormat !== null && $tournament['tournament_type'] !== $expectedFormat) {
            throw new \DomainException('Esta operación no está disponible para el formato seleccionado.');
        }
        return $tournament;
    }

    private function lockTournament(PDO $pdo, int $tournamentId): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $stmt = $pdo->prepare('SELECT id FROM tournaments WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $tournamentId]);
    }

    private function assertRegistrationOpen(array $tournament): void
    {
        if (!in_array($tournament['status'], ['DRAFT', 'REGISTRATION'], true)) {
            throw new \DomainException('La fase de grupos solo puede generarse antes de iniciar el torneo.');
        }
    }

    private function assertNoStages(PDO $pdo, int $tournamentId): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_stages WHERE tournament_id = :tournament_id');
        $stmt->execute([':tournament_id' => $tournamentId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new \DomainException('El torneo ya tiene fases o jornadas; no se puede volver a generar el formato.');
        }
    }

    private function assertNoKnockoutStage(PDO $pdo, int $tournamentId): void
    {
        if ($this->stage($pdo, $tournamentId, 'KNOCKOUT') !== null) {
            throw new \DomainException('El cuadro final ya fue generado.');
        }
    }

    private function activeTeams(PDO $pdo, int $tournamentId): array
    {
        $stmt = $pdo->prepare('SELECT tt.id, tt.team_id, tt.seed_number FROM tournament_teams tt WHERE tt.tournament_id = :tournament_id AND tt.status = :status ORDER BY tt.seed_number IS NULL, tt.seed_number, tt.team_id');
        $stmt->execute([':tournament_id' => $tournamentId, ':status' => 'ACTIVE']);
        return $stmt->fetchAll();
    }

    private function assertEnoughTeams(array $teams): void
    {
        if (count($teams) < 2) {
            throw new \DomainException('Se requieren al menos dos equipos inscritos activamente.');
        }
    }

    private function stage(PDO $pdo, int $tournamentId, string $type): ?array
    {
        $stmt = $pdo->prepare('SELECT id, sequence_number, status, qualification_rules FROM tournament_stages WHERE tournament_id = :tournament_id AND stage_type = :stage_type ORDER BY sequence_number LIMIT 1');
        $stmt->execute([':tournament_id' => $tournamentId, ':stage_type' => $type]);
        return $stmt->fetch() ?: null;
    }

    private function groupRows(PDO $pdo, int $stageId): array
    {
        $stmt = $pdo->prepare('SELECT id, stage_id, name, group_order FROM tournament_groups WHERE stage_id = :stage_id ORDER BY group_order, id');
        $stmt->execute([':stage_id' => $stageId]);
        return $stmt->fetchAll();
    }

    private function groupTeamIds(PDO $pdo, int $groupId): array
    {
        $stmt = $pdo->prepare('SELECT tt.id FROM group_teams gt JOIN tournament_teams tt ON tt.id = gt.tournament_team_id WHERE gt.group_id = :group_id ORDER BY tt.seed_number IS NULL, tt.seed_number, tt.team_id');
        $stmt->execute([':group_id' => $groupId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    private function snakeGroupIndex(int $teamIndex, int $groupCount): int
    {
        $cycle = intdiv($teamIndex, $groupCount);
        $position = $teamIndex % $groupCount;
        return $cycle % 2 === 0 ? $position : $groupCount - 1 - $position;
    }

    private function knockoutRoundName(int $participantCount): string
    {
        return match (true) {
            $participantCount <= 2 => 'Final',
            $participantCount <= 4 => 'Semifinales',
            $participantCount <= 8 => 'Cuartos de final',
            $participantCount <= 16 => 'Octavos de final',
            default => 'Ronda de eliminación',
        };
    }

    private function rollBack(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

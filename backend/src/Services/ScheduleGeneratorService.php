<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

class ScheduleGeneratorService
{
    public function generateLeagueSchedule(int $tournamentId): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $tournamentQuery = $pdo->prepare('SELECT tournament_type, status, regular_rounds FROM tournaments WHERE id = :id');
            $tournamentQuery->execute([':id' => $tournamentId]);
            $tournament = $tournamentQuery->fetch();
            if ($tournament === false) {
                throw new \DomainException('Torneo no encontrado.');
            }
            if ($tournament['tournament_type'] !== 'LEAGUE') {
                throw new \DomainException('La generación automática solo está disponible para torneos de liga.');
            }
            if (!in_array($tournament['status'], ['DRAFT', 'REGISTRATION'], true)) {
                throw new \DomainException('El calendario solo puede generarse antes de iniciar el torneo.');
            }

            $teamQuery = $pdo->prepare('SELECT id, team_id FROM tournament_teams WHERE tournament_id = :tournament_id AND status = :status ORDER BY seed_number IS NULL, seed_number, team_id');
            $teamQuery->execute([':tournament_id' => $tournamentId, ':status' => 'ACTIVE']);
            $teamIds = array_map('intval', array_column($teamQuery->fetchAll(), 'id'));
            if (count($teamIds) < 2) {
                throw new \DomainException('Se requieren al menos dos equipos inscritos para generar el calendario.');
            }

            $existing = $pdo->prepare('SELECT COUNT(*) FROM rounds r JOIN tournament_stages s ON s.id = r.stage_id WHERE s.tournament_id = :tournament_id');
            $existing->execute([':tournament_id' => $tournamentId]);
            if ((int) $existing->fetchColumn() > 0) {
                throw new \DomainException('El calendario de este torneo ya fue generado.');
            }

            $stageQuery = $pdo->prepare('SELECT id FROM tournament_stages WHERE tournament_id = :tournament_id AND stage_type = :stage_type ORDER BY sequence_number LIMIT 1');
            $stageQuery->execute([':tournament_id' => $tournamentId, ':stage_type' => 'LEAGUE']);
            $stageId = $stageQuery->fetchColumn();
            if ($stageId === false) {
                $stageInsert = $pdo->prepare('INSERT INTO tournament_stages (tournament_id, name, stage_type, sequence_number, status) VALUES (:tournament_id, :name, :stage_type, :sequence_number, :status)');
                $stageInsert->execute([
                    ':tournament_id' => $tournamentId,
                    ':name' => 'Temporada regular',
                    ':stage_type' => 'LEAGUE',
                    ':sequence_number' => 1,
                    ':status' => 'PENDING',
                ]);
                $stageId = $pdo->lastInsertId();
            }

            $participants = $teamIds;
            if (count($participants) % 2 !== 0) {
                $participants[] = null;
            }
            $pairsPerRound = intdiv(count($participants), 2);
            $rotationRounds = count($participants) - 1;
            $configuredRounds = max(1, (int) $tournament['regular_rounds']);
            $insertRound = $pdo->prepare('INSERT INTO rounds (stage_id, group_id, round_number, name, status) VALUES (:stage_id, NULL, :round_number, :name, :status)');
            $insertMatch = $pdo->prepare('INSERT INTO matches (round_id, home_tournament_team_id, away_tournament_team_id, status, is_bye) VALUES (:round_id, :home_id, :away_id, :status, :is_bye)');
            $matchCount = 0;
            $byeCount = 0;

            for ($roundNumber = 1; $roundNumber <= $configuredRounds; $roundNumber++) {
                $cycle = intdiv($roundNumber - 1, $rotationRounds);
                $rotation = ($roundNumber - 1) % $rotationRounds;
                $roundTeams = $participants;
                for ($step = 0; $step < $rotation; $step++) {
                    $last = array_pop($roundTeams);
                    array_splice($roundTeams, 1, 0, [$last]);
                }

                $insertRound->execute([
                    ':stage_id' => $stageId,
                    ':round_number' => $roundNumber,
                    ':name' => 'Jornada ' . $roundNumber,
                    ':status' => 'PENDING',
                ]);
                $roundId = (int) $pdo->lastInsertId();

                for ($pair = 0; $pair < $pairsPerRound; $pair++) {
                    $home = $roundTeams[$pair];
                    $away = $roundTeams[count($roundTeams) - 1 - $pair];
                    if ($home === null || $away === null) {
                        $byeTeam = $home ?? $away;
                        $insertMatch->execute([
                            ':round_id' => $roundId,
                            ':home_id' => $byeTeam,
                            ':away_id' => null,
                            ':status' => 'BYE',
                            ':is_bye' => 1,
                        ]);
                        $byeCount++;
                        continue;
                    }

                    if (($cycle + $pair + $roundNumber) % 2 === 0) {
                        [$home, $away] = [$away, $home];
                    }
                    $insertMatch->execute([
                        ':round_id' => $roundId,
                        ':home_id' => $home,
                        ':away_id' => $away,
                        ':status' => 'SCHEDULED',
                        ':is_bye' => 0,
                    ]);
                    $matchCount++;
                }
            }

            $pdo->commit();
            return [
                'tournament_id' => $tournamentId,
                'rounds' => $configuredRounds,
                'matches' => $matchCount,
                'byes' => $byeCount,
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

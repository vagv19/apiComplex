<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AdminAuditRepository;

class MatchResultService
{
    public function recordResult(
        int $matchId,
        int $homeScore,
        int $awayScore,
        ?int $changedBy,
        string $reason,
        ?int $homePenalties = null,
        ?int $awayPenalties = null,
        ?string $changedByRole = null,
        ?string $ipAddress = null,
    ): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $result = $this->recordResultWithinTransaction(
                $pdo,
                $matchId,
                $homeScore,
                $awayScore,
                $changedBy,
                $reason,
                $homePenalties,
                $awayPenalties,
                $changedByRole,
                $ipAddress,
            );
            $pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function recordResultWithinTransaction(
        \PDO $pdo,
        int $matchId,
        int $homeScore,
        int $awayScore,
        ?int $changedBy,
        string $reason,
        ?int $homePenalties = null,
        ?int $awayPenalties = null,
        ?string $changedByRole = null,
        ?string $ipAddress = null,
    ): array {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('El resultado requiere una transacción activa.');
        }

        $tournamentQuery = $pdo->prepare('SELECT s.tournament_id FROM matches m JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id WHERE m.id = :id');
        $tournamentQuery->execute([':id' => $matchId]);
        $tournamentId = $tournamentQuery->fetchColumn();
        if ($tournamentId === false) {
            throw new \DomainException('Partido no encontrado.');
        }

        $this->lockTournament($pdo, (int) $tournamentId);
        $lockClause = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT m.*, r.round_number, r.stage_id, s.tournament_id, s.stage_type, t.status AS tournament_status FROM matches m JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id JOIN tournaments t ON t.id = s.tournament_id WHERE m.id = :id' . $lockClause);
        $stmt->execute([':id' => $matchId]);
        $match = $stmt->fetch();
        if ($match === false) {
            throw new \DomainException('Partido no encontrado.');
        }
        if ((bool) $match['is_bye'] || $match['status'] === 'BYE') {
            throw new \DomainException('No se puede registrar un marcador para un descanso.');
        }
        if ($match['status'] === 'CANCELLED') {
            throw new \DomainException('No se puede registrar el resultado de un partido cancelado.');
        }
        $resultChanged = (int) ($match['home_score'] ?? -1) !== $homeScore
            || (int) ($match['away_score'] ?? -1) !== $awayScore
            || ($match['home_penalties'] === null ? null : (int) $match['home_penalties']) !== $homePenalties
            || ($match['away_penalties'] === null ? null : (int) $match['away_penalties']) !== $awayPenalties;
        if ($resultChanged && $match['status'] === 'COMPLETED' && $this->hasDependentPhase($pdo, $match)) {
            throw new \DomainException('No se puede corregir el resultado porque ya existen fases o partidos posteriores que dependen de este ganador.');
        }
        $isKnockout = $match['stage_type'] === 'KNOCKOUT';
        if ($homeScore === $awayScore) {
            if (!$isKnockout && ($homePenalties !== null || $awayPenalties !== null)) {
                throw new \DomainException('Los penales solo se registran para partidos de eliminación directa.');
            }
            if ($isKnockout && ($homePenalties === null || $awayPenalties === null || $homePenalties === $awayPenalties)) {
                throw new \DomainException('Un empate en eliminación directa requiere penales distintos para determinar al ganador.');
            }
        } elseif ($homePenalties !== null || $awayPenalties !== null) {
            throw new \DomainException('Los penales solo se registran cuando el marcador del partido termina empatado.');
        }

        $update = $pdo->prepare('UPDATE matches SET home_score = :home_score, away_score = :away_score, home_penalties = :home_penalties, away_penalties = :away_penalties, status = :status WHERE id = :id');
        $update->execute([
            ':home_score' => $homeScore,
            ':away_score' => $awayScore,
            ':home_penalties' => $homePenalties,
            ':away_penalties' => $awayPenalties,
            ':status' => 'COMPLETED',
            ':id' => $matchId,
        ]);
        $history = $pdo->prepare('INSERT INTO match_result_history (match_id, old_home_score, old_away_score, new_home_score, new_away_score, changed_by, reason) VALUES (:match_id, :old_home_score, :old_away_score, :new_home_score, :new_away_score, :changed_by, :reason)');
        $history->execute([
            ':match_id' => $matchId,
            ':old_home_score' => $match['home_score'],
            ':old_away_score' => $match['away_score'],
            ':new_home_score' => $homeScore,
            ':new_away_score' => $awayScore,
            ':changed_by' => $changedBy,
            ':reason' => $reason,
        ]);
        if ($changedBy !== null && $changedByRole !== null) {
            (new AdminAuditRepository())->record($pdo, [
                'actor_user_id' => $changedBy,
                'actor_role' => $changedByRole,
                'action' => 'matches.result.record',
                'entity_type' => 'match',
                'entity_id' => $matchId,
                'tournament_id' => (int) $match['tournament_id'],
                'reason' => $reason,
                'old_values' => [
                    'home_score' => $match['home_score'],
                    'away_score' => $match['away_score'],
                    'home_penalties' => $match['home_penalties'],
                    'away_penalties' => $match['away_penalties'],
                ],
                'new_values' => [
                    'home_score' => $homeScore,
                    'away_score' => $awayScore,
                    'home_penalties' => $homePenalties,
                    'away_penalties' => $awayPenalties,
                ],
                'ip_address' => $ipAddress,
            ]);
        }

        $roundId = (int) $match['round_id'];
        $roundState = $pdo->prepare('SELECT COUNT(*) AS total, SUM(status IN (:completed, :bye)) AS completed FROM matches WHERE round_id = :round_id');
        $roundState->execute([':completed' => 'COMPLETED', ':bye' => 'BYE', ':round_id' => $roundId]);
        $counts = $roundState->fetch();
        $roundStatus = (int) $counts['total'] === (int) $counts['completed'] ? 'COMPLETED' : 'IN_PROGRESS';
        $pdo->prepare('UPDATE rounds SET status = :status WHERE id = :id')->execute([':status' => $roundStatus, ':id' => $roundId]);

        return [
            'match_id' => $matchId,
            'previous' => ['home_score' => $match['home_score'], 'away_score' => $match['away_score']],
            'current' => ['home_score' => $homeScore, 'away_score' => $awayScore],
        ];
    }

    private function hasDependentPhase(\PDO $pdo, array $match): bool
    {
        if (in_array($match['stage_type'], ['GROUP_STAGE', 'LEAGUE'], true)) {
            $stmt = $pdo->prepare('SELECT id FROM tournament_stages WHERE tournament_id = :tournament_id AND stage_type = :stage_type LIMIT 1');
            $stmt->execute([':tournament_id' => $match['tournament_id'], ':stage_type' => 'KNOCKOUT']);
            return $stmt->fetchColumn() !== false;
        }
        if ($match['stage_type'] !== 'KNOCKOUT') {
            return false;
        }
        if ($match['tournament_status'] === 'COMPLETED') {
            return true;
        }

        $stmt = $pdo->prepare('SELECT id FROM rounds WHERE stage_id = :stage_id AND round_number > :round_number LIMIT 1');
        $stmt->execute([':stage_id' => $match['stage_id'], ':round_number' => $match['round_number']]);
        return $stmt->fetchColumn() !== false;
    }

    private function lockTournament(\PDO $pdo, int $tournamentId): void
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $stmt = $pdo->prepare('SELECT id FROM tournaments WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $tournamentId]);
    }
}

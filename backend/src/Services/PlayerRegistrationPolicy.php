<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class PlayerRegistrationPolicy
{
    public static function defaultWindow(): array
    {
        $start = new \DateTimeImmutable('now');

        return [
            'player_registration_start_at' => $start->format('Y-m-d H:i:s'),
            'player_registration_end_at' => $start->modify('+20 days')->format('Y-m-d H:i:s'),
        ];
    }

    public function assertAllowed(PDO $pdo, int $tournamentId, bool $isOverride = false): array
    {
        $lockClause = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $tournamentQuery = $pdo->prepare(
            'SELECT player_registration_enabled, player_registration_start_at, player_registration_end_at,
                    player_registration_deadline
             FROM tournaments
             WHERE id = :id' . $lockClause,
        );
        $tournamentQuery->execute([':id' => $tournamentId]);
        $tournament = $tournamentQuery->fetch();
        if ($tournament === false) {
            throw new \DomainException('Torneo no encontrado.');
        }

        $roundQuery = $pdo->prepare(
            "SELECT COALESCE(MAX(
                CASE
                    WHEN m.status IN ('IN_PROGRESS', 'COMPLETED')
                      OR (m.status = 'SCHEDULED' AND m.match_date IS NOT NULL AND m.match_date <= CURRENT_DATE)
                    THEN r.round_number
                END
             ), 0)
             FROM rounds r
             JOIN tournament_stages s ON s.id = r.stage_id
             JOIN matches m ON m.round_id = r.id
             WHERE s.tournament_id = :tournament_id
               AND s.stage_type IN ('LEAGUE', 'GROUP_STAGE')
               AND m.is_bye = 0",
        );
        $roundQuery->execute([':tournament_id' => $tournamentId]);
        $currentRound = (int) $roundQuery->fetchColumn();

        $state = [
            'current_round' => $currentRound,
            'deadline_round' => (int) $tournament['player_registration_deadline'],
            'enabled' => (bool) $tournament['player_registration_enabled'],
            'starts_at' => $tournament['player_registration_start_at'],
            'ends_at' => $tournament['player_registration_end_at'],
        ];
        if ($isOverride) {
            return $state;
        }
        if (!$state['enabled']) {
            throw new \DomainException('La inscripción de jugadores está deshabilitada.');
        }
        if ($state['starts_at'] !== null && strtotime((string) $state['starts_at']) > time()) {
            throw new \DomainException('La inscripción de jugadores aún no está abierta.');
        }
        if ($state['ends_at'] !== null && strtotime((string) $state['ends_at']) < time()) {
            throw new \DomainException('La inscripción de jugadores está fuera del periodo configurado.');
        }
        if ($currentRound > $state['deadline_round']) {
            throw new \DomainException('Se alcanzó la fecha límite por jornada para registrar jugadores.');
        }

        return $state;
    }
}

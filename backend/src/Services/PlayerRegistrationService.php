<?php

declare(strict_types=1);

namespace App\Services;

use App\Middleware\AuthorizationService;
use App\Repositories\TournamentRepository;

final class PlayerRegistrationService
{
    public function __construct(private readonly TournamentRepository $repository = new TournamentRepository())
    {
    }

    public function registerWithOverride(
        int $tournamentId,
        int $teamId,
        int $playerId,
        ?int $jerseyNumber,
        array $actor,
        string $reason,
        ?string $ipAddress,
    ): bool {
        $actorId = $this->assertOverrideAccess($actor);
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('El motivo del registro excepcional es obligatorio.');
        }

        $created = $this->repository->addPlayerToTournament(
            $tournamentId,
            $teamId,
            $playerId,
            $jerseyNumber,
            [
                'actor_user_id' => $actorId,
                'actor_role' => (string) $actor['role'],
                'reason' => trim($reason),
                'ip_address' => $ipAddress,
            ],
        );
        if (!$created) {
            throw new \DomainException('El jugador ya está inscrito en este torneo.');
        }
        return true;
    }

    public function registerAsSuperadmin(
        int $tournamentId,
        int $teamId,
        int $playerId,
        ?int $jerseyNumber,
        array $actor,
        ?string $ipAddress,
    ): bool {
        if (($actor['role'] ?? null) !== 'superadmin') {
            throw new \App\Core\HttpException(403, 'No tienes permiso para omitir el periodo de inscripción.');
        }
        $actorId = $this->assertOverrideAccess($actor);

        return $this->repository->addPlayerToTournament(
            $tournamentId,
            $teamId,
            $playerId,
            $jerseyNumber,
            [
                'actor_user_id' => $actorId,
                'actor_role' => 'superadmin',
                'reason' => 'Inscripción realizada por superadmin; se omiten las restricciones de periodo.',
                'ip_address' => $ipAddress,
            ],
        );
    }

    public function registerManyAsSuperadmin(
        int $tournamentId,
        int $teamId,
        array $playerIds,
        array $actor,
        ?string $ipAddress,
    ): int {
        if (($actor['role'] ?? null) !== 'superadmin') {
            throw new \App\Core\HttpException(403, 'No tienes permiso para omitir el periodo de inscripción.');
        }
        $actorId = $this->assertOverrideAccess($actor);

        return $this->repository->addPlayersToTournament(
            $tournamentId,
            $teamId,
            $playerIds,
            [
                'actor_user_id' => $actorId,
                'actor_role' => 'superadmin',
                'reason' => 'Inscripción realizada por superadmin; se omiten las restricciones de periodo.',
                'ip_address' => $ipAddress,
            ],
        );
    }

    private function assertOverrideAccess(array $actor): int
    {
        $actorId = filter_var($actor['sub'] ?? null, FILTER_VALIDATE_INT);
        if (
            $actorId === false
            || $actorId < 1
            || !AuthorizationService::hasCapability($actor, 'players.registration.override_deadline')
        ) {
            throw new \App\Core\HttpException(403, 'No tienes permiso para registrar jugadores fuera del periodo.');
        }

        return $actorId;
    }
}

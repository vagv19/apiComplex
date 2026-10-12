<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TournamentRepository;

class RosterService
{
    public function __construct(private readonly TournamentRepository $repository = new TournamentRepository())
    {
    }

    public function registerPlayer(int $tournamentId, int $teamId, int $playerId): bool
    {
        return $this->repository->addPlayerToTournament($tournamentId, $teamId, $playerId);
    }

    public function toggleRegistration(int $tournamentId, bool $enabled): ?array
    {
        return $this->repository->update($tournamentId, ['allow_player_registration' => $enabled]);
    }
}

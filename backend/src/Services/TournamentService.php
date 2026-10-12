<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TournamentRepository;

class TournamentService
{
    public function __construct(private readonly TournamentRepository $repository = new TournamentRepository())
    {
    }

    public function createTournament(array $data): array
    {
        return $this->repository->create($data);
    }
}

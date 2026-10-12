<?php

declare(strict_types=1);

namespace App\Models;

class Tournament
{
    public ?int $id = null;
    public ?string $name = null;
    public ?int $categoryId = null;
    public string $format = 'LEAGUE';
    public string $status = 'DRAFT';
    public ?string $startDate = null;
    public ?string $endDate = null;
    public int $pointsForWin = 3;
    public int $pointsForDraw = 1;
    public int $pointsForLoss = 0;
    public int $roundsCount = 10;
    public int $qualifiedTeams = 8;
    public ?string $tieBreakers = null;
    public int $playerRegistrationDeadlineRound = 4;
    public bool $allowPlayerRegistration = true;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;
}

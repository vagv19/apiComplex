<?php

declare(strict_types=1);

namespace App\Services;

class QualificationService
{
    public function buildKnockout(int $tournamentId, array $participants): array
    {
        $slots = [];
        foreach ($participants as $participant) {
            $slots[] = $participant;
        }

        $rounds = [];
        while (count($slots) > 1) {
            $round = [];
            for ($i = 0; $i < count($slots); $i += 2) {
                if (!isset($slots[$i + 1])) {
                    $round[] = ['home' => $slots[$i], 'away' => null];
                    continue;
                }
                $round[] = ['home' => $slots[$i], 'away' => $slots[$i + 1]];
            }
            $rounds[] = $round;
            $slots = array_map(static fn($item) => $item['home'], $round);
        }

        return ['tournament_id' => $tournamentId, 'rounds' => $rounds];
    }
}

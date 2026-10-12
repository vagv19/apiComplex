<?php

declare(strict_types=1);

namespace App\Services;

final class ScheduleAlgorithms
{
    public function roundRobin(array $teamIds): array
    {
        $teamIds = array_values(array_unique(array_map('intval', $teamIds)));
        if (count($teamIds) < 2) {
            throw new \DomainException('Cada grupo debe tener al menos dos equipos.');
        }

        $participants = $teamIds;
        if (count($participants) % 2 !== 0) {
            $participants[] = null;
        }

        $rounds = [];
        $roundCount = count($participants) - 1;
        for ($round = 0; $round < $roundCount; $round++) {
            $pairs = [];
            for ($pair = 0; $pair < intdiv(count($participants), 2); $pair++) {
                $pairs[] = [
                    'home' => $participants[$pair],
                    'away' => $participants[count($participants) - 1 - $pair],
                ];
            }
            $rounds[] = $pairs;

            $last = array_pop($participants);
            array_splice($participants, 1, 0, [$last]);
        }

        return $rounds;
    }

    public function knockoutPairs(array $seededTeamIds): array
    {
        $seededTeamIds = array_values(array_unique(array_map('intval', $seededTeamIds)));
        if (count($seededTeamIds) < 2) {
            throw new \DomainException('Se requieren al menos dos equipos para crear un cuadro.');
        }

        $size = 1;
        while ($size < count($seededTeamIds)) {
            $size *= 2;
        }

        $seedPositions = $this->seedPositions($size);
        $slots = [];
        foreach ($seedPositions as $seedNumber) {
            $slots[] = $seededTeamIds[$seedNumber - 1] ?? null;
        }

        $pairs = [];
        for ($index = 0; $index < $size; $index += 2) {
            $pairs[] = ['home' => $slots[$index], 'away' => $slots[$index + 1]];
        }

        return $pairs;
    }

    private function seedPositions(int $size): array
    {
        $positions = [1, 2];
        for ($currentSize = 2; $currentSize < $size; $currentSize *= 2) {
            $expanded = [];
            foreach ($positions as $position) {
                $expanded[] = $position;
                $expanded[] = ($currentSize * 2) + 1 - $position;
            }
            $positions = $expanded;
        }

        return $positions;
    }
}

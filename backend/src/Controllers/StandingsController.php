<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\StandingsService;

class StandingsController
{
    public function tournament(Request $request, array $params): void
    {
        $rows = (new StandingsService())->calculate((int) $params['id']);
        if ($rows === [] && !$this->tournamentExists((int) $params['id'])) {
            Response::json(404, false, null, 'Torneo no encontrado.');
            return;
        }
        Response::json(200, true, $rows, 'Tabla del torneo.');
    }

    public function group(Request $request, array $params): void
    {
        $groupId = (int) $params['id'];
        $stmt = Database::getConnection()->prepare('SELECT s.tournament_id FROM tournament_groups g JOIN tournament_stages s ON s.id = g.stage_id WHERE g.id = :id');
        $stmt->execute([':id' => $groupId]);
        $tournamentId = $stmt->fetchColumn();
        if ($tournamentId === false) {
            Response::json(404, false, null, 'Grupo no encontrado.');
            return;
        }
        $rows = (new StandingsService())->calculate((int) $tournamentId, $groupId);
        Response::json(200, true, $rows, 'Tabla del grupo.');
    }

    private function tournamentExists(int $tournamentId): bool
    {
        $stmt = Database::getConnection()->prepare('SELECT id FROM tournaments WHERE id = :id');
        $stmt->execute([':id' => $tournamentId]);
        return $stmt->fetchColumn() !== false;
    }
}

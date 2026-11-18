<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class DashboardRepository
{
    public function overview(): array
    {
        $pdo = Database::getConnection();
        $counts = $pdo->query(
            'SELECT
                (SELECT COUNT(*) FROM tournaments) AS tournaments,
                (SELECT COUNT(*) FROM categories WHERE is_active = 1) AS categories,
                (SELECT COUNT(*) FROM teams WHERE is_active = 1) AS teams,
                (SELECT COUNT(*) FROM players WHERE is_active = 1) AS players',
        )->fetch();

        $recentTournaments = $pdo->query(
            'SELECT t.id, t.category_id, c.name AS category_name, t.name,
                    t.tournament_type AS format, t.status, t.created_at
             FROM tournaments t
             LEFT JOIN categories c ON c.id = t.category_id
             ORDER BY t.created_at DESC, t.id DESC
             LIMIT 5',
        )->fetchAll();

        return [
            'counts' => array_map('intval', $counts),
            'recent_tournaments' => $recentTournaments,
        ];
    }
}

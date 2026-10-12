<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class TeamRepository
{
    public function list(int $page = 1, int $limit = 15, ?string $search = null): array
    {
        $offset = ($page - 1) * $limit;
        $pdo = Database::getConnection();
        $query = 'SELECT * FROM teams WHERE is_active = 1';
        $params = [];
        if ($search !== null && trim($search) !== '') {
            $query .= ' AND (name LIKE :search_name OR short_name LIKE :search_short)';
            $params[':search_name'] = '%' . $search . '%';
            $params[':search_short'] = '%' . $search . '%';
        }
        $query .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE is_active = 1' . ($search !== null && trim($search) !== '' ? ' AND (name LIKE :search_name OR short_name LIKE :search_short)' : ''));
        if ($search !== null && trim($search) !== '') {
            $countStmt->bindValue(':search_name', '%' . $search . '%');
            $countStmt->bindValue(':search_short', '%' . $search . '%');
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        return ['items' => $rows, 'page' => $page, 'limit' => $limit, 'total' => $total];
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT * FROM teams WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $stmt = Database::getConnection()->prepare('INSERT INTO teams (name, short_name, logo_url, contact_name, contact_phone, is_active) VALUES (:name, :short_name, :logo_url, :contact_name, :contact_phone, 1)');
        $stmt->execute([
            ':name' => $data['name'],
            ':short_name' => $data['short_name'] ?? null,
            ':logo_url' => $data['logo_url'] ?? null,
            ':contact_name' => $data['contact_name'] ?? null,
            ':contact_phone' => $data['contact_phone'] ?? null,
        ]);
        return $this->findById((int) Database::getConnection()->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $current = $this->findById($id);
        if ($current === null) {
            return null;
        }
        $data = array_merge($current, $data);
        $stmt = Database::getConnection()->prepare('UPDATE teams SET name = :name, short_name = :short_name, logo_url = :logo_url, contact_name = :contact_name, contact_phone = :contact_phone, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            ':name' => $data['name'],
            ':short_name' => $data['short_name'] ?? null,
            ':logo_url' => $data['logo_url'] ?? null,
            ':contact_name' => $data['contact_name'] ?? null,
            ':contact_phone' => $data['contact_phone'] ?? null,
            ':id' => $id,
        ]);
        return $this->findById($id);
    }

    public function softDelete(int $id): bool
    {
        $stmt = Database::getConnection()->prepare('UPDATE teams SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function fetchTournamentHistory(int $teamId): array
    {
        $stmt = Database::getConnection()->prepare('SELECT tt.*, t.name AS tournament_name FROM tournament_teams tt JOIN tournaments t ON t.id = tt.tournament_id WHERE tt.team_id = :team_id ORDER BY tt.id DESC');
        $stmt->execute([':team_id' => $teamId]);
        return $stmt->fetchAll();
    }

    public function fetchTournamentRoster(int $teamId, int $tournamentId): array
    {
        $stmt = Database::getConnection()->prepare('SELECT tp.*, tt.team_id, p.first_name, p.last_name FROM tournament_players tp JOIN tournament_teams tt ON tt.id = tp.tournament_team_id JOIN players p ON p.id = tp.player_id WHERE tt.team_id = :team_id AND tp.tournament_id = :tournament_id');
        $stmt->execute([':team_id' => $teamId, ':tournament_id' => $tournamentId]);
        return $stmt->fetchAll();
    }
}

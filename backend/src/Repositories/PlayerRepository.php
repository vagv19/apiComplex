<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class PlayerRepository
{
    public function list(int $page = 1, int $limit = 15, ?string $search = null): array
    {
        $offset = ($page - 1) * $limit;
        $pdo = Database::getConnection();
        $query = 'SELECT * FROM players WHERE is_active = 1';
        $params = [];
        if ($search !== null && trim($search) !== '') {
            $query .= ' AND (first_name LIKE :search_first OR last_name LIKE :search_last)';
            $params[':search_first'] = '%' . $search . '%';
            $params[':search_last'] = '%' . $search . '%';
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

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM players WHERE is_active = 1' . ($search !== null && trim($search) !== '' ? ' AND (first_name LIKE :search_first OR last_name LIKE :search_last)' : ''));
        if ($search !== null && trim($search) !== '') {
            $countStmt->bindValue(':search_first', '%' . $search . '%');
            $countStmt->bindValue(':search_last', '%' . $search . '%');
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        return ['items' => $rows, 'page' => $page, 'limit' => $limit, 'total' => $total];
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare('SELECT * FROM players WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): array
    {
        $stmt = Database::getConnection()->prepare('INSERT INTO players (first_name, last_name, birth_date, photo_url, phone, email, is_active) VALUES (:first_name, :last_name, :birth_date, :photo_url, :phone, :email, 1)');
        $stmt->execute([
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':birth_date' => $data['birth_date'] ?? null,
            ':photo_url' => $data['photo_url'] ?? null,
            ':phone' => $data['phone'] ?? null,
            ':email' => $data['email'] ?? null,
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
        $stmt = Database::getConnection()->prepare('UPDATE players SET first_name = :first_name, last_name = :last_name, birth_date = :birth_date, photo_url = :photo_url, phone = :phone, email = :email, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':birth_date' => $data['birth_date'] ?? null,
            ':photo_url' => $data['photo_url'] ?? null,
            ':phone' => $data['phone'] ?? null,
            ':email' => $data['email'] ?? null,
            ':id' => $id,
        ]);
        return $this->findById($id);
    }

    public function setPhotoIfEmpty(int $id, string $filename): bool
    {
        $stmt = Database::getConnection()->prepare(
            "UPDATE players
             SET photo_url = :photo_url, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND is_active = 1 AND (photo_url IS NULL OR photo_url = '')"
        );
        $stmt->execute([
            ':photo_url' => $filename,
            ':id' => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function replacePhotoIfUnchanged(int $id, string $expectedFilename, string $filename): bool
    {
        $stmt = Database::getConnection()->prepare(
            'UPDATE players
             SET photo_url = :photo_url, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND is_active = 1 AND photo_url = :expected_photo_url',
        );
        $stmt->execute([
            ':photo_url' => $filename,
            ':id' => $id,
            ':expected_photo_url' => $expectedFilename,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function isRegisteredToDirector(int $playerId, int $directorUserId): bool
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT 1
             FROM tournament_players tp
             JOIN tournament_teams tt ON tt.id = tp.tournament_team_id
             JOIN tournament_team_coaches c ON c.tournament_team_id = tt.id
             JOIN users u ON u.id = c.user_id
             WHERE tp.player_id = :player_id
               AND tp.status = 'ACTIVE'
               AND tt.status = 'ACTIVE'
               AND c.user_id = :director_user_id
               AND c.is_active = 1
               AND u.role = 'director_tecnico'
               AND u.is_active = 1
             LIMIT 1"
        );
        $stmt->execute([
            ':player_id' => $playerId,
            ':director_user_id' => $directorUserId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    public function softDelete(int $id): bool
    {
        $stmt = Database::getConnection()->prepare('UPDATE players SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function tournamentHistory(int $playerId): array
    {
        $stmt = Database::getConnection()->prepare('SELECT tp.*, tt.team_id, t.name AS tournament_name FROM tournament_players tp JOIN tournament_teams tt ON tt.id = tp.tournament_team_id JOIN tournaments t ON t.id = tp.tournament_id WHERE tp.player_id = :player_id ORDER BY tp.id DESC');
        $stmt->execute([':player_id' => $playerId]);
        return $stmt->fetchAll();
    }
}

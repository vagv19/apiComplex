<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class CategoryRepository
{
    public function list(int $page = 1, int $limit = 15): array
    {
        $offset = ($page - 1) * $limit;
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE is_active = 1 ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $total = (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE is_active = 1')->fetchColumn();
        return ['items' => $rows, 'page' => $page, 'limit' => $limit, 'total' => $total];
    }

    public function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('INSERT INTO categories (name, description, is_active) VALUES (:name, :description, 1)');
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
        ]);
        return $this->findById((int) $pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $stmt = Database::getConnection()->prepare('UPDATE categories SET name = :name, description = :description WHERE id = :id');
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
            ':id' => $id,
        ]);
        return $this->findById($id);
    }

    public function softDelete(int $id): bool
    {
        $stmt = Database::getConnection()->prepare('UPDATE categories SET is_active = 0 WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}

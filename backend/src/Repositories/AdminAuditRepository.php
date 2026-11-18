<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AdminAuditRepository
{
    public function record(PDO $pdo, array $entry): void
    {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('La auditoría debe guardarse dentro de la transacción de la operación.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit_log
                (actor_user_id, actor_role, action, entity_type, entity_id, tournament_id, reason,
                 old_values, new_values, ip_address)
             VALUES
                (:actor_user_id, :actor_role, :action, :entity_type, :entity_id, :tournament_id, :reason,
                 :old_values, :new_values, :ip_address)',
        );
        $stmt->execute([
            ':actor_user_id' => $entry['actor_user_id'],
            ':actor_role' => $entry['actor_role'],
            ':action' => $entry['action'],
            ':entity_type' => $entry['entity_type'],
            ':entity_id' => $entry['entity_id'] === null ? null : (string) $entry['entity_id'],
            ':tournament_id' => $entry['tournament_id'],
            ':reason' => $entry['reason'] ?? null,
            ':old_values' => isset($entry['old_values'])
                ? json_encode($entry['old_values'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : null,
            ':new_values' => isset($entry['new_values'])
                ? json_encode($entry['new_values'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : null,
            ':ip_address' => $entry['ip_address'] ?? null,
        ]);
    }

    public function paginate(int $page, int $limit): array
    {
        $pdo = \App\Config\Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, actor_user_id, actor_role, action, entity_type, entity_id, tournament_id,
                    reason, old_values, new_values, ip_address, created_at
             FROM admin_audit_log
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            foreach (['old_values', 'new_values'] as $field) {
                if (is_string($item[$field])) {
                    $decoded = json_decode($item[$field], true);
                    $item[$field] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
                }
            }
        }
        unset($item);

        return [
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn(),
        ];
    }
}

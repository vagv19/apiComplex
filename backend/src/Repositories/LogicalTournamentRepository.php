<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Services\PlayerRegistrationPolicy;
use PDO;
use PDOException;

final class LogicalTournamentRepository
{
    public function list(int $page, int $limit): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT logical_tournaments.*
             FROM (
                 SELECT CONCAT('group-', tm.id) AS logical_id, 'group' AS entity_type,
                        tm.id AS entity_id, tm.name, tm.description, tm.season,
                        tm.start_date, tm.end_date, tm.status, tm.created_at
                 FROM tournament_masters tm
                 UNION ALL
                 SELECT CONCAT('single-', t.id) AS logical_id, 'single' AS entity_type,
                        t.id AS entity_id, t.name, NULL AS description, NULL AS season,
                        t.start_date, t.end_date, t.status, t.created_at
                 FROM tournaments t
                 WHERE t.master_tournament_id IS NULL
             ) AS logical_tournaments
             ORDER BY logical_tournaments.created_at DESC,
                      logical_tournaments.entity_type ASC,
                      logical_tournaments.entity_id DESC
             LIMIT :limit OFFSET :offset",
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        $groupIds = [];
        $singleIds = [];
        foreach ($items as $item) {
            if ($item['entity_type'] === 'group') {
                $groupIds[] = (int) $item['entity_id'];
            } else {
                $singleIds[] = (int) $item['entity_id'];
            }
        }
        $categories = $this->fetchCompetitionRows($pdo, $groupIds, $singleIds);
        $categoriesByLogicalId = [];
        foreach ($categories as $category) {
            $logicalId = $category['master_tournament_id'] === null
                ? 'single-' . (int) $category['tournament_id']
                : 'group-' . (int) $category['master_tournament_id'];
            $categoriesByLogicalId[$logicalId][] = $category;
        }

        $items = array_map(
            fn (array $item): array => $this->present(
                $item,
                $categoriesByLogicalId[$item['logical_id']] ?? [],
            ),
            $items,
        );
        $total = (int) $pdo->query(
            'SELECT (SELECT COUNT(*) FROM tournament_masters)
                  + (SELECT COUNT(*) FROM tournaments WHERE master_tournament_id IS NULL)',
        )->fetchColumn();

        return ['items' => $items, 'page' => $page, 'limit' => $limit, 'total' => $total];
    }

    public function find(string $type, int $id): ?array
    {
        $pdo = Database::getConnection();
        if ($type === 'group') {
            $stmt = $pdo->prepare(
                'SELECT id AS entity_id, name, description, season, start_date, end_date,
                        status, created_at
                 FROM tournament_masters WHERE id = :id LIMIT 1',
            );
        } else {
            $stmt = $pdo->prepare(
                "SELECT id AS entity_id, name, NULL AS description, NULL AS season,
                        start_date, end_date, status, created_at
                 FROM tournaments
                 WHERE id = :id AND master_tournament_id IS NULL
                 LIMIT 1",
            );
        }
        $stmt->execute([':id' => $id]);
        $entity = $stmt->fetch();
        if ($entity === false) {
            return null;
        }

        $rows = $type === 'group'
            ? $this->fetchCompetitionRows($pdo, [$id], [])
            : $this->fetchCompetitionRows($pdo, [], [$id]);
        $entity['logical_id'] = ($type === 'group' ? 'group-' : 'single-') . $id;
        $entity['entity_type'] = $type;

        return $this->present($entity, $rows);
    }

    public function availableCategories(int $page, int $limit, ?int $masterId): array
    {
        $pdo = Database::getConnection();
        $where = 'c.is_active = 1';
        $params = [];
        if ($masterId !== null) {
            $where .= ' AND NOT EXISTS (
                SELECT 1 FROM tournaments existing
                WHERE existing.master_tournament_id = :master_id
                  AND existing.category_id = c.id
            )';
            $params[':master_id'] = $masterId;
        }

        $count = $pdo->prepare('SELECT COUNT(*) FROM categories c WHERE ' . $where);
        $count->execute($params);
        $stmt = $pdo->prepare(
            'SELECT c.id, c.name, c.description
             FROM categories c
             WHERE ' . $where . '
             ORDER BY c.name, c.id
             LIMIT :limit OFFSET :offset',
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $count->fetchColumn(),
        ];
    }

    public function create(array $details, array $rules, array $categoryIds, array $audit): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $categoryNames = $this->assertActiveCategories($pdo, $categoryIds);
            $master = $pdo->prepare(
                'INSERT INTO tournament_masters
                    (name, description, season, start_date, end_date, status)
                 VALUES (:name, :description, :season, :start_date, :end_date, :status)',
            );
            $master->execute([
                ':name' => $details['name'],
                ':description' => $details['description'],
                ':season' => $details['season'],
                ':start_date' => $details['start_date'],
                ':end_date' => $details['end_date'],
                ':status' => $details['status'],
            ]);
            $masterId = (int) $pdo->lastInsertId();

            $insert = $pdo->prepare(
                'INSERT INTO tournaments
                    (master_tournament_id, category_id, name, tournament_type, status,
                     start_date, end_date, points_win, points_draw, points_loss,
                     regular_rounds, qualification_count, player_registration_deadline,
                     player_registration_enabled, player_registration_start_at,
                     player_registration_end_at, rules)
                 VALUES
                    (:master_id, :category_id, :name, :format, :status, :start_date,
                     :end_date, :points_for_win, :points_for_draw, :points_for_loss,
                     :rounds_count, :qualified_teams, :registration_deadline,
                     :registration_enabled, :registration_start, :registration_end,
                     :tie_breakers)',
            );
            foreach ($categoryIds as $categoryId) {
                $insert->execute($this->competitionValues(
                    $masterId,
                    $categoryId,
                    $categoryNames[$categoryId],
                    $details,
                    $rules,
                ));
            }

            $logicalId = 'group-' . $masterId;
            $this->recordAudit($pdo, $audit, 'logical_tournaments.create', $logicalId, null, [
                'details' => $details,
                'rules' => $rules,
                'category_ids' => $categoryIds,
            ]);
            $pdo->commit();

            return $this->find('group', $masterId);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException && $this->isUniqueConflict($exception)) {
                throw new \DomainException('Una de las categorías ya está asociada a este torneo.', 0, $exception);
            }
            throw $exception;
        }
    }

    public function updateDetails(string $type, int $id, array $changes, array $audit): ?array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $current = $this->lockEntity($pdo, $type, $id);
            if ($current === null) {
                $pdo->rollBack();
                return null;
            }
            $this->assertCanPersistLegacyDetails($type, $changes);
            if ($type === 'group') {
                $startDate = $this->changedValue($changes, 'start_date', $current['start_date']);
                $endDate = $this->changedValue($changes, 'end_date', $current['end_date']);
                $this->assertValidDateRange($startDate, $endDate);
                $stmt = $pdo->prepare(
                    'UPDATE tournament_masters
                     SET name = :name, description = :description, season = :season,
                         start_date = :start_date, end_date = :end_date
                     WHERE id = :id',
                );
                $stmt->execute([
                    ':name' => $this->changedValue($changes, 'name', $current['name']),
                    ':description' => $this->changedValue($changes, 'description', $current['description']),
                    ':season' => $this->changedValue($changes, 'season', $current['season']),
                    ':start_date' => $this->changedValue($changes, 'start_date', $current['start_date']),
                    ':end_date' => $this->changedValue($changes, 'end_date', $current['end_date']),
                    ':id' => $id,
                ]);
                $children = $pdo->prepare(
                    'SELECT t.id, c.name AS category_name
                     FROM tournaments t
                     JOIN categories c ON c.id = t.category_id
                     WHERE t.master_tournament_id = :id',
                );
                $children->execute([':id' => $id]);
                $updateChild = $pdo->prepare(
                    'UPDATE tournaments
                     SET name = :name, start_date = :start_date, end_date = :end_date
                     WHERE id = :id AND master_tournament_id = :master_id',
                );
                $name = $this->changedValue($changes, 'name', $current['name']);
                foreach ($children->fetchAll() as $child) {
                    $updateChild->execute([
                        ':name' => $this->childTournamentName($name, (string) $child['category_name']),
                        ':start_date' => $this->changedValue($changes, 'start_date', $current['start_date']),
                        ':end_date' => $this->changedValue($changes, 'end_date', $current['end_date']),
                        ':id' => (int) $child['id'],
                        ':master_id' => $id,
                    ]);
                }
            } else {
                $startDate = $this->changedValue($changes, 'start_date', $current['start_date']);
                $endDate = $this->changedValue($changes, 'end_date', $current['end_date']);
                $this->assertValidDateRange($startDate, $endDate);
                $stmt = $pdo->prepare(
                    'UPDATE tournaments
                     SET name = :name, start_date = :start_date, end_date = :end_date
                     WHERE id = :id AND master_tournament_id IS NULL',
                );
                $stmt->execute([
                    ':name' => $this->changedValue($changes, 'name', $current['name']),
                    ':start_date' => $this->changedValue($changes, 'start_date', $current['start_date']),
                    ':end_date' => $this->changedValue($changes, 'end_date', $current['end_date']),
                    ':id' => $id,
                ]);
            }
            $oldValues = $type === 'group'
                ? array_intersect_key($current, array_flip(['name', 'description', 'season', 'start_date', 'end_date']))
                : array_intersect_key($current, array_flip(['name', 'start_date', 'end_date']));
            $this->recordAudit(
                $pdo,
                $audit,
                'logical_tournaments.update_details',
                $this->logicalId($type, $id),
                null,
                $changes,
                $oldValues,
            );
            $pdo->commit();

            return $this->find($type, $id);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateRules(
        string $type,
        int $id,
        array $rules,
        array $expectedRules,
        array $audit,
        bool $updateRegistrationWindow = true,
    ): ?array {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $entity = $this->lockEntity($pdo, $type, $id);
            if ($entity === null) {
                $pdo->rollBack();
                return null;
            }
            $rows = $this->fetchCompetitionRows(
                $pdo,
                $type === 'group' ? [$id] : [],
                $type === 'single' ? [$id] : [],
                true,
            );
            if ($rows === []) {
                throw new \DomainException('El torneo no tiene competencias asociadas para actualizar.');
            }
            if ($this->presentRules($rows[0]) !== $expectedRules) {
                throw new \DomainException('Las reglas cambiaron durante la operación; vuelve a consultar el torneo e inténtalo de nuevo.');
            }
            if (strtoupper((string) $rows[0]['tournament_type']) !== strtoupper((string) $rules['format'])) {
                $rounds = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM tournament_stages s
                     JOIN tournaments t ON t.id = s.tournament_id
                     WHERE ' . ($type === 'group' ? 't.master_tournament_id = :id' : 't.id = :id'),
                );
                $rounds->execute([':id' => $id]);
                if ((int) $rounds->fetchColumn() > 0) {
                    throw new \DomainException('No se puede cambiar el formato después de crear fases o jornadas.');
                }
            }
            $registrationWindowSql = $updateRegistrationWindow
                ? ', player_registration_start_at = :registration_start, player_registration_end_at = :registration_end'
                : '';
            $stmt = $pdo->prepare(
                'UPDATE tournaments
                 SET tournament_type = :format, status = :status, points_win = :points_for_win,
                     points_draw = :points_for_draw, points_loss = :points_for_loss,
                     regular_rounds = :rounds_count, qualification_count = :qualified_teams,
                     player_registration_deadline = :registration_deadline,
                     player_registration_enabled = :registration_enabled'
                     . $registrationWindowSql . ', rules = :tie_breakers
                 WHERE ' . ($type === 'group' ? 'master_tournament_id = :id' : 'id = :id AND master_tournament_id IS NULL'),
            );
            $stmt->execute($this->rulesValues($id, $rules, $updateRegistrationWindow));
            if ($type === 'group') {
                $masterStatus = match ($rules['status']) {
                    'DRAFT' => 'DRAFT',
                    'REGISTRATION', 'IN_PROGRESS' => 'ACTIVE',
                    'COMPLETED' => 'COMPLETED',
                    'CANCELLED' => 'CANCELLED',
                };
                $master = $pdo->prepare('UPDATE tournament_masters SET status = :status WHERE id = :id');
                $master->execute([':status' => $masterStatus, ':id' => $id]);
            }
            $this->recordAudit(
                $pdo,
                $audit,
                'logical_tournaments.update_rules',
                $this->logicalId($type, $id),
                null,
                $rules,
                $this->presentRules($rows[0]),
            );
            $pdo->commit();

            return $this->find($type, $id);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function addCategory(string $type, int $id, int $categoryId, array $audit): ?array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            if ($type !== 'group') {
                throw new \DomainException('Una competencia anterior de categoría única no se convierte automáticamente en un torneo lógico.');
            }
            $master = $this->lockEntity($pdo, $type, $id);
            if ($master === null) {
                $pdo->rollBack();
                return null;
            }
            if (in_array($master['status'], ['COMPLETED', 'CANCELLED'], true)) {
                throw new \DomainException('No se pueden agregar categorías a un torneo finalizado o retirado.');
            }
            $categoryNames = $this->assertActiveCategories($pdo, [$categoryId]);
            $rows = $this->fetchCompetitionRows($pdo, [$id], [], true);
            if ($rows === []) {
                throw new \DomainException('El torneo lógico no tiene una competencia base.');
            }
            if (!$this->settingsAreConsistent($rows)) {
                throw new \DomainException('Las configuraciones por categoría difieren; actualiza las reglas antes de agregar otra categoría.');
            }
            $base = $rows[0];
            $registrationWindow = PlayerRegistrationPolicy::defaultWindow();
            $values = [
                'format' => $base['tournament_type'],
                'status' => $base['status'],
                'points_for_win' => (int) $base['points_win'],
                'points_for_draw' => (int) $base['points_draw'],
                'points_for_loss' => (int) $base['points_loss'],
                'rounds_count' => (int) $base['regular_rounds'],
                'qualified_teams' => (int) $base['qualification_count'],
                'player_registration_deadline_round' => (int) $base['player_registration_deadline'],
                'allow_player_registration' => (bool) $base['player_registration_enabled'],
                'player_registration_start_at' => $registrationWindow['player_registration_start_at'],
                'player_registration_end_at' => $registrationWindow['player_registration_end_at'],
                'tie_breakers' => $base['rules'],
            ];
            $insert = $pdo->prepare(
                'INSERT INTO tournaments
                    (master_tournament_id, category_id, name, tournament_type, status,
                     start_date, end_date, points_win, points_draw, points_loss,
                     regular_rounds, qualification_count, player_registration_deadline,
                     player_registration_enabled, player_registration_start_at,
                     player_registration_end_at, rules)
                 VALUES
                    (:master_id, :category_id, :name, :format, :status, :start_date,
                     :end_date, :points_for_win, :points_for_draw, :points_for_loss,
                     :rounds_count, :qualified_teams, :registration_deadline,
                     :registration_enabled, :registration_start, :registration_end,
                     :tie_breakers)',
            );
            $insert->execute($this->competitionValues(
                $id,
                $categoryId,
                $categoryNames[$categoryId],
                [
                    'name' => $master['name'],
                    'start_date' => $master['start_date'],
                    'end_date' => $master['end_date'],
                ],
                $values,
            ));
            $tournamentId = (int) $pdo->lastInsertId();
            $this->recordAudit(
                $pdo,
                $audit,
                'logical_tournaments.category.add',
                $this->logicalId($type, $id),
                $tournamentId,
                ['category_id' => $categoryId],
                ['category_count' => count($rows)],
            );
            $pdo->commit();

            return $this->find($type, $id);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException && $this->isUniqueConflict($exception)) {
                throw new \DomainException('La categoría ya está asociada a este torneo.', 0, $exception);
            }
            throw $exception;
        }
    }

    public function removeCategory(string $type, int $id, int $categoryId, array $audit): ?array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            if ($type !== 'group') {
                throw new \DomainException('No se puede retirar la única categoría de una competencia anterior.');
            }
            $master = $this->lockEntity($pdo, $type, $id);
            if ($master === null) {
                $pdo->rollBack();
                return null;
            }
            if (in_array($master['status'], ['COMPLETED', 'CANCELLED'], true)) {
                throw new \DomainException('No se pueden retirar categorías de un torneo finalizado o retirado.');
            }
            $category = $pdo->prepare(
                'SELECT id, status FROM tournaments
                 WHERE master_tournament_id = :master_id AND category_id = :category_id
                 LIMIT 1 FOR UPDATE',
            );
            $category->execute([':master_id' => $id, ':category_id' => $categoryId]);
            $categoryRow = $category->fetch();
            if ($categoryRow === false) {
                $pdo->rollBack();
                return null;
            }
            $tournamentId = (int) $categoryRow['id'];
            if ($this->hasSportingActivity($pdo, 't.master_tournament_id = :id AND t.category_id = :category_id', [
                ':id' => $id,
                ':category_id' => $categoryId,
            ])) {
                throw new \DomainException('La categoría tiene actividad deportiva o participantes registrados y no puede retirarse.');
            }
            $count = $pdo->prepare('SELECT COUNT(*) FROM tournaments WHERE master_tournament_id = :id');
            $count->execute([':id' => $id]);
            if ((int) $count->fetchColumn() <= 1) {
                throw new \DomainException('El torneo debe conservar al menos una categoría; usa la opción de retiro del torneo completo.');
            }
            $detach = $pdo->prepare(
                "UPDATE tournaments SET master_tournament_id = NULL, status = 'CANCELLED' WHERE id = :id",
            );
            $detach->execute([':id' => $tournamentId]);
            $this->recordAudit(
                $pdo,
                $audit,
                'logical_tournaments.category.remove',
                $this->logicalId($type, $id),
                $tournamentId,
                ['category_id' => $categoryId, 'status' => 'CANCELLED'],
                [
                    'category_id' => $categoryId,
                    'master_tournament_id' => $id,
                    'status' => $categoryRow['status'],
                ],
            );
            $pdo->commit();

            return $this->find($type, $id);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function retire(string $type, int $id, array $audit): ?array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $entity = $this->lockEntity($pdo, $type, $id);
            if ($entity === null) {
                $pdo->rollBack();
                return null;
            }
            if ($entity['status'] === 'CANCELLED') {
                $pdo->commit();
                return $this->find($type, $id);
            }
            $childRows = $type === 'group'
                ? $this->fetchCompetitionRows($pdo, [$id], [], true)
                : $this->fetchCompetitionRows($pdo, [], [$id], true);
            $where = $type === 'group' ? 't.master_tournament_id = :id' : 't.id = :id';
            if ($this->hasSportingActivity($pdo, $where, [':id' => $id])) {
                throw new \DomainException('El torneo tiene actividad deportiva o participantes registrados y no puede retirarse.');
            }
            if ($type === 'group') {
                $master = $pdo->prepare("UPDATE tournament_masters SET status = 'CANCELLED' WHERE id = :id");
                $master->execute([':id' => $id]);
                $updateChildren = $pdo->prepare("UPDATE tournaments SET status = 'CANCELLED' WHERE master_tournament_id = :id");
                $updateChildren->execute([':id' => $id]);
            } else {
                $single = $pdo->prepare("UPDATE tournaments SET status = 'CANCELLED' WHERE id = :id AND master_tournament_id IS NULL");
                $single->execute([':id' => $id]);
            }
            $this->recordAudit(
                $pdo,
                $audit,
                'logical_tournaments.retire',
                $this->logicalId($type, $id),
                null,
                ['status' => 'CANCELLED'],
                [
                    'status' => $entity['status'],
                    'category_statuses' => array_map(
                        static fn (array $child): array => [
                            'tournament_id' => (int) $child['tournament_id'],
                            'status' => $child['status'],
                        ],
                        $childRows,
                    ),
                ],
            );
            $pdo->commit();

            return $this->find($type, $id);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function fetchCompetitionRows(PDO $pdo, array $masterIds, array $singleIds, bool $lock = false): array
    {
        $rows = [];
        if ($masterIds !== []) {
            [$in, $params] = $this->inClause($masterIds, 'master');
            $stmt = $pdo->prepare(
                'SELECT t.id AS tournament_id, t.master_tournament_id, t.category_id,
                        c.name AS category_name, t.name, t.tournament_type, t.status,
                        t.start_date, t.end_date, t.points_win, t.points_draw, t.points_loss,
                        t.regular_rounds, t.qualification_count, t.player_registration_deadline,
                        t.player_registration_enabled, t.player_registration_start_at,
                        t.player_registration_end_at, t.rules
                 FROM tournaments t
                 JOIN categories c ON c.id = t.category_id
                 WHERE t.master_tournament_id IN (' . $in . ')
                 ORDER BY t.master_tournament_id, t.category_id, t.id'
                 . ($lock ? ' FOR UPDATE' : ''),
            );
            $stmt->execute($params);
            $rows = [...$rows, ...$stmt->fetchAll()];
        }
        if ($singleIds !== []) {
            [$in, $params] = $this->inClause($singleIds, 'single');
            $stmt = $pdo->prepare(
                'SELECT t.id AS tournament_id, t.master_tournament_id, t.category_id,
                        c.name AS category_name, t.name, t.tournament_type, t.status,
                        t.start_date, t.end_date, t.points_win, t.points_draw, t.points_loss,
                        t.regular_rounds, t.qualification_count, t.player_registration_deadline,
                        t.player_registration_enabled, t.player_registration_start_at,
                        t.player_registration_end_at, t.rules
                 FROM tournaments t
                 JOIN categories c ON c.id = t.category_id
                 WHERE t.master_tournament_id IS NULL AND t.id IN (' . $in . ')
                 ORDER BY t.category_id, t.id'
                 . ($lock ? ' FOR UPDATE' : ''),
            );
            $stmt->execute($params);
            $rows = [...$rows, ...$stmt->fetchAll()];
        }

        return $rows;
    }

    private function present(array $entity, array $rows): array
    {
        $categories = [];
        foreach ($rows as $row) {
            $categories[] = [
                'tournament_id' => (int) $row['tournament_id'],
                'category_id' => (int) $row['category_id'],
                'category_name' => $row['category_name'],
                'tournament_name' => $row['name'],
                'status' => $row['status'],
                'player_registration_start_at' => $row['player_registration_start_at'],
                'player_registration_end_at' => $row['player_registration_end_at'],
            ];
        }
        $rules = $rows === [] ? null : $this->presentRules($rows[0]);
        $consistent = true;
        if (count($rows) > 1) {
            $first = $this->settingsFingerprint($rows[0]);
            foreach (array_slice($rows, 1) as $row) {
                if ($this->settingsFingerprint($row) !== $first) {
                    $consistent = false;
                    break;
                }
            }
        }

        return [
            'id' => $entity['logical_id'],
            'name' => $entity['name'],
            'description' => $entity['description'],
            'season' => $entity['season'],
            'start_date' => $entity['start_date'],
            'end_date' => $entity['end_date'],
            'status' => $entity['status'],
            'categories' => $categories,
            'category_count' => count($categories),
            'rules' => $rules,
            'rules_consistent' => $consistent,
            'created_at' => $entity['created_at'],
        ];
    }

    private function presentRules(array $row): array
    {
        return [
            'format' => $row['tournament_type'],
            'status' => $row['status'],
            'points_for_win' => (int) $row['points_win'],
            'points_for_draw' => (int) $row['points_draw'],
            'points_for_loss' => (int) $row['points_loss'],
            'rounds_count' => (int) $row['regular_rounds'],
            'qualified_teams' => (int) $row['qualification_count'],
            'player_registration_deadline_round' => (int) $row['player_registration_deadline'],
            'allow_player_registration' => (bool) $row['player_registration_enabled'],
            'player_registration_start_at' => $row['player_registration_start_at'],
            'player_registration_end_at' => $row['player_registration_end_at'],
            'tie_breakers' => $row['rules'],
        ];
    }

    private function settingsFingerprint(array $row): array
    {
        return [
            $row['tournament_type'],
            $row['status'],
            (int) $row['points_win'],
            (int) $row['points_draw'],
            (int) $row['points_loss'],
            (int) $row['regular_rounds'],
            (int) $row['qualification_count'],
            (int) $row['player_registration_deadline'],
            (int) $row['player_registration_enabled'],
            $row['rules'],
        ];
    }

    private function settingsAreConsistent(array $rows): bool
    {
        $fingerprint = $this->settingsFingerprint($rows[0]);
        foreach (array_slice($rows, 1) as $row) {
            if ($this->settingsFingerprint($row) !== $fingerprint) {
                return false;
            }
        }

        return true;
    }

    private function changedValue(array $changes, string $field, mixed $current): mixed
    {
        return array_key_exists($field, $changes) ? $changes[$field] : $current;
    }

    private function assertValidDateRange(mixed $start, mixed $end): void
    {
        if (is_string($start) && is_string($end) && $start > $end) {
            throw new \DomainException('La fecha de cierre no puede ser anterior a la fecha de inicio.');
        }
    }

    private function lockEntity(PDO $pdo, string $type, int $id): ?array
    {
        $table = $type === 'group' ? 'tournament_masters' : 'tournaments';
        $condition = $type === 'group' ? 'id = :id' : 'id = :id AND master_tournament_id IS NULL';
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE ' . $condition . ' LIMIT 1' . $lock);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function assertActiveCategories(PDO $pdo, array $categoryIds): array
    {
        [$in, $params] = $this->inClause($categoryIds, 'category');
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE is_active = 1 AND id IN (' . $in . ')' . $lock);
        $stmt->execute($params);
        $categoryNames = [];
        foreach ($stmt->fetchAll() as $category) {
            $categoryNames[(int) $category['id']] = (string) $category['name'];
        }
        if (count($categoryNames) !== count($categoryIds)) {
            throw new \DomainException('Una o más categorías no existen o están inactivas.');
        }

        return $categoryNames;
    }

    private function competitionValues(
        int $masterId,
        int $categoryId,
        string $categoryName,
        array $details,
        array $rules,
    ): array {
        return [
            ':master_id' => $masterId,
            ':category_id' => $categoryId,
            ':name' => $this->childTournamentName($details['name'], $categoryName),
            ':format' => $rules['format'],
            ':status' => $rules['status'],
            ':start_date' => $details['start_date'],
            ':end_date' => $details['end_date'],
            ':points_for_win' => $rules['points_for_win'],
            ':points_for_draw' => $rules['points_for_draw'],
            ':points_for_loss' => $rules['points_for_loss'],
            ':rounds_count' => $rules['rounds_count'],
            ':qualified_teams' => $rules['qualified_teams'],
            ':registration_deadline' => $rules['player_registration_deadline_round'],
            ':registration_enabled' => $rules['allow_player_registration'] ? 1 : 0,
            ':registration_start' => $rules['player_registration_start_at'],
            ':registration_end' => $rules['player_registration_end_at'],
            ':tie_breakers' => $this->storedTieBreakers($rules['tie_breakers']),
        ];
    }

    private function childTournamentName(string $tournamentName, string $categoryName): string
    {
        $name = $tournamentName . '-' . $categoryName;
        if (mb_strlen($name) > 150) {
            throw new \DomainException('El nombre combinado del torneo y la categoría no puede exceder 150 caracteres.');
        }

        return $name;
    }

    private function rulesValues(int $id, array $rules, bool $includeRegistrationWindow): array
    {
        $values = [
            ':format' => $rules['format'],
            ':status' => $rules['status'],
            ':points_for_win' => $rules['points_for_win'],
            ':points_for_draw' => $rules['points_for_draw'],
            ':points_for_loss' => $rules['points_for_loss'],
            ':rounds_count' => $rules['rounds_count'],
            ':qualified_teams' => $rules['qualified_teams'],
            ':registration_deadline' => $rules['player_registration_deadline_round'],
            ':registration_enabled' => $rules['allow_player_registration'] ? 1 : 0,
            ':tie_breakers' => $this->storedTieBreakers($rules['tie_breakers']),
            ':id' => $id,
        ];
        if ($includeRegistrationWindow) {
            $values[':registration_start'] = $rules['player_registration_start_at'];
            $values[':registration_end'] = $rules['player_registration_end_at'];
        }

        return $values;
    }

    private function storedTieBreakers(mixed $value): ?string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if (is_string($value) && $value !== '' && json_decode($value) === null && json_last_error() !== JSON_ERROR_NONE) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

    private function hasSportingActivity(PDO $pdo, string $condition, array $params): bool
    {
        $stmt = $pdo->prepare(
            'SELECT EXISTS (
                SELECT 1
                FROM tournaments t
                WHERE ' . $condition . '
                  AND (
                    EXISTS (SELECT 1 FROM tournament_teams tt WHERE tt.tournament_id = t.id)
                    OR EXISTS (SELECT 1 FROM tournament_players tp WHERE tp.tournament_id = t.id)
                    OR EXISTS (SELECT 1 FROM tournament_stages s WHERE s.tournament_id = t.id)
                    OR EXISTS (SELECT 1 FROM tournament_groups g JOIN tournament_stages s ON s.id = g.stage_id WHERE s.tournament_id = t.id)
                    OR EXISTS (SELECT 1 FROM rounds r JOIN tournament_stages s ON s.id = r.stage_id WHERE s.tournament_id = t.id)
                    OR EXISTS (SELECT 1 FROM matches m JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id WHERE s.tournament_id = t.id)
                    OR EXISTS (SELECT 1 FROM match_result_history h JOIN matches m ON m.id = h.match_id JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id WHERE s.tournament_id = t.id)
                    OR EXISTS (SELECT 1 FROM match_goals g JOIN matches m ON m.id = g.match_id JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id WHERE s.tournament_id = t.id)
                    OR EXISTS (SELECT 1 FROM match_player_attendance a JOIN matches m ON m.id = a.match_id JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id WHERE s.tournament_id = t.id)
                    OR EXISTS (SELECT 1 FROM match_disciplinary_events d JOIN matches m ON m.id = d.match_id JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id WHERE s.tournament_id = t.id)
                    OR EXISTS (SELECT 1 FROM player_suspensions ps JOIN match_disciplinary_events d ON d.id = ps.disciplinary_event_id JOIN matches m ON m.id = d.match_id JOIN rounds r ON r.id = m.round_id JOIN tournament_stages s ON s.id = r.stage_id WHERE s.tournament_id = t.id)
                  )
                LIMIT 1
            )',
        );
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    private function recordAudit(
        PDO $pdo,
        array $actor,
        string $action,
        string $logicalId,
        ?int $tournamentId,
        array $newValues,
        ?array $oldValues = null,
    ): void {
        $entry = [
            ...$actor,
            'action' => $action,
            'entity_type' => 'logical_tournament',
            'entity_id' => $logicalId,
            'tournament_id' => $tournamentId,
            'new_values' => $newValues,
        ];
        if ($oldValues !== null) {
            $entry['old_values'] = $oldValues;
        }
        (new AdminAuditRepository())->record($pdo, $entry);
    }

    private function assertCanPersistLegacyDetails(string $type, array $changes): void
    {
        if ($type === 'single' && (
            array_key_exists('description', $changes)
            || array_key_exists('season', $changes)
        )) {
            throw new \DomainException('Las competencias anteriores no tienen campos de descripción o temporada; esos datos no se guardaron.');
        }
    }

    private function logicalId(string $type, int $id): string
    {
        return ($type === 'group' ? 'group-' : 'single-') . $id;
    }

    private function inClause(array $ids, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $key = ':' . $prefix . $index;
            $placeholders[] = $key;
            $params[$key] = (int) $id;
        }

        return [implode(', ', $placeholders), $params];
    }

    private function isUniqueConflict(PDOException $exception): bool
    {
        $databaseMessage = strtolower((string) ($exception->errorInfo[2] ?? $exception->getMessage()));

        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            || str_contains($databaseMessage, 'duplicate entry');
    }
}

-- Reconcile report tables created by the earlier database schema with the
-- current API. Run before migration 004; report rows are preserved.

SET @has_goal_client_key = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'match_goals'
      AND COLUMN_NAME = 'client_key'
);
SET @ddl = IF(
    @has_goal_client_key = 0,
    'ALTER TABLE match_goals ADD COLUMN client_key CHAR(36) NULL',
    'DO 0'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE match_goals
SET client_key = UUID()
WHERE client_key IS NULL;

ALTER TABLE match_goals
    MODIFY COLUMN client_key CHAR(36) NOT NULL,
    MODIFY COLUMN notes TEXT NULL;

SET @has_goal_client_key_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'match_goals'
      AND INDEX_NAME = 'uq_match_goal_client_key'
);
SET @ddl = IF(
    @has_goal_client_key_index = 0,
    'ALTER TABLE match_goals ADD UNIQUE KEY uq_match_goal_client_key (match_id, client_key)',
    'DO 0'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_event_client_key = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'match_disciplinary_events'
      AND COLUMN_NAME = 'client_key'
);
SET @ddl = IF(
    @has_event_client_key = 0,
    'ALTER TABLE match_disciplinary_events ADD COLUMN client_key CHAR(36) NULL',
    'DO 0'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE match_disciplinary_events
SET client_key = UUID()
WHERE client_key IS NULL;

ALTER TABLE match_disciplinary_events
    MODIFY COLUMN client_key CHAR(36) NOT NULL,
    MODIFY COLUMN notes TEXT NULL;

SET @has_event_client_key_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'match_disciplinary_events'
      AND INDEX_NAME = 'uq_match_disciplinary_client_key'
);
SET @ddl = IF(
    @has_event_client_key_index = 0,
    'ALTER TABLE match_disciplinary_events ADD UNIQUE KEY uq_match_disciplinary_client_key (match_id, client_key)',
    'DO 0'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_suspension_reason_name = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_suspensions'
      AND COLUMN_NAME = 'expulsion_reason_name'
);
SET @ddl = IF(
    @has_suspension_reason_name = 0,
    'ALTER TABLE player_suspensions ADD COLUMN expulsion_reason_name VARCHAR(150) NULL',
    'DO 0'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE player_suspensions s
LEFT JOIN match_disciplinary_events d
    ON d.id = s.disciplinary_event_id
LEFT JOIN expulsion_reasons r
    ON r.id = d.expulsion_reason_id
SET s.expulsion_reason_name = COALESCE(r.name, 'Motivo histórico no disponible')
WHERE s.expulsion_reason_name IS NULL;

ALTER TABLE player_suspensions
    MODIFY COLUMN expulsion_reason_name VARCHAR(150) NOT NULL;

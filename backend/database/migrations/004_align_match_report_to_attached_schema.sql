-- Align existing report tables with the supplied feature schema while
-- preserving report rows. Apply after migration 003 when it was used.

SET @legacy_goal_team_fk = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'match_goals'
      AND COLUMN_NAME = 'credited_to_team_id'
      AND REFERENCED_TABLE_NAME = 'teams'
    LIMIT 1
);
SET @ddl = IF(
    @legacy_goal_team_fk IS NULL,
    'DO 0',
    CONCAT('ALTER TABLE match_goals DROP FOREIGN KEY `', REPLACE(@legacy_goal_team_fk, '`', '``'), '`')
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
    @legacy_goal_team_fk IS NULL,
    'DO 0',
    'UPDATE match_goals g
     JOIN matches m ON m.id = g.match_id
     JOIN rounds r ON r.id = m.round_id
     JOIN tournament_stages st ON st.id = r.stage_id
     JOIN tournament_teams tt
       ON tt.tournament_id = st.tournament_id
      AND tt.team_id = g.credited_to_team_id
     SET g.credited_to_team_id = tt.id'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_tournament_team_fk = (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'match_goals'
      AND COLUMN_NAME = 'credited_to_team_id'
      AND REFERENCED_TABLE_NAME = 'tournament_teams'
);
SET @ddl = IF(
    @has_tournament_team_fk = 0,
    'ALTER TABLE match_goals
     ADD CONSTRAINT fk_goal_credited_team
     FOREIGN KEY (credited_to_team_id) REFERENCES tournament_teams (id)',
    'DO 0'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

ALTER TABLE expulsion_reasons
    MODIFY COLUMN name VARCHAR(150) NOT NULL;

ALTER TABLE player_suspensions
    MODIFY COLUMN expulsion_reason_name VARCHAR(150) NOT NULL,
    MODIFY COLUMN status ENUM('ACTIVE','COMPLETED','OVERRIDDEN') NOT NULL DEFAULT 'ACTIVE';

SET @has_suspension_notes = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_suspensions'
      AND COLUMN_NAME = 'notes'
);
SET @ddl = IF(
    @has_suspension_notes = 0,
    'ALTER TABLE player_suspensions ADD COLUMN notes VARCHAR(500) NULL',
    'DO 0'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @suspension_status_check = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_suspensions'
      AND CONSTRAINT_TYPE = 'CHECK'
      AND CONSTRAINT_NAME = 'chk_suspension_status'
    LIMIT 1
);
SET @ddl = IF(
    @suspension_status_check IS NULL,
    'DO 0',
    'ALTER TABLE player_suspensions DROP CHECK chk_suspension_status'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_disciplinary_check = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'match_disciplinary_events'
      AND CONSTRAINT_TYPE = 'CHECK'
      AND CONSTRAINT_NAME = 'chk_disciplinary_expulsion_reason'
);
SET @ddl = IF(
    @has_disciplinary_check = 0,
    'ALTER TABLE match_disciplinary_events
     ADD CONSTRAINT chk_disciplinary_expulsion_reason CHECK (
       (event_type = ''YELLOW_CARD'' AND expulsion_reason_id IS NULL)
       OR (event_type IN (''SECOND_YELLOW_RED'', ''DIRECT_RED'') AND expulsion_reason_id IS NOT NULL)
     )',
    'DO 0'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_suspension_status_index = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_suspensions'
      AND INDEX_NAME = 'idx_suspensions_status'
);
SET @ddl = IF(
    @has_suspension_status_index = 0,
    'ALTER TABLE player_suspensions ADD INDEX idx_suspensions_status (status)',
    'DO 0'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_suspension_remaining_index = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'player_suspensions'
      AND INDEX_NAME = 'idx_suspensions_remaining'
);
SET @ddl = IF(
    @has_suspension_remaining_index = 0,
    'ALTER TABLE player_suspensions ADD INDEX idx_suspensions_remaining (remaining_matches)',
    'DO 0'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

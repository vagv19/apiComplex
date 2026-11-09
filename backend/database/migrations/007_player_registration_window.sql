SET @player_registration_start_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tournaments'
      AND COLUMN_NAME = 'player_registration_start_at'
);
SET @player_registration_start_sql = IF(
    @player_registration_start_exists = 0,
    'ALTER TABLE tournaments ADD COLUMN player_registration_start_at DATETIME NULL',
    'SELECT 1'
);
PREPARE player_registration_start_stmt FROM @player_registration_start_sql;
EXECUTE player_registration_start_stmt;
DEALLOCATE PREPARE player_registration_start_stmt;

SET @player_registration_end_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tournaments'
      AND COLUMN_NAME = 'player_registration_end_at'
);
SET @player_registration_end_sql = IF(
    @player_registration_end_exists = 0,
    'ALTER TABLE tournaments ADD COLUMN player_registration_end_at DATETIME NULL',
    'SELECT 1'
);
PREPARE player_registration_end_stmt FROM @player_registration_end_sql;
EXECUTE player_registration_end_stmt;
DEALLOCATE PREPARE player_registration_end_stmt;

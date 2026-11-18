SET @auth_version_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'auth_version'
);
SET @auth_version_sql = IF(
    @auth_version_exists = 0,
    'ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE auth_version_stmt FROM @auth_version_sql;
EXECUTE auth_version_stmt;
DEALLOCATE PREPARE auth_version_stmt;

SET @role_default = (
    SELECT COLUMN_DEFAULT
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
);
SET @role_default_sql = IF(
    @role_default IS NOT NULL,
    'ALTER TABLE users ALTER COLUMN role DROP DEFAULT',
    'SELECT 1'
);
PREPARE role_default_stmt FROM @role_default_sql;
EXECUTE role_default_stmt;
DEALLOCATE PREPARE role_default_stmt;

DROP TRIGGER IF EXISTS trg_users_auth_version;
CREATE TRIGGER trg_users_auth_version
BEFORE UPDATE ON users
FOR EACH ROW
SET NEW.auth_version = IF(
    NEW.role <> OLD.role OR NEW.is_active <> OLD.is_active,
    OLD.auth_version + 1,
    NEW.auth_version
);

CREATE TABLE IF NOT EXISTS admin_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_role VARCHAR(30) NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(80) NULL,
    tournament_id BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_tournament_created (tournament_id, created_at),
    INDEX idx_audit_entity (entity_type, entity_id)
);

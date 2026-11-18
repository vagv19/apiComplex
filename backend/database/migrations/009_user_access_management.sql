-- Add account lifecycle fields to the existing users table.
SET @deleted_at_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'deleted_at'
);
SET @deleted_at_sql = IF(
    @deleted_at_exists = 0,
    'ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL',
    'SELECT 1'
);
PREPARE deleted_at_stmt FROM @deleted_at_sql;
EXECUTE deleted_at_stmt;
DEALLOCATE PREPARE deleted_at_stmt;

SET @must_change_password_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'must_change_password'
);
SET @must_change_password_sql = IF(
    @must_change_password_exists = 0,
    'ALTER TABLE users ADD COLUMN must_change_password BOOLEAN NOT NULL DEFAULT FALSE',
    'SELECT 1'
);
PREPARE must_change_password_stmt FROM @must_change_password_sql;
EXECUTE must_change_password_stmt;
DEALLOCATE PREPARE must_change_password_stmt;

CREATE TABLE IF NOT EXISTS system_modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    parent_id BIGINT UNSIGNED NULL,
    route VARCHAR(160) NULL,
    icon VARCHAR(50) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_system_modules_parent FOREIGN KEY (parent_id) REFERENCES system_modules(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS user_modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    module_id BIGINT UNSIGNED NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_module (user_id, module_id),
    INDEX idx_user_modules_module (module_id, is_enabled),
    CONSTRAINT fk_user_modules_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_user_modules_module FOREIGN KEY (module_id) REFERENCES system_modules(id)
);

INSERT INTO system_modules (code, name, description, route, icon, sort_order) VALUES
    ('dashboard', 'Resumen', 'Resumen general del sistema.', '/', 'home', 10),
    ('tournaments', 'Torneos', 'Administración de torneos.', '/tournaments', 'trophy', 20),
    ('categories', 'Categorías', 'Administración de categorías.', '/categories', 'categories', 30),
    ('teams', 'Equipos', 'Catálogo y equipos asignados.', '/teams', 'flag', 40),
    ('players', 'Jugadores', 'Catálogo de jugadores.', '/players', 'player', 50),
    ('rosters', 'Plantillas', 'Plantillas y registros de torneo.', '/rosters', 'roster', 60),
    ('schedule', 'Calendario', 'Jornadas y programación.', '/schedule', 'calendar', 70),
    ('matchday', 'Partidos del día', 'Consulta de partidos por fecha.', '/matchday', 'clock', 80),
    ('results', 'Resultados', 'Captura de resultados y cédulas.', '/results', 'score', 90),
    ('standings', 'Posiciones', 'Tablas de posiciones.', '/standings', 'table', 100),
    ('discipline', 'Disciplina', 'Motivos de expulsión y sanciones.', '/discipline', 'warning', 110),
    ('users', 'Usuarios y accesos', 'Usuarios, roles y módulos.', '/users', 'users', 120),
    ('audit', 'Auditoría', 'Historial de operaciones administrativas.', '/audit', 'history', 130)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    route = VALUES(route),
    icon = VALUES(icon),
    sort_order = VALUES(sort_order);

INSERT IGNORE INTO user_modules (user_id, module_id, is_enabled)
SELECT u.id, m.id, 1
FROM users u
CROSS JOIN system_modules m
WHERE u.is_active = 1
  AND u.deleted_at IS NULL
  AND (
      (u.role = 'admin' AND m.code <> 'audit')
      OR (u.role = 'director_tecnico' AND m.code IN (
          'dashboard', 'tournaments', 'teams', 'players', 'rosters',
          'schedule', 'matchday', 'results', 'standings'
      ))
  );

DROP TRIGGER IF EXISTS trg_users_auth_version;
CREATE TRIGGER trg_users_auth_version
BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
    IF NEW.role <> OLD.role
       OR NEW.is_active <> OLD.is_active
       OR NOT (NEW.deleted_at <=> OLD.deleted_at) THEN
        SET NEW.auth_version = OLD.auth_version + 1;
    END IF;
END;

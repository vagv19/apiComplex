PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) NOT NULL,
    auth_version INTEGER NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    deleted_at DATETIME,
    must_change_password BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS system_modules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255),
    parent_id INTEGER,
    route VARCHAR(160),
    icon VARCHAR(50),
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES system_modules(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS user_modules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    module_id INTEGER NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, module_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (module_id) REFERENCES system_modules(id)
);
CREATE INDEX IF NOT EXISTS idx_user_modules_module ON user_modules(module_id, is_enabled);

INSERT OR IGNORE INTO system_modules (code, name, description, route, icon, sort_order) VALUES
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
    ('audit', 'Auditoría', 'Historial de operaciones administrativas.', '/audit', 'history', 130);

CREATE TABLE IF NOT EXISTS admin_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id INTEGER,
    actor_role VARCHAR(30) NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(80),
    tournament_id INTEGER,
    reason VARCHAR(500),
    old_values TEXT,
    new_values TEXT,
    ip_address VARCHAR(45),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_audit_created ON admin_audit_log(created_at);
CREATE INDEX IF NOT EXISTS idx_audit_tournament_created ON admin_audit_log(tournament_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON admin_audit_log(entity_type, entity_id);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(150) NOT NULL,
    short_name VARCHAR(30),
    logo_url VARCHAR(500),
    contact_name VARCHAR(150),
    contact_phone VARCHAR(30),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(150) NOT NULL,
    birth_date DATE,
    photo_url VARCHAR(500),
    phone VARCHAR(30),
    email VARCHAR(150),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tournaments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    name VARCHAR(150) NOT NULL,
    tournament_type VARCHAR(10) NOT NULL CHECK (tournament_type IN ('LEAGUE', 'CUP', 'FLASH')),
    status VARCHAR(20) NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'REGISTRATION', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED')),
    start_date DATE,
    end_date DATE,
    points_win INTEGER NOT NULL DEFAULT 3 CHECK (points_win BETWEEN 0 AND 255),
    points_draw INTEGER NOT NULL DEFAULT 1 CHECK (points_draw BETWEEN 0 AND 255),
    points_loss INTEGER NOT NULL DEFAULT 0 CHECK (points_loss BETWEEN 0 AND 255),
    regular_rounds INTEGER NOT NULL DEFAULT 10 CHECK (regular_rounds BETWEEN 0 AND 255),
    qualification_count INTEGER NOT NULL DEFAULT 8 CHECK (qualification_count BETWEEN 0 AND 255),
    player_registration_deadline INTEGER NOT NULL DEFAULT 4 CHECK (player_registration_deadline BETWEEN 0 AND 255),
    player_registration_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    player_registration_start_at DATETIME,
    player_registration_end_at DATETIME,
    rules TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

CREATE TABLE IF NOT EXISTS tournament_teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    registration_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    seed_number INTEGER,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'WITHDRAWN', 'DISQUALIFIED')),
    UNIQUE (tournament_id, team_id),
    UNIQUE (tournament_id, seed_number),
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id),
    FOREIGN KEY (team_id) REFERENCES teams(id)
);

CREATE TABLE IF NOT EXISTS tournament_team_coaches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_team_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (tournament_team_id, user_id),
    FOREIGN KEY (tournament_team_id) REFERENCES tournament_teams(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS tournament_players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id INTEGER NOT NULL,
    tournament_team_id INTEGER NOT NULL,
    player_id INTEGER NOT NULL,
    jersey_number INTEGER,
    position VARCHAR(50),
    registration_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE', 'SUSPENDED')),
    UNIQUE (tournament_id, player_id),
    UNIQUE (tournament_team_id, jersey_number),
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id),
    FOREIGN KEY (tournament_team_id) REFERENCES tournament_teams(id),
    FOREIGN KEY (player_id) REFERENCES players(id)
);

CREATE TABLE IF NOT EXISTS tournament_stages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tournament_id INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL,
    stage_type VARCHAR(20) NOT NULL CHECK (stage_type IN ('GROUP_STAGE', 'LEAGUE', 'ROUND_OF_16', 'QUARTER_FINAL', 'SEMI_FINAL', 'FINAL', 'THIRD_PLACE', 'KNOCKOUT')),
    sequence_number INTEGER NOT NULL,
    qualification_rules TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'IN_PROGRESS', 'COMPLETED')),
    UNIQUE (tournament_id, sequence_number),
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
);

CREATE TABLE IF NOT EXISTS tournament_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stage_id INTEGER NOT NULL,
    name VARCHAR(50) NOT NULL,
    group_order INTEGER NOT NULL DEFAULT 1,
    UNIQUE (stage_id, name),
    FOREIGN KEY (stage_id) REFERENCES tournament_stages(id)
);

CREATE TABLE IF NOT EXISTS group_teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL,
    tournament_team_id INTEGER NOT NULL,
    UNIQUE (group_id, tournament_team_id),
    FOREIGN KEY (group_id) REFERENCES tournament_groups(id),
    FOREIGN KEY (tournament_team_id) REFERENCES tournament_teams(id)
);

CREATE TABLE IF NOT EXISTS rounds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stage_id INTEGER NOT NULL,
    group_id INTEGER,
    round_number INTEGER NOT NULL,
    name VARCHAR(100),
    scheduled_date DATE,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'IN_PROGRESS', 'COMPLETED')),
    UNIQUE (stage_id, group_id, round_number),
    FOREIGN KEY (stage_id) REFERENCES tournament_stages(id),
    FOREIGN KEY (group_id) REFERENCES tournament_groups(id)
);

CREATE TABLE IF NOT EXISTS matches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    round_id INTEGER NOT NULL,
    home_tournament_team_id INTEGER,
    away_tournament_team_id INTEGER,
    match_number INTEGER,
    match_date DATE,
    kickoff_time TIME,
    venue VARCHAR(150),
    home_score INTEGER,
    away_score INTEGER,
    status VARCHAR(20) NOT NULL DEFAULT 'SCHEDULED' CHECK (status IN ('SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'POSTPONED', 'CANCELLED', 'BYE')),
    is_bye BOOLEAN NOT NULL DEFAULT FALSE,
    home_penalties INTEGER,
    away_penalties INTEGER,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (round_id) REFERENCES rounds(id),
    FOREIGN KEY (home_tournament_team_id) REFERENCES tournament_teams(id),
    FOREIGN KEY (away_tournament_team_id) REFERENCES tournament_teams(id),
    CHECK (
        (is_bye = FALSE AND home_tournament_team_id IS NOT NULL AND away_tournament_team_id IS NOT NULL AND home_tournament_team_id <> away_tournament_team_id)
        OR
        (is_bye = TRUE AND status = 'BYE' AND ((home_tournament_team_id IS NOT NULL AND away_tournament_team_id IS NULL) OR (home_tournament_team_id IS NULL AND away_tournament_team_id IS NOT NULL)))
    )
);

CREATE TABLE IF NOT EXISTS match_result_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    old_home_score INTEGER,
    old_away_score INTEGER,
    new_home_score INTEGER,
    new_away_score INTEGER,
    changed_by INTEGER,
    reason VARCHAR(255),
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES matches(id)
);

CREATE TABLE IF NOT EXISTS expulsion_reasons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(150) NOT NULL UNIQUE,
    description VARCHAR(500),
    suspension_matches INTEGER NOT NULL DEFAULT 0 CHECK (suspension_matches >= 0),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS match_player_attendance (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    tournament_player_id INTEGER NOT NULL,
    attendance_status VARCHAR(7) NOT NULL DEFAULT 'PRESENT' CHECK (attendance_status IN ('PRESENT', 'ABSENT')),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (match_id, tournament_player_id),
    FOREIGN KEY (match_id) REFERENCES matches(id),
    FOREIGN KEY (tournament_player_id) REFERENCES tournament_players(id)
);

CREATE TABLE IF NOT EXISTS match_goals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    tournament_player_id INTEGER NOT NULL,
    credited_to_team_id INTEGER NOT NULL,
    goal_type VARCHAR(10) NOT NULL DEFAULT 'REGULAR' CHECK (goal_type IN ('REGULAR', 'PENALTY', 'OWN_GOAL')),
    notes VARCHAR(300),
    client_key CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (match_id, client_key),
    FOREIGN KEY (match_id) REFERENCES matches(id),
    FOREIGN KEY (tournament_player_id) REFERENCES tournament_players(id),
    FOREIGN KEY (credited_to_team_id) REFERENCES tournament_teams(id)
);

CREATE TABLE IF NOT EXISTS match_disciplinary_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    tournament_player_id INTEGER NOT NULL,
    event_type VARCHAR(20) NOT NULL CHECK (event_type IN ('YELLOW_CARD', 'SECOND_YELLOW_RED', 'DIRECT_RED')),
    expulsion_reason_id INTEGER,
    notes VARCHAR(500),
    client_key CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (match_id, client_key),
    FOREIGN KEY (match_id) REFERENCES matches(id),
    FOREIGN KEY (tournament_player_id) REFERENCES tournament_players(id),
    FOREIGN KEY (expulsion_reason_id) REFERENCES expulsion_reasons(id),
    CHECK (
        (event_type = 'YELLOW_CARD' AND expulsion_reason_id IS NULL)
        OR (event_type IN ('SECOND_YELLOW_RED', 'DIRECT_RED') AND expulsion_reason_id IS NOT NULL)
    )
);

CREATE TABLE IF NOT EXISTS player_suspensions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    disciplinary_event_id INTEGER NOT NULL UNIQUE,
    expulsion_reason_name VARCHAR(150) NOT NULL,
    total_matches INTEGER NOT NULL CHECK (total_matches >= 0),
    served_matches INTEGER NOT NULL DEFAULT 0 CHECK (served_matches >= 0),
    remaining_matches INTEGER NOT NULL CHECK (remaining_matches >= 0),
    status VARCHAR(9) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'COMPLETED', 'OVERRIDDEN')),
    notes VARCHAR(500),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (total_matches = served_matches + remaining_matches),
    FOREIGN KEY (disciplinary_event_id) REFERENCES match_disciplinary_events(id)
);

CREATE INDEX IF NOT EXISTS idx_categories_active ON categories(is_active);
CREATE INDEX IF NOT EXISTS idx_teams_name ON teams(name);
CREATE INDEX IF NOT EXISTS idx_players_name ON players(last_name, first_name);
CREATE INDEX IF NOT EXISTS idx_tournaments_status ON tournaments(status);
CREATE INDEX IF NOT EXISTS idx_tournaments_category_status ON tournaments(category_id, status);
CREATE INDEX IF NOT EXISTS idx_rounds_stage ON rounds(stage_id, round_number);
CREATE INDEX IF NOT EXISTS idx_matches_round_status ON matches(round_id, status);
CREATE INDEX IF NOT EXISTS idx_matches_date ON matches(match_date);
CREATE INDEX IF NOT EXISTS idx_attendance_player ON match_player_attendance(tournament_player_id);
CREATE INDEX IF NOT EXISTS idx_goals_match ON match_goals(match_id);
CREATE INDEX IF NOT EXISTS idx_goals_player ON match_goals(tournament_player_id);
CREATE INDEX IF NOT EXISTS idx_goals_team ON match_goals(credited_to_team_id);
CREATE INDEX IF NOT EXISTS idx_disciplinary_match ON match_disciplinary_events(match_id);
CREATE INDEX IF NOT EXISTS idx_disciplinary_player ON match_disciplinary_events(tournament_player_id);
CREATE INDEX IF NOT EXISTS idx_disciplinary_reason ON match_disciplinary_events(expulsion_reason_id);
CREATE INDEX IF NOT EXISTS idx_expulsion_reasons_active ON expulsion_reasons(is_active);
CREATE INDEX IF NOT EXISTS idx_suspensions_status ON player_suspensions(status);
CREATE INDEX IF NOT EXISTS idx_suspensions_remaining ON player_suspensions(remaining_matches);

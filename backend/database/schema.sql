CREATE DATABASE IF NOT EXISTS bdcomplex_v2
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE bdcomplex_v2;

CREATE TABLE categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_category_name (name)
);

CREATE TABLE teams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    short_name VARCHAR(30) NULL,
    logo_url VARCHAR(500) NULL,
    contact_name VARCHAR(150) NULL,
    contact_phone VARCHAR(30) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_team_name (name)
);

CREATE TABLE players (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(150) NOT NULL,
    birth_date DATE NULL,
    photo_url VARCHAR(500) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_player_name (last_name, first_name)
);

CREATE TABLE tournaments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    tournament_type ENUM('LEAGUE','CUP','FLASH') NOT NULL,
    status ENUM('DRAFT','REGISTRATION','IN_PROGRESS','COMPLETED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
    start_date DATE NULL,
    end_date DATE NULL,
    points_win TINYINT UNSIGNED NOT NULL DEFAULT 3,
    points_draw TINYINT UNSIGNED NOT NULL DEFAULT 1,
    points_loss TINYINT UNSIGNED NOT NULL DEFAULT 0,
    regular_rounds TINYINT UNSIGNED NOT NULL DEFAULT 10,
    qualification_count TINYINT UNSIGNED NOT NULL DEFAULT 8,
    player_registration_deadline TINYINT UNSIGNED NOT NULL DEFAULT 4,
    player_registration_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    rules JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tournament_category FOREIGN KEY (category_id) REFERENCES categories(id),
    INDEX idx_tournament_status (status),
    INDEX idx_tournament_category (category_id, status)
);

CREATE TABLE tournament_teams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id BIGINT UNSIGNED NOT NULL,
    team_id BIGINT UNSIGNED NOT NULL,
    registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    seed_number INT UNSIGNED NULL,
    status ENUM('ACTIVE','WITHDRAWN','DISQUALIFIED') NOT NULL DEFAULT 'ACTIVE',
    UNIQUE KEY uq_tournament_team (tournament_id, team_id),
    UNIQUE KEY uq_seed (tournament_id, seed_number),
    CONSTRAINT fk_tt_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id),
    CONSTRAINT fk_tt_team FOREIGN KEY (team_id) REFERENCES teams(id)
);

CREATE TABLE tournament_players (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id BIGINT UNSIGNED NOT NULL,
    tournament_team_id BIGINT UNSIGNED NOT NULL,
    player_id BIGINT UNSIGNED NOT NULL,
    jersey_number SMALLINT UNSIGNED NULL,
    position VARCHAR(50) NULL,
    registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('ACTIVE','INACTIVE','SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
    UNIQUE KEY uq_player_tournament (tournament_id, player_id),
    UNIQUE KEY uq_jersey (tournament_team_id, jersey_number),
    CONSTRAINT fk_tp_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id),
    CONSTRAINT fk_tp_team FOREIGN KEY (tournament_team_id) REFERENCES tournament_teams(id),
    CONSTRAINT fk_tp_player FOREIGN KEY (player_id) REFERENCES players(id)
);

CREATE TABLE tournament_stages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    stage_type ENUM('GROUP_STAGE','LEAGUE','ROUND_OF_16','QUARTER_FINAL','SEMI_FINAL','FINAL','THIRD_PLACE','KNOCKOUT') NOT NULL,
    sequence_number SMALLINT UNSIGNED NOT NULL,
    qualification_rules JSON NULL,
    status ENUM('PENDING','IN_PROGRESS','COMPLETED') NOT NULL DEFAULT 'PENDING',
    UNIQUE KEY uq_stage_sequence (tournament_id, sequence_number),
    CONSTRAINT fk_stage_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
);

CREATE TABLE tournament_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stage_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL,
    group_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_group_name (stage_id, name),
    CONSTRAINT fk_group_stage FOREIGN KEY (stage_id) REFERENCES tournament_stages(id)
);

CREATE TABLE group_teams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    tournament_team_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_group_team (group_id, tournament_team_id),
    CONSTRAINT fk_gt_group FOREIGN KEY (group_id) REFERENCES tournament_groups(id),
    CONSTRAINT fk_gt_team FOREIGN KEY (tournament_team_id) REFERENCES tournament_teams(id)
);

CREATE TABLE rounds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stage_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NULL,
    round_number SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(100) NULL,
    scheduled_date DATE NULL,
    status ENUM('PENDING','IN_PROGRESS','COMPLETED') NOT NULL DEFAULT 'PENDING',
    UNIQUE KEY uq_round_group (stage_id, group_id, round_number),
    CONSTRAINT fk_round_stage FOREIGN KEY (stage_id) REFERENCES tournament_stages(id),
    CONSTRAINT fk_round_group FOREIGN KEY (group_id) REFERENCES tournament_groups(id)
);

CREATE TABLE matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    round_id BIGINT UNSIGNED NOT NULL,
    home_tournament_team_id BIGINT UNSIGNED NULL,
    away_tournament_team_id BIGINT UNSIGNED NULL,
    match_number SMALLINT UNSIGNED NULL,
    match_date DATE NULL,
    kickoff_time TIME NULL,
    venue VARCHAR(150) NULL,
    home_score SMALLINT UNSIGNED NULL,
    away_score SMALLINT UNSIGNED NULL,
    status ENUM('SCHEDULED','IN_PROGRESS','COMPLETED','POSTPONED','CANCELLED','BYE') NOT NULL DEFAULT 'SCHEDULED',
    is_bye BOOLEAN NOT NULL DEFAULT FALSE,
    home_penalties SMALLINT UNSIGNED NULL,
    away_penalties SMALLINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_match_round FOREIGN KEY (round_id) REFERENCES rounds(id),
    CONSTRAINT fk_match_home FOREIGN KEY (home_tournament_team_id) REFERENCES tournament_teams(id),
    CONSTRAINT fk_match_away FOREIGN KEY (away_tournament_team_id) REFERENCES tournament_teams(id),
    INDEX idx_match_round_status (round_id, status),
    INDEX idx_match_date (match_date),
    CONSTRAINT chk_match_teams CHECK (
        (is_bye = FALSE AND home_tournament_team_id IS NOT NULL AND away_tournament_team_id IS NOT NULL AND home_tournament_team_id <> away_tournament_team_id)
        OR
        (is_bye = TRUE AND status = 'BYE' AND ((home_tournament_team_id IS NOT NULL AND away_tournament_team_id IS NULL) OR (home_tournament_team_id IS NULL AND away_tournament_team_id IS NOT NULL)))
    )
);

CREATE TABLE match_result_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    old_home_score SMALLINT UNSIGNED NULL,
    old_away_score SMALLINT UNSIGNED NULL,
    new_home_score SMALLINT UNSIGNED NULL,
    new_away_score SMALLINT UNSIGNED NULL,
    changed_by BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_history_match FOREIGN KEY (match_id) REFERENCES matches(id)
);

CREATE TABLE expulsion_reasons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    suspension_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_expulsion_reasons_name (name),
    INDEX idx_expulsion_reasons_active (is_active)
);

CREATE TABLE match_player_attendance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    tournament_player_id BIGINT UNSIGNED NOT NULL,
    attendance_status ENUM('PRESENT','ABSENT') NOT NULL DEFAULT 'PRESENT',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_match_player_attendance (match_id, tournament_player_id),
    INDEX idx_attendance_player (tournament_player_id),
    CONSTRAINT fk_attendance_match FOREIGN KEY (match_id) REFERENCES matches(id),
    CONSTRAINT fk_attendance_tournament_player FOREIGN KEY (tournament_player_id) REFERENCES tournament_players(id)
);

CREATE TABLE match_goals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    tournament_player_id BIGINT UNSIGNED NOT NULL,
    credited_to_team_id BIGINT UNSIGNED NOT NULL,
    goal_type ENUM('REGULAR','PENALTY','OWN_GOAL') NOT NULL DEFAULT 'REGULAR',
    notes VARCHAR(300) NULL,
    client_key CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_match_goal_client_key (match_id, client_key),
    INDEX idx_goals_match (match_id),
    INDEX idx_goals_player (tournament_player_id),
    INDEX idx_goals_team (credited_to_team_id),
    CONSTRAINT fk_goal_match FOREIGN KEY (match_id) REFERENCES matches(id),
    CONSTRAINT fk_goal_tournament_player FOREIGN KEY (tournament_player_id) REFERENCES tournament_players(id),
    CONSTRAINT fk_goal_credited_team FOREIGN KEY (credited_to_team_id) REFERENCES tournament_teams(id)
);

CREATE TABLE match_disciplinary_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    tournament_player_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('YELLOW_CARD','SECOND_YELLOW_RED','DIRECT_RED') NOT NULL,
    expulsion_reason_id BIGINT UNSIGNED NULL,
    notes VARCHAR(500) NULL,
    client_key CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_match_disciplinary_client_key (match_id, client_key),
    INDEX idx_disciplinary_match (match_id),
    INDEX idx_disciplinary_player (tournament_player_id),
    INDEX idx_disciplinary_reason (expulsion_reason_id),
    CONSTRAINT fk_disciplinary_match FOREIGN KEY (match_id) REFERENCES matches(id),
    CONSTRAINT fk_disciplinary_player FOREIGN KEY (tournament_player_id) REFERENCES tournament_players(id),
    CONSTRAINT fk_disciplinary_expulsion_reason FOREIGN KEY (expulsion_reason_id) REFERENCES expulsion_reasons(id),
    CONSTRAINT chk_disciplinary_expulsion_reason CHECK (
        (event_type = 'YELLOW_CARD' AND expulsion_reason_id IS NULL)
        OR (event_type IN ('SECOND_YELLOW_RED','DIRECT_RED') AND expulsion_reason_id IS NOT NULL)
    )
);

CREATE TABLE player_suspensions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    disciplinary_event_id BIGINT UNSIGNED NOT NULL,
    expulsion_reason_name VARCHAR(150) NOT NULL,
    total_matches SMALLINT UNSIGNED NOT NULL,
    served_matches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    remaining_matches SMALLINT UNSIGNED NOT NULL,
    status ENUM('ACTIVE','COMPLETED','OVERRIDDEN') NOT NULL DEFAULT 'ACTIVE',
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suspension_disciplinary_event (disciplinary_event_id),
    CONSTRAINT fk_suspension_disciplinary_event FOREIGN KEY (disciplinary_event_id) REFERENCES match_disciplinary_events(id),
    CONSTRAINT chk_suspension_matches CHECK (total_matches = served_matches + remaining_matches),
    INDEX idx_suspensions_status (status),
    INDEX idx_suspensions_remaining (remaining_matches)
);

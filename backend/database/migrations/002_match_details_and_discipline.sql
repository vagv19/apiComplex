-- Adds match reporting details and discipline without changing existing data.
-- Apply after schema.sql and migrations/001_create_users.sql.
CREATE TABLE IF NOT EXISTS expulsion_reasons (
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

CREATE TABLE IF NOT EXISTS match_player_attendance (
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

CREATE TABLE IF NOT EXISTS match_goals (
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

CREATE TABLE IF NOT EXISTS match_disciplinary_events (
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

CREATE TABLE IF NOT EXISTS player_suspensions (
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

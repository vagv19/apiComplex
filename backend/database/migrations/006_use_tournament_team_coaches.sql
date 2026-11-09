CREATE TABLE IF NOT EXISTS tournament_team_coaches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_team_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tournament_team_coach (tournament_team_id, user_id),
    INDEX idx_team_coach_user_active (user_id, is_active),
    CONSTRAINT fk_ttc_tournament_team FOREIGN KEY (tournament_team_id) REFERENCES tournament_teams(id),
    CONSTRAINT fk_ttc_user FOREIGN KEY (user_id) REFERENCES users(id)
);

SET @migration_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'tournament_teams'
          AND column_name = 'director_user_id'
    ),
    'INSERT IGNORE INTO tournament_team_coaches (tournament_team_id, user_id, is_active, assigned_at) SELECT id, director_user_id, TRUE, CURRENT_TIMESTAMP FROM tournament_teams WHERE director_user_id IS NOT NULL',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

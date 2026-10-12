INSERT OR IGNORE INTO categories (id, name, description, is_active) VALUES
(1, 'Senior', 'Senior category', 1),
(2, 'Youth', 'Youth category', 1);

INSERT OR IGNORE INTO teams (id, name, short_name, is_active) VALUES
(1, 'Barcelona FC', 'BAR', 1),
(2, 'Real Madrid', 'RMA', 1),
(3, 'Manchester City', 'MCI', 1),
(4, 'Bayern Munich', 'BAY', 1),
(5, 'Juventus', 'JUV', 1),
(6, 'Inter Milan', 'INT', 1);

INSERT OR IGNORE INTO players (id, first_name, last_name, is_active) VALUES
(1, 'Robert', 'Lewandowski', 1),
(2, 'Jude', 'Bellingham', 1),
(3, 'Erling', 'Haaland', 1),
(4, 'Harry', 'Kane', 1),
(5, 'Dusan', 'Vlahovic', 1),
(6, 'Lautaro', 'Martinez', 1);

INSERT OR IGNORE INTO tournaments (id, name, category_id, tournament_type, status, start_date, end_date, points_win, points_draw, points_loss, regular_rounds, qualification_count, rules, player_registration_deadline, player_registration_enabled) VALUES
(1, 'Liga Mayor 2026', 1, 'LEAGUE', 'REGISTRATION', '2026-01-10', '2026-05-20', 3, 1, 0, 10, 8, 'goal_difference', 4, 1),
(2, 'Copa Nacional', 1, 'CUP', 'DRAFT', '2026-06-01', '2026-06-30', 3, 1, 0, 8, 8, 'penalties', 4, 1);

INSERT OR IGNORE INTO users (id, name, email, password_hash, role, is_active) VALUES
(1, 'Admin user', 'admin@example.com', '$2y$10$ee8N2z6dE1IiFhQ8d7Gv0eCgg3i9ezVYk0rU7w2wZq3DgG1kP4lAq', 'admin', 1);

START TRANSACTION;

SET @password_hash = '$2y$12$C1Ih18uFpFMU1WiGE4Qbf.R8sNzUd3t8SRckD5fp7XuFhf38R4Nfu';

-- Hasło dla wszystkich seed kont: test1234

-- Opcjonalnie: user_id = 1 jako admin dev
UPDATE players
SET isAdmin = 1
WHERE user_id = 1;

-- =========================================================
-- TEAM ALPHA
-- =========================================================

INSERT INTO users (username, email, password)
VALUES ('seed_alpha_igl', 'seed_alpha_igl@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @alpha_captain = LAST_INSERT_ID();

INSERT INTO teams (name, tag, logo, is_open, captain_id)
VALUES (
    'Seed Alpha',
    'ALP',
    'https://api.dicebear.com/7.x/identicon/svg?seed=SeedAlpha',
    1,
    @alpha_captain
)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), captain_id = VALUES(captain_id);
SET @team_alpha = LAST_INSERT_ID();

INSERT INTO players (
    user_id, team_id, is_substitute, avatar, bio, preferred_role,
    faceit_level, region, school, availability, isAdmin, isSpectator
)
VALUES (
    @alpha_captain, @team_alpha, 0,
    'https://ui-avatars.com/api/?name=Alpha+IGL&background=121212&color=ff002b',
    'Kapitan Seed Alpha.', 'igl', 8, 'EU', 'Clutchify Academy', 'Wieczory',
    0, 0
)
ON DUPLICATE KEY UPDATE
    team_id = VALUES(team_id),
    is_substitute = VALUES(is_substitute),
    avatar = VALUES(avatar),
    bio = VALUES(bio),
    preferred_role = VALUES(preferred_role),
    faceit_level = VALUES(faceit_level),
    region = VALUES(region),
    school = VALUES(school),
    availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_alpha_entry', 'seed_alpha_entry@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_alpha, 0, 'https://ui-avatars.com/api/?name=Alpha+Entry&background=121212&color=ff002b', 'Entry fragger Seed Alpha.', 'entry', 7, 'EU', 'Clutchify Academy', 'Wieczory', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_alpha_awper', 'seed_alpha_awper@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_alpha, 0, 'https://ui-avatars.com/api/?name=Alpha+AWP&background=121212&color=ff002b', 'AWPer Seed Alpha.', 'awper', 9, 'EU', 'Clutchify Academy', 'Wieczory', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_alpha_support', 'seed_alpha_support@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_alpha, 0, 'https://ui-avatars.com/api/?name=Alpha+Support&background=121212&color=ff002b', 'Support Seed Alpha.', 'support', 6, 'EU', 'Clutchify Academy', 'Wieczory', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_alpha_lurker', 'seed_alpha_lurker@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_alpha, 0, 'https://ui-avatars.com/api/?name=Alpha+Lurker&background=121212&color=ff002b', 'Lurker Seed Alpha.', 'lurker', 7, 'EU', 'Clutchify Academy', 'Wieczory', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

-- =========================================================
-- TEAM BRAVO
-- =========================================================

INSERT INTO users (username, email, password)
VALUES ('seed_bravo_igl', 'seed_bravo_igl@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @bravo_captain = LAST_INSERT_ID();

INSERT INTO teams (name, tag, logo, is_open, captain_id)
VALUES (
    'Seed Bravo',
    'BRV',
    'https://api.dicebear.com/7.x/identicon/svg?seed=SeedBravo',
    1,
    @bravo_captain
)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), captain_id = VALUES(captain_id);
SET @team_bravo = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@bravo_captain, @team_bravo, 0, 'https://ui-avatars.com/api/?name=Bravo+IGL&background=121212&color=ff002b', 'Kapitan Seed Bravo.', 'igl', 6, 'EU', 'Clutchify Academy', 'Weekendy', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_bravo_entry', 'seed_bravo_entry@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_bravo, 0, 'https://ui-avatars.com/api/?name=Bravo+Entry&background=121212&color=ff002b', 'Entry Seed Bravo.', 'entry', 6, 'EU', 'Clutchify Academy', 'Weekendy', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_bravo_awper', 'seed_bravo_awper@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_bravo, 0, 'https://ui-avatars.com/api/?name=Bravo+AWP&background=121212&color=ff002b', 'AWPer Seed Bravo.', 'awper', 7, 'EU', 'Clutchify Academy', 'Weekendy', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_bravo_support', 'seed_bravo_support@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_bravo, 0, 'https://ui-avatars.com/api/?name=Bravo+Support&background=121212&color=ff002b', 'Support Seed Bravo.', 'support', 5, 'EU', 'Clutchify Academy', 'Weekendy', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_bravo_lurker', 'seed_bravo_lurker@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_bravo, 0, 'https://ui-avatars.com/api/?name=Bravo+Lurker&background=121212&color=ff002b', 'Lurker Seed Bravo.', 'lurker', 6, 'EU', 'Clutchify Academy', 'Weekendy', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

-- =========================================================
-- TEAM CHARLIE
-- =========================================================

INSERT INTO users (username, email, password)
VALUES ('seed_charlie_igl', 'seed_charlie_igl@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @charlie_captain = LAST_INSERT_ID();

INSERT INTO teams (name, tag, logo, is_open, captain_id)
VALUES (
    'Seed Charlie',
    'CHR',
    'https://api.dicebear.com/7.x/identicon/svg?seed=SeedCharlie',
    1,
    @charlie_captain
)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), captain_id = VALUES(captain_id);
SET @team_charlie = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@charlie_captain, @team_charlie, 0, 'https://ui-avatars.com/api/?name=Charlie+IGL&background=121212&color=ff002b', 'Kapitan Seed Charlie.', 'igl', 9, 'EU', 'Clutchify Academy', 'Codziennie', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_charlie_entry', 'seed_charlie_entry@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_charlie, 0, 'https://ui-avatars.com/api/?name=Charlie+Entry&background=121212&color=ff002b', 'Entry Seed Charlie.', 'entry', 8, 'EU', 'Clutchify Academy', 'Codziennie', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_charlie_awper', 'seed_charlie_awper@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_charlie, 0, 'https://ui-avatars.com/api/?name=Charlie+AWP&background=121212&color=ff002b', 'AWPer Seed Charlie.', 'awper', 10, 'EU', 'Clutchify Academy', 'Codziennie', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_charlie_support', 'seed_charlie_support@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_charlie, 0, 'https://ui-avatars.com/api/?name=Charlie+Support&background=121212&color=ff002b', 'Support Seed Charlie.', 'support', 8, 'EU', 'Clutchify Academy', 'Codziennie', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_charlie_lurker', 'seed_charlie_lurker@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_charlie, 0, 'https://ui-avatars.com/api/?name=Charlie+Lurker&background=121212&color=ff002b', 'Lurker Seed Charlie.', 'lurker', 9, 'EU', 'Clutchify Academy', 'Codziennie', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

-- =========================================================
-- TEAM DELTA
-- =========================================================

INSERT INTO users (username, email, password)
VALUES ('seed_delta_igl', 'seed_delta_igl@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @delta_captain = LAST_INSERT_ID();

INSERT INTO teams (name, tag, logo, is_open, captain_id)
VALUES (
    'Seed Delta',
    'DLT',
    'https://api.dicebear.com/7.x/identicon/svg?seed=SeedDelta',
    1,
    @delta_captain
)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), captain_id = VALUES(captain_id);
SET @team_delta = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@delta_captain, @team_delta, 0, 'https://ui-avatars.com/api/?name=Delta+IGL&background=121212&color=ff002b', 'Kapitan Seed Delta.', 'igl', 5, 'EU', 'Clutchify Academy', 'Po 18:00', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_delta_entry', 'seed_delta_entry@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_delta, 0, 'https://ui-avatars.com/api/?name=Delta+Entry&background=121212&color=ff002b', 'Entry Seed Delta.', 'entry', 5, 'EU', 'Clutchify Academy', 'Po 18:00', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_delta_awper', 'seed_delta_awper@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_delta, 0, 'https://ui-avatars.com/api/?name=Delta+AWP&background=121212&color=ff002b', 'AWPer Seed Delta.', 'awper', 6, 'EU', 'Clutchify Academy', 'Po 18:00', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_delta_support', 'seed_delta_support@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_delta, 0, 'https://ui-avatars.com/api/?name=Delta+Support&background=121212&color=ff002b', 'Support Seed Delta.', 'support', 4, 'EU', 'Clutchify Academy', 'Po 18:00', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

INSERT INTO users (username, email, password)
VALUES ('seed_delta_lurker', 'seed_delta_lurker@clutchify.test', @password_hash)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), username = VALUES(username);
SET @u = LAST_INSERT_ID();

INSERT INTO players (user_id, team_id, is_substitute, avatar, bio, preferred_role, faceit_level, region, school, availability, isAdmin, isSpectator)
VALUES (@u, @team_delta, 0, 'https://ui-avatars.com/api/?name=Delta+Lurker&background=121212&color=ff002b', 'Lurker Seed Delta.', 'lurker', 5, 'EU', 'Clutchify Academy', 'Po 18:00', 0, 0)
ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), is_substitute = VALUES(is_substitute), avatar = VALUES(avatar), bio = VALUES(bio), preferred_role = VALUES(preferred_role), faceit_level = VALUES(faceit_level), region = VALUES(region), school = VALUES(school), availability = VALUES(availability);

-- =========================================================
-- TEST TOURNAMENT
-- =========================================================

SET @seed_tournament_id = (
    SELECT id
    FROM tournaments
    WHERE title = 'Seed Bracket Test'
    ORDER BY id DESC
    LIMIT 1
);

INSERT INTO tournaments (
    join_code,
    is_open,
    status,
    creator,
    title,
    sign_in_end,
    starts_at
)
SELECT
    'SEEDTEST',
    1,
    'registration_closed',
    'Clutchify Seed',
    'Seed Bracket Test',
    NOW(),
    DATE_ADD(NOW(), INTERVAL 1 HOUR)
WHERE @seed_tournament_id IS NULL;

SET @seed_tournament_id = COALESCE(@seed_tournament_id, LAST_INSERT_ID());

UPDATE tournaments
SET
    is_open = 1,
    status = 'registration_closed',
    sign_in_end = NOW(),
    starts_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
WHERE id = @seed_tournament_id;

-- Reset bracketu i zapisów dla testowego turnieju, żeby seed dało się odpalać wiele razy.
DELETE FROM tournament_matches
WHERE tournament_id = @seed_tournament_id;

DELETE FROM tournament_teams
WHERE tournament_id = @seed_tournament_id;

INSERT INTO tournament_teams (
    tournament_id,
    team_id,
    registered_by,
    status,
    verification_note,
    admin_note,
    reviewed_by,
    reviewed_at
)
VALUES
    (@seed_tournament_id, @team_alpha, @alpha_captain, 'approved', 'Seed test registration.', 'Auto approved by seed.', 1, NOW()),
    (@seed_tournament_id, @team_bravo, @bravo_captain, 'approved', 'Seed test registration.', 'Auto approved by seed.', 1, NOW()),
    (@seed_tournament_id, @team_charlie, @charlie_captain, 'approved', 'Seed test registration.', 'Auto approved by seed.', 1, NOW()),
    (@seed_tournament_id, @team_delta, @delta_captain, 'approved', 'Seed test registration.', 'Auto approved by seed.', 1, NOW());

INSERT INTO activity_events (
    actor_user_id,
    type,
    title,
    message,
    target_type,
    target_id,
    metadata,
    visibility
)
VALUES (
    1,
    'seed_created',
    'Dane testowe',
    'Utworzono testowe drużyny i turniej Seed Bracket Test.',
    'tournament',
    @seed_tournament_id,
    JSON_OBJECT(
        'tournament_title', 'Seed Bracket Test',
        'teams', JSON_ARRAY('Seed Alpha', 'Seed Bravo', 'Seed Charlie', 'Seed Delta')
    ),
    'admin'
);

COMMIT;

SELECT
    @seed_tournament_id AS tournament_id,
    'Seed Bracket Test' AS tournament_title,
    'Gotowe. Wejdź w turniej i kliknij Wygeneruj bracket.' AS info;
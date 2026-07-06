START TRANSACTION;

-- Znajdź testowy turniej
SET @seed_tournament_id = (
    SELECT id
    FROM tournaments
    WHERE title = 'Seed Bracket Test'
    ORDER BY id DESC
    LIMIT 1
);

-- Usuń mecze testowego bracketu
DELETE FROM tournament_matches
WHERE @seed_tournament_id IS NOT NULL
  AND tournament_id = @seed_tournament_id;

-- Usuń zapisy drużyn do testowego turnieju
DELETE FROM tournament_teams
WHERE @seed_tournament_id IS NOT NULL
  AND tournament_id = @seed_tournament_id;

-- Usuń aktywności związane z seedem / testowym turniejem
DELETE FROM activity_events
WHERE type = 'seed_created'
   OR (
        @seed_tournament_id IS NOT NULL
        AND target_type = 'tournament'
        AND target_id = @seed_tournament_id
   )
   OR message LIKE '%Seed Bracket Test%';

-- Usuń testowy turniej
DELETE FROM tournaments
WHERE title = 'Seed Bracket Test';

-- Usuń graczy seedowych
DELETE FROM players
WHERE user_id IN (
    SELECT id
    FROM users
    WHERE username REGEXP '^seed_'
       OR email LIKE '%@clutchify.test'
);

-- Usuń testowe drużyny
DELETE FROM teams
WHERE name IN (
    'Seed Alpha',
    'Seed Bravo',
    'Seed Charlie',
    'Seed Delta'
)
OR tag IN ('ALP', 'BRV', 'CHR', 'DLT');

-- Usuń testowych userów
DELETE FROM users
WHERE username REGEXP '^seed_'
   OR email LIKE '%@clutchify.test';

COMMIT;

SELECT 'Usunięto testowe dane bracketu.' AS info;
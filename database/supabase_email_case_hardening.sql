-- ============================================================
-- AGAPE AIIS Supabase Email Case Hardening
--
-- Run this after:
-- database/supabase_production_readiness_hardening.sql
--
-- Purpose:
-- - normalize existing account emails to lowercase;
-- - prevent future case-only duplicate accounts such as
--   User@school.edu and user@school.edu.
-- ============================================================

DO $$
DECLARE
    issue_count BIGINT;
BEGIN
    SELECT COUNT(*) INTO issue_count
    FROM (
        SELECT lower(btrim(email))
        FROM users
        GROUP BY lower(btrim(email))
        HAVING COUNT(*) > 1
    ) x;

    IF issue_count > 0 THEN
        RAISE EXCEPTION 'Cannot harden email uniqueness: % case-insensitive duplicate email group(s) exist. Merge or rename duplicate users first.', issue_count;
    END IF;
END
$$;

UPDATE users
SET email = lower(btrim(email))
WHERE email <> lower(btrim(email));

CREATE UNIQUE INDEX IF NOT EXISTS uq_users_email_lower
    ON users ((lower(btrim(email))));

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_users_email_lower_trimmed'
          AND conrelid = 'users'::regclass
    ) THEN
        ALTER TABLE users
            ADD CONSTRAINT chk_users_email_lower_trimmed
            CHECK (email = lower(btrim(email)));
    END IF;
END
$$;

SELECT 'AGAPE AIIS email case hardening completed.' AS result;

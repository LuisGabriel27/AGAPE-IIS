-- ============================================================
-- AGAPE-IIS Supabase Upgrade v3 - Split full_name into first_name + last_name
-- Tables: students, guardians, teachers
-- Run in: Supabase Dashboard -> SQL Editor -> New Query
--
-- This migration is safe to rerun. It also handles a partially migrated
-- database where one table was already split but another still has full_name.
-- ============================================================

BEGIN;

-- ============================================================
-- 1. STUDENTS
-- ============================================================
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'students' AND column_name = 'full_name'
    ) THEN
        ALTER TABLE students
            ADD COLUMN IF NOT EXISTS first_name TEXT,
            ADD COLUMN IF NOT EXISTS last_name  TEXT;

        UPDATE students SET
            first_name = CASE
                WHEN position(' ' IN trim(coalesce(full_name, ''))) > 0
                    THEN split_part(trim(coalesce(full_name, '')), ' ', 1)
                ELSE ''
            END,
            last_name = CASE
                WHEN position(' ' IN trim(coalesce(full_name, ''))) > 0
                    THEN trim(substring(trim(coalesce(full_name, '')) FROM position(' ' IN trim(coalesce(full_name, ''))) + 1))
                ELSE trim(coalesce(full_name, ''))
            END
        WHERE first_name IS NULL OR last_name IS NULL;

        ALTER TABLE students DROP COLUMN full_name;
    END IF;

    ALTER TABLE students
        ADD COLUMN IF NOT EXISTS first_name TEXT,
        ADD COLUMN IF NOT EXISTS last_name  TEXT;

    UPDATE students
    SET first_name = COALESCE(first_name, ''),
        last_name = COALESCE(last_name, '');

    ALTER TABLE students
        ALTER COLUMN first_name SET NOT NULL,
        ALTER COLUMN last_name  SET NOT NULL;
END$$;

CREATE INDEX IF NOT EXISTS idx_students_last_name ON students (last_name);

-- ============================================================
-- 2. GUARDIANS
-- ============================================================
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'guardians' AND column_name = 'full_name'
    ) THEN
        ALTER TABLE guardians
            ADD COLUMN IF NOT EXISTS first_name TEXT,
            ADD COLUMN IF NOT EXISTS last_name  TEXT;

        UPDATE guardians SET
            first_name = CASE
                WHEN position(' ' IN trim(coalesce(full_name, ''))) > 0
                    THEN split_part(trim(coalesce(full_name, '')), ' ', 1)
                ELSE ''
            END,
            last_name = CASE
                WHEN position(' ' IN trim(coalesce(full_name, ''))) > 0
                    THEN trim(substring(trim(coalesce(full_name, '')) FROM position(' ' IN trim(coalesce(full_name, ''))) + 1))
                ELSE trim(coalesce(full_name, ''))
            END
        WHERE first_name IS NULL OR last_name IS NULL;

        ALTER TABLE guardians DROP COLUMN full_name;
    END IF;

    ALTER TABLE guardians
        ADD COLUMN IF NOT EXISTS first_name TEXT,
        ADD COLUMN IF NOT EXISTS last_name  TEXT;

    UPDATE guardians
    SET first_name = COALESCE(first_name, ''),
        last_name = COALESCE(last_name, '');

    ALTER TABLE guardians
        ALTER COLUMN first_name SET NOT NULL,
        ALTER COLUMN last_name  SET NOT NULL;
END$$;

CREATE INDEX IF NOT EXISTS idx_guardians_last_name ON guardians (last_name);

-- ============================================================
-- 3. TEACHERS
-- ============================================================
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'teachers' AND column_name = 'full_name'
    ) THEN
        ALTER TABLE teachers
            ADD COLUMN IF NOT EXISTS first_name TEXT,
            ADD COLUMN IF NOT EXISTS last_name  TEXT;

        UPDATE teachers SET
            first_name = CASE
                WHEN position(' ' IN trim(coalesce(full_name, ''))) > 0
                    THEN split_part(trim(coalesce(full_name, '')), ' ', 1)
                ELSE ''
            END,
            last_name = CASE
                WHEN position(' ' IN trim(coalesce(full_name, ''))) > 0
                    THEN trim(substring(trim(coalesce(full_name, '')) FROM position(' ' IN trim(coalesce(full_name, ''))) + 1))
                ELSE trim(coalesce(full_name, ''))
            END
        WHERE first_name IS NULL OR last_name IS NULL;

        ALTER TABLE teachers DROP COLUMN full_name;
    END IF;

    ALTER TABLE teachers
        ADD COLUMN IF NOT EXISTS first_name TEXT,
        ADD COLUMN IF NOT EXISTS last_name  TEXT;

    UPDATE teachers
    SET first_name = COALESCE(first_name, ''),
        last_name = COALESCE(last_name, '');

    ALTER TABLE teachers
        ALTER COLUMN first_name SET NOT NULL,
        ALTER COLUMN last_name  SET NOT NULL;
END$$;

CREATE INDEX IF NOT EXISTS idx_teachers_last_name ON teachers (last_name);

COMMIT;

-- ============================================================
-- AGAPE-IIS Supabase Required Updates - Combined Latest
-- Run in: Supabase Dashboard -> SQL Editor -> New Query
--
-- This file combines the database updates needed by the current app:
-- - nullable student guardian_id
-- - clerk role and multi-role user_roles table
-- - expanded guardian profile fields
-- - settings/calendar/enrollment/payment/grade workflow columns
-- - split full_name into first_name + last_name
-- - enrollment requirement document uploads
--
-- Safe to rerun. Do NOT run supabase_schema.sql on a database with data;
-- use this file for upgrading an existing Supabase database.
-- ============================================================

-- ============================================================
-- 1. STUDENT GUARDIAN RELATIONSHIP
-- ============================================================

ALTER TABLE students
    ALTER COLUMN guardian_id DROP NOT NULL,
    ALTER COLUMN guardian_id SET DEFAULT NULL;

ALTER TABLE students DROP CONSTRAINT IF EXISTS fk_student_guardian;

ALTER TABLE students
    ADD CONSTRAINT fk_student_guardian
    FOREIGN KEY (guardian_id) REFERENCES guardians (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- ============================================================
-- 2. ROLES AND GUARDIAN PROFILE FIELDS
-- ============================================================

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_enum
        WHERE enumlabel = 'clerk'
          AND enumtypid = 'user_role'::regtype
    ) THEN
        ALTER TYPE user_role ADD VALUE 'clerk';
    END IF;
END$$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'civil_status_type') THEN
        CREATE TYPE civil_status_type AS ENUM (
            'single', 'married', 'widowed', 'separated', 'others'
        );
    END IF;
END$$;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL,
    role    user_role NOT NULL,
    PRIMARY KEY (user_id, role),
    CONSTRAINT fk_ur_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
);

INSERT INTO user_roles (user_id, role)
    SELECT id, role FROM users
ON CONFLICT DO NOTHING;

ALTER TABLE guardians
    ADD COLUMN IF NOT EXISTS occupation               VARCHAR(100)      DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS civil_status             civil_status_type DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS nationality              VARCHAR(100)      DEFAULT 'Filipino',
    ADD COLUMN IF NOT EXISTS religion                 VARCHAR(100)      DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_name   VARCHAR(255)      DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_number VARCHAR(50)       DEFAULT NULL;

-- ============================================================
-- 3. SETTINGS, CALENDAR, ENROLLMENT, AND GRADES
-- ============================================================

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_enum
        WHERE enumlabel = 'archived'
          AND enumtypid = 'enrollment_status'::regtype
    ) THEN
        ALTER TYPE enrollment_status ADD VALUE 'archived';
    END IF;
END$$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'calendar_source') THEN
        CREATE TYPE calendar_source AS ENUM ('manual', 'deped');
    END IF;
END$$;

CREATE TABLE IF NOT EXISTS settings (
    "key"   VARCHAR(100) NOT NULL,
    "value" TEXT NOT NULL,
    PRIMARY KEY ("key")
);

INSERT INTO settings ("key", "value") VALUES
    ('active_school_year', '2024-2025')
ON CONFLICT ("key") DO NOTHING;

INSERT INTO settings ("key", "value") VALUES
    ('active_term', '1st Semester')
ON CONFLICT ("key") DO NOTHING;

INSERT INTO settings ("key", "value") VALUES
    ('attendance_module_enabled', '1')
ON CONFLICT ("key") DO NOTHING;

ALTER TABLE calendar_events
    ADD COLUMN IF NOT EXISTS source calendar_source NOT NULL DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS school_year VARCHAR(20) NOT NULL DEFAULT '';

ALTER TABLE enrollments
    ADD COLUMN IF NOT EXISTS payment_submitted_at TIMESTAMP DEFAULT NULL;

ALTER TABLE subjects
    ADD COLUMN IF NOT EXISTS grade_level VARCHAR(20) NOT NULL DEFAULT '',
    ALTER COLUMN units SET DEFAULT 0;

ALTER TABLE grades
    ADD COLUMN IF NOT EXISTS quarter1 DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quarter2 DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quarter3 DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quarter4 DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS published SMALLINT NOT NULL DEFAULT 0;

UPDATE grades
SET quarter1 = COALESCE(quarter1, midterm),
    quarter2 = COALESCE(quarter2, finals)
WHERE quarter1 IS NULL
   OR quarter2 IS NULL;

UPDATE grades
SET final_grade = CASE
    WHEN quarter1 IS NOT NULL
     AND quarter2 IS NOT NULL
     AND quarter3 IS NOT NULL
     AND quarter4 IS NOT NULL
        THEN ROUND((quarter1 + quarter2 + quarter3 + quarter4) / 4, 2)
    ELSE NULL
END;

UPDATE grades
SET published = 0
WHERE published IS NULL;

ALTER TABLE grades
    ALTER COLUMN published SET DEFAULT 0,
    ALTER COLUMN published SET NOT NULL;

-- ============================================================
-- 4. SPLIT full_name INTO first_name + last_name
-- ============================================================

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'students'
          AND column_name = 'full_name'
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
        ALTER COLUMN first_name SET DEFAULT '',
        ALTER COLUMN first_name SET NOT NULL,
        ALTER COLUMN last_name  SET NOT NULL;
END$$;

CREATE INDEX IF NOT EXISTS idx_students_last_name ON students (last_name);

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'guardians'
          AND column_name = 'full_name'
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
        ALTER COLUMN first_name SET DEFAULT '',
        ALTER COLUMN first_name SET NOT NULL,
        ALTER COLUMN last_name  SET NOT NULL;
END$$;

CREATE INDEX IF NOT EXISTS idx_guardians_last_name ON guardians (last_name);

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'teachers'
          AND column_name = 'full_name'
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
        ALTER COLUMN first_name SET DEFAULT '',
        ALTER COLUMN first_name SET NOT NULL,
        ALTER COLUMN last_name  SET NOT NULL;
END$$;

CREATE INDEX IF NOT EXISTS idx_teachers_last_name ON teachers (last_name);

-- ============================================================
-- 5. ENROLLMENT REQUIREMENT DOCUMENT UPLOADS
-- ============================================================

CREATE TABLE IF NOT EXISTS enrollment_documents (
    id            SERIAL PRIMARY KEY,
    enrollment_id INT NOT NULL,
    document_type VARCHAR(50) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_path     VARCHAR(500) NOT NULL,
    mime_type     VARCHAR(100) DEFAULT NULL,
    file_size     BIGINT DEFAULT NULL,
    uploaded_by   INT DEFAULT NULL,
    uploaded_at   TIMESTAMP NOT NULL DEFAULT NOW()
);

ALTER TABLE enrollment_documents
    ADD COLUMN IF NOT EXISTS enrollment_id INT,
    ADD COLUMN IF NOT EXISTS document_type VARCHAR(50),
    ADD COLUMN IF NOT EXISTS original_name VARCHAR(255),
    ADD COLUMN IF NOT EXISTS file_path VARCHAR(500),
    ADD COLUMN IF NOT EXISTS mime_type VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS file_size BIGINT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS uploaded_by INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS uploaded_at TIMESTAMP NOT NULL DEFAULT NOW();

UPDATE enrollment_documents
SET uploaded_at = NOW()
WHERE uploaded_at IS NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_enrollment_document_type'
          AND conrelid = 'enrollment_documents'::regclass
    ) THEN
        ALTER TABLE enrollment_documents
            ADD CONSTRAINT chk_enrollment_document_type
            CHECK (document_type IN ('psa', 'medical', 'previous_school', 'parent_data'));
    END IF;
END$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname IN ('fk_enrollment_document_enrollment', 'enrollment_documents_enrollment_id_fkey')
          AND conrelid = 'enrollment_documents'::regclass
    ) THEN
        ALTER TABLE enrollment_documents
            ADD CONSTRAINT fk_enrollment_document_enrollment
            FOREIGN KEY (enrollment_id) REFERENCES enrollments (id)
            ON DELETE CASCADE ON UPDATE CASCADE;
    END IF;
END$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname IN ('fk_enrollment_document_uploader', 'enrollment_documents_uploaded_by_fkey')
          AND conrelid = 'enrollment_documents'::regclass
    ) THEN
        ALTER TABLE enrollment_documents
            ADD CONSTRAINT fk_enrollment_document_uploader
            FOREIGN KEY (uploaded_by) REFERENCES users (id)
            ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END$$;

ALTER TABLE enrollment_documents
    ALTER COLUMN enrollment_id SET NOT NULL,
    ALTER COLUMN document_type SET NOT NULL,
    ALTER COLUMN original_name SET NOT NULL,
    ALTER COLUMN file_path SET NOT NULL,
    ALTER COLUMN uploaded_at SET DEFAULT NOW(),
    ALTER COLUMN uploaded_at SET NOT NULL;

CREATE INDEX IF NOT EXISTS idx_enrollment_documents_enrollment
    ON enrollment_documents (enrollment_id);

CREATE INDEX IF NOT EXISTS idx_enrollment_documents_uploaded_by
    ON enrollment_documents (uploaded_by);

CREATE UNIQUE INDEX IF NOT EXISTS uq_enrollment_documents_type
    ON enrollment_documents (enrollment_id, document_type);

-- ============================================================
-- Done.
-- ============================================================

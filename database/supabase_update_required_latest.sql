-- ============================================================
-- AGAPE-IIS Supabase Required Updates - Combined Latest
-- Run in: Supabase Dashboard -> SQL Editor -> New Query
--
-- This file combines the database updates needed by the current app:
-- - nullable student guardian_id
-- - clerk role and multi-role user_roles table
-- - expanded student/guardian/teacher/profile fields
-- - settings/calendar/enrollment/payment/grade workflow columns
-- - split full_name into first_name + last_name
-- - enrollment requirement document uploads, review notes, and assessments
-- - quarter-based school year setting migration
-- - PSA uniqueness and Philippine ZIP code validation
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
ALTER TABLE students DROP CONSTRAINT IF EXISTS students_guardian_id_fkey;

ALTER TABLE students
    ADD CONSTRAINT fk_student_guardian
    FOREIGN KEY (guardian_id) REFERENCES guardians (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- ============================================================
-- 2. ROLES AND PROFILE FIELDS
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

CREATE INDEX IF NOT EXISTS idx_user_roles_role
    ON user_roles (role);

ALTER TABLE students
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(100) DEFAULT '',
    ADD COLUMN IF NOT EXISTS extension_name VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS psa_birth_certificate_no VARCHAR(80) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS place_of_birth VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS mother_tongue VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS religion VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS current_house_street VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS current_barangay VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS current_city_municipality VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS current_province VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS current_country VARCHAR(100) DEFAULT 'Philippines',
    ADD COLUMN IF NOT EXISTS current_zip_code VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS permanent_same_as_current BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS permanent_house_street VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS permanent_barangay VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS permanent_city_municipality VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS permanent_province VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS permanent_country VARCHAR(100) DEFAULT 'Philippines',
    ADD COLUMN IF NOT EXISTS permanent_zip_code VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS father_first_name VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS father_middle_name VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS father_last_name VARCHAR(155) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS father_contact_number VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS mother_first_name VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS mother_middle_name VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS mother_maiden_last_name VARCHAR(155) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS mother_contact_number VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS is_ip_community BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS ip_group VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS is_4ps_beneficiary BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS four_ps_household_id VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS learner_with_disability BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS disability_type VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS returning_learner BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS transferee BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS last_grade_level_completed VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS last_school_year_completed VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS last_school_attended VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS previous_school_id VARCHAR(50) DEFAULT NULL;

ALTER TABLE guardians
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(100) DEFAULT '',
    ADD COLUMN IF NOT EXISTS extension_name VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS occupation               VARCHAR(100)      DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS civil_status             civil_status_type DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS nationality              VARCHAR(100)      DEFAULT 'Filipino',
    ADD COLUMN IF NOT EXISTS religion                 VARCHAR(100)      DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_name   VARCHAR(255)      DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_number VARCHAR(50)       DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS data_privacy_consent BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS data_privacy_consented_at TIMESTAMP DEFAULT NULL;

ALTER TABLE teachers
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(100) DEFAULT '',
    ADD COLUMN IF NOT EXISTS extension_name VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS employee_number VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS gender gender_type DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS birthdate DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS civil_status civil_status_type DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS address TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS position_title VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS employment_status VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS date_hired DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS prc_license_no VARCHAR(80) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS prc_license_expiry DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS specialization VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_name VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_number VARCHAR(50) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS user_profiles (
    user_id INT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    first_name VARCHAR(100) NOT NULL DEFAULT '',
    middle_name VARCHAR(100) NOT NULL DEFAULT '',
    last_name VARCHAR(155) NOT NULL DEFAULT '',
    extension_name VARCHAR(30) DEFAULT NULL,
    employee_number VARCHAR(50) DEFAULT NULL,
    contact_number VARCHAR(50) DEFAULT NULL,
    office VARCHAR(100) DEFAULT NULL,
    position_title VARCHAR(100) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    employment_status VARCHAR(50) DEFAULT NULL,
    date_hired DATE DEFAULT NULL,
    emergency_contact_name VARCHAR(255) DEFAULT NULL,
    emergency_contact_number VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_teachers_employee_number_not_blank
    ON teachers ((lower(btrim(employee_number))))
    WHERE employee_number IS NOT NULL AND btrim(employee_number) <> '';

CREATE UNIQUE INDEX IF NOT EXISTS uq_teachers_prc_license_not_blank
    ON teachers ((lower(btrim(prc_license_no))))
    WHERE prc_license_no IS NOT NULL AND btrim(prc_license_no) <> '';

CREATE UNIQUE INDEX IF NOT EXISTS uq_user_profiles_employee_number_not_blank
    ON user_profiles ((lower(btrim(employee_number))))
    WHERE employee_number IS NOT NULL AND btrim(employee_number) <> '';

-- ============================================================
-- 3. SETTINGS, CALENDAR, ENROLLMENT, AND GRADES
-- ============================================================

ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'submitted';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'requirements_incomplete';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'documents_under_review';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'assessed_for_payment';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'awaiting_payment';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'paid_for_registrar';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'returned';
ALTER TYPE enrollment_status ADD VALUE IF NOT EXISTS 'archived';

-- Enum values added above must be committed before they can be used in UPDATEs.
COMMIT;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'calendar_source') THEN
        CREATE TYPE calendar_source AS ENUM ('manual', 'deped');
    END IF;
END$$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'document_review_status') THEN
        CREATE TYPE document_review_status AS ENUM ('pending', 'accepted', 'needs_replacement', 'missing');
    END IF;
END$$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'assessment_status') THEN
        CREATE TYPE assessment_status AS ENUM ('draft', 'sent_to_cashier', 'cancelled');
    END IF;
END$$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'assessment_item_category') THEN
        CREATE TYPE assessment_item_category AS ENUM (
            'tuition',
            'enrollment_fee',
            'miscellaneous',
            'discount',
            'scholarship',
            'other'
        );
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
    ('active_term', '1st Quarter')
ON CONFLICT ("key") DO NOTHING;

UPDATE settings
SET "value" = '1st Quarter'
WHERE "key" = 'active_term'
  AND "value" IN ('1st Semester', 'First Semester');

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

CREATE INDEX IF NOT EXISTS idx_subject_grade_level ON subjects (grade_level);

WITH seed_subjects (code, name, grade_level) AS (
    VALUES
    ('PS-LANG', 'Language Readiness', 'Preschool'),
    ('PS-NUM', 'Numeracy Readiness', 'Preschool'),
    ('PS-VAL', 'Values and Social Development', 'Preschool'),
    ('PS-MOTOR', 'Motor Skills and Creative Arts', 'Preschool'),
    ('K-LANG', 'Language, Literacy and Communication', 'Kindergarten'),
    ('K-MATH', 'Mathematics', 'Kindergarten'),
    ('K-ENV', 'Physical and Natural Environment', 'Kindergarten'),
    ('K-MAK', 'Makabansa', 'Kindergarten'),
    ('K-GMRC', 'Good Manners and Right Conduct', 'Kindergarten'),
    ('G1-MTB', 'Mother Tongue', '1'),
    ('G1-FIL', 'Filipino', '1'),
    ('G1-ENG', 'English', '1'),
    ('G1-MATH', 'Mathematics', '1'),
    ('G1-AP', 'Araling Panlipunan', '1'),
    ('G1-GMRCESP', 'GMRC / Edukasyon sa Pagpapakatao', '1'),
    ('G1-MUSIC', 'Music', '1'),
    ('G1-ARTS', 'Arts', '1'),
    ('G1-PE', 'Physical Education', '1'),
    ('G1-HEALTH', 'Health', '1'),
    ('G2-MTB', 'Mother Tongue', '2'),
    ('G2-FIL', 'Filipino', '2'),
    ('G2-ENG', 'English', '2'),
    ('G2-MATH', 'Mathematics', '2'),
    ('G2-AP', 'Araling Panlipunan', '2'),
    ('G2-GMRCESP', 'GMRC / Edukasyon sa Pagpapakatao', '2'),
    ('G2-MUSIC', 'Music', '2'),
    ('G2-ARTS', 'Arts', '2'),
    ('G2-PE', 'Physical Education', '2'),
    ('G2-HEALTH', 'Health', '2'),
    ('G3-MTB', 'Mother Tongue', '3'),
    ('G3-FIL', 'Filipino', '3'),
    ('G3-ENG', 'English', '3'),
    ('G3-MATH', 'Mathematics', '3'),
    ('G3-SCI', 'Science', '3'),
    ('G3-AP', 'Araling Panlipunan', '3'),
    ('G3-GMRCESP', 'GMRC / Edukasyon sa Pagpapakatao', '3'),
    ('G3-MUSIC', 'Music', '3'),
    ('G3-ARTS', 'Arts', '3'),
    ('G3-PE', 'Physical Education', '3'),
    ('G3-HEALTH', 'Health', '3'),
    ('G4-FIL', 'Filipino', '4'),
    ('G4-ENG', 'English', '4'),
    ('G4-MATH', 'Mathematics', '4'),
    ('G4-SCI', 'Science', '4'),
    ('G4-AP', 'Araling Panlipunan', '4'),
    ('G4-GMRCESP', 'GMRC / Edukasyon sa Pagpapakatao', '4'),
    ('G4-MUSIC', 'Music', '4'),
    ('G4-ARTS', 'Arts', '4'),
    ('G4-PE', 'Physical Education', '4'),
    ('G4-HEALTH', 'Health', '4'),
    ('G4-EPP', 'Edukasyong Pantahanan at Pangkabuhayan', '4'),
    ('G5-FIL', 'Filipino', '5'),
    ('G5-ENG', 'English', '5'),
    ('G5-MATH', 'Mathematics', '5'),
    ('G5-SCI', 'Science', '5'),
    ('G5-AP', 'Araling Panlipunan', '5'),
    ('G5-GMRCESP', 'GMRC / Edukasyon sa Pagpapakatao', '5'),
    ('G5-MUSIC', 'Music', '5'),
    ('G5-ARTS', 'Arts', '5'),
    ('G5-PE', 'Physical Education', '5'),
    ('G5-HEALTH', 'Health', '5'),
    ('G5-EPP', 'Edukasyong Pantahanan at Pangkabuhayan', '5'),
    ('G6-FIL', 'Filipino', '6'),
    ('G6-ENG', 'English', '6'),
    ('G6-MATH', 'Mathematics', '6'),
    ('G6-SCI', 'Science', '6'),
    ('G6-AP', 'Araling Panlipunan', '6'),
    ('G6-GMRCESP', 'GMRC / Edukasyon sa Pagpapakatao', '6'),
    ('G6-MUSIC', 'Music', '6'),
    ('G6-ARTS', 'Arts', '6'),
    ('G6-PE', 'Physical Education', '6'),
    ('G6-HEALTH', 'Health', '6'),
    ('G6-EPP', 'Edukasyong Pantahanan at Pangkabuhayan', '6')
)
INSERT INTO subjects (code, name, grade_level, units, department)
SELECT code, name, grade_level, 0, NULL
FROM seed_subjects
ON CONFLICT (code) DO UPDATE SET
    name = EXCLUDED.name,
    grade_level = EXCLUDED.grade_level,
    units = 0,
    department = NULL;

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

CREATE INDEX IF NOT EXISTS idx_students_middle_name
    ON students (middle_name);

CREATE INDEX IF NOT EXISTS idx_students_birth_profile
    ON students (last_name, first_name, middle_name, birthdate);

DO $$
DECLARE
    duplicate_psa_count BIGINT := 0;
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'students'
          AND column_name = 'psa_birth_certificate_no'
    ) THEN
        SELECT COUNT(*) INTO duplicate_psa_count
        FROM (
            SELECT lower(btrim(psa_birth_certificate_no))
            FROM students
            WHERE psa_birth_certificate_no IS NOT NULL
              AND btrim(psa_birth_certificate_no) <> ''
            GROUP BY lower(btrim(psa_birth_certificate_no))
            HAVING COUNT(*) > 1
        ) duplicate_psa_numbers;

        IF duplicate_psa_count = 0 THEN
            EXECUTE $sql$
                CREATE UNIQUE INDEX IF NOT EXISTS uq_students_psa_birth_certificate_no
                ON students ((lower(btrim(psa_birth_certificate_no))))
                WHERE psa_birth_certificate_no IS NOT NULL
                  AND btrim(psa_birth_certificate_no) <> ''
            $sql$;
        ELSE
            RAISE NOTICE 'Skipped PSA unique index because % duplicate PSA number group(s) already exist. Clean duplicates later, then create uq_students_psa_birth_certificate_no.', duplicate_psa_count;
        END IF;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'students'
          AND column_name = 'current_zip_code'
    ) AND EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'students'
          AND column_name = 'permanent_zip_code'
    ) AND NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'chk_students_ph_zip_codes'
          AND conrelid = 'students'::regclass
    ) THEN
        ALTER TABLE students
            ADD CONSTRAINT chk_students_ph_zip_codes
            CHECK (
                (current_zip_code IS NULL OR btrim(current_zip_code) = '' OR current_zip_code ~ '^[0-9]{4}$')
                AND
                (permanent_zip_code IS NULL OR btrim(permanent_zip_code) = '' OR permanent_zip_code ~ '^[0-9]{4}$')
            );
    END IF;
END
$$;

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

CREATE INDEX IF NOT EXISTS idx_guardians_name_full
    ON guardians (last_name, first_name, middle_name);

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

ALTER TABLE enrollment_documents
    ADD COLUMN IF NOT EXISTS review_status document_review_status NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS reviewer_note TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS reviewed_by INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP DEFAULT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'fk_enrollment_document_reviewer'
          AND conrelid = 'enrollment_documents'::regclass
    ) THEN
        ALTER TABLE enrollment_documents
            ADD CONSTRAINT fk_enrollment_document_reviewer
            FOREIGN KEY (reviewed_by) REFERENCES users(id)
            ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END$$;

CREATE INDEX IF NOT EXISTS idx_enrollment_documents_review_status
    ON enrollment_documents (review_status);

CREATE TABLE IF NOT EXISTS enrollment_assessments (
    id                  SERIAL PRIMARY KEY,
    enrollment_id       INT NOT NULL REFERENCES enrollments(id) ON DELETE CASCADE,
    status              assessment_status NOT NULL DEFAULT 'draft',
    total_amount        DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    notes               TEXT DEFAULT NULL,
    created_by          INT DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMP NOT NULL DEFAULT NOW(),
    sent_to_cashier_at  TIMESTAMP DEFAULT NULL,
    sent_by             INT DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_enrollment_assessments_enrollment
    ON enrollment_assessments (enrollment_id);

CREATE INDEX IF NOT EXISTS idx_enrollment_assessments_status
    ON enrollment_assessments (status);

CREATE TABLE IF NOT EXISTS enrollment_assessment_items (
    id              SERIAL PRIMARY KEY,
    assessment_id   INT NOT NULL REFERENCES enrollment_assessments(id) ON DELETE CASCADE,
    category        assessment_item_category NOT NULL,
    description     VARCHAR(255) NOT NULL DEFAULT '',
    amount          DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    sort_order      INT NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_enrollment_assessment_items_assessment
    ON enrollment_assessment_items (assessment_id);

UPDATE enrollments
SET status = 'returned'
WHERE status = 'rejected';

UPDATE enrollments
SET status = 'paid_for_registrar'
WHERE status = 'approved';

UPDATE enrollments e
SET status = 'paid_for_registrar'
WHERE e.status = 'pending'
  AND EXISTS (
      SELECT 1
      FROM payments p
      WHERE p.enrollment_id = e.id
        AND p.status = 'paid'
  );

UPDATE enrollments e
SET status = 'awaiting_payment'
WHERE e.status = 'pending'
  AND e.payment_submitted_at IS NOT NULL;

UPDATE enrollments e
SET status = 'documents_under_review'
WHERE e.status = 'pending'
  AND (
      SELECT COUNT(DISTINCT d.document_type)
      FROM enrollment_documents d
      WHERE d.enrollment_id = e.id
        AND d.document_type IN ('psa', 'medical', 'previous_school', 'parent_data')
  ) >= 4;

UPDATE enrollments e
SET status = 'requirements_incomplete'
WHERE e.status = 'pending'
  AND (
      SELECT COUNT(DISTINCT d.document_type)
      FROM enrollment_documents d
      WHERE d.enrollment_id = e.id
        AND d.document_type IN ('psa', 'medical', 'previous_school', 'parent_data')
  ) > 0;

UPDATE enrollments
SET status = 'submitted'
WHERE status = 'pending';

-- ============================================================
-- Done.
-- ============================================================

SELECT 'AGAPE-IIS single Supabase update completed.' AS result;

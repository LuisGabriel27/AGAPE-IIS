-- ============================================================
-- AGAPE AIIS Supabase Production Readiness Hardening
--
-- Run this in Supabase SQL Editor AFTER running:
-- database/supabase_production_readiness_preflight.sql
--
-- This migration is intentionally conservative:
-- - it adds constraints, indexes, and schedule-conflict protection;
-- - it creates missing support tables used by the current PHP app;
-- - it aborts if duplicate/invalid data would make constraints unsafe.
-- ============================================================

-- ------------------------------------------------------------
-- 1. Ensure current enum/support objects exist
-- ------------------------------------------------------------

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
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'document_review_status') THEN
        CREATE TYPE document_review_status AS ENUM ('pending', 'accepted', 'needs_replacement', 'missing');
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'assessment_status') THEN
        CREATE TYPE assessment_status AS ENUM ('draft', 'sent_to_cashier', 'cancelled');
    END IF;
END
$$;

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
END
$$;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL,
    role    user_role NOT NULL,
    PRIMARY KEY (user_id, role),
    CONSTRAINT fk_ur_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
);

INSERT INTO user_roles (user_id, role)
SELECT id, role
FROM users
ON CONFLICT DO NOTHING;

CREATE INDEX IF NOT EXISTS idx_user_roles_role
    ON user_roles (role);

-- ------------------------------------------------------------
-- 2. Ensure current enrollment review/payment assessment schema
-- ------------------------------------------------------------

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
END
$$;

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

-- ------------------------------------------------------------
-- 3. Normalize harmless values before enforcing constraints
-- ------------------------------------------------------------

UPDATE students
SET lrn = NULL
WHERE lrn IS NOT NULL
  AND btrim(lrn) = '';

UPDATE students
SET lrn = btrim(lrn)
WHERE lrn IS NOT NULL
  AND lrn <> btrim(lrn);

-- ------------------------------------------------------------
-- 4. Abort if current data is not safe for hard constraints
-- ------------------------------------------------------------

DO $$
DECLARE
    issue_count BIGINT;
BEGIN
    SELECT COUNT(*) INTO issue_count
    FROM (
        SELECT btrim(lrn)
        FROM students
        WHERE lrn IS NOT NULL AND btrim(lrn) <> ''
        GROUP BY btrim(lrn)
        HAVING COUNT(*) > 1
    ) x;
    IF issue_count > 0 THEN
        RAISE EXCEPTION 'Cannot add student LRN uniqueness: % duplicate LRN group(s) exist. Run the preflight SQL and clean duplicates first.', issue_count;
    END IF;

    SELECT COUNT(*) INTO issue_count
    FROM students
    WHERE lrn IS NOT NULL
      AND btrim(lrn) <> ''
      AND btrim(lrn) !~ '^[0-9]{12}$';
    IF issue_count > 0 THEN
        RAISE EXCEPTION 'Cannot add student LRN format check: % invalid LRN value(s) exist. LRNs must be exactly 12 digits.', issue_count;
    END IF;

    SELECT COUNT(*) INTO issue_count
    FROM (
        SELECT lower(btrim(first_name)), lower(btrim(last_name)), birthdate
        FROM students
        WHERE btrim(coalesce(first_name, '')) <> ''
          AND btrim(coalesce(last_name, '')) <> ''
          AND birthdate IS NOT NULL
        GROUP BY lower(btrim(first_name)), lower(btrim(last_name)), birthdate
        HAVING COUNT(*) > 1
    ) x;
    IF issue_count > 0 THEN
        RAISE EXCEPTION 'Cannot add student identity uniqueness: % duplicate name+birthdate group(s) exist.', issue_count;
    END IF;

    SELECT COUNT(*) INTO issue_count
    FROM (
        SELECT student_id, school_year, term
        FROM enrollments
        GROUP BY student_id, school_year, term
        HAVING COUNT(*) > 1
    ) x;
    IF issue_count > 0 THEN
        RAISE EXCEPTION 'Cannot add enrollment uniqueness: % duplicate student/year/term group(s) exist.', issue_count;
    END IF;

    SELECT COUNT(*) INTO issue_count
    FROM (
        SELECT student_id, subject_id, school_year, term
        FROM grades
        GROUP BY student_id, subject_id, school_year, term
        HAVING COUNT(*) > 1
    ) x;
    IF issue_count > 0 THEN
        RAISE EXCEPTION 'Cannot add grade uniqueness: % duplicate student/subject/year/term group(s) exist.', issue_count;
    END IF;

    SELECT COUNT(*) INTO issue_count
    FROM (
        SELECT user_id
        FROM guardians
        WHERE user_id IS NOT NULL
        GROUP BY user_id
        HAVING COUNT(*) > 1
    ) x;
    IF issue_count > 0 THEN
        RAISE EXCEPTION 'Cannot add guardian profile uniqueness: % duplicate user profile group(s) exist.', issue_count;
    END IF;

    SELECT COUNT(*) INTO issue_count
    FROM (
        SELECT user_id
        FROM teachers
        WHERE user_id IS NOT NULL
        GROUP BY user_id
        HAVING COUNT(*) > 1
    ) x;
    IF issue_count > 0 THEN
        RAISE EXCEPTION 'Cannot add teacher profile uniqueness: % duplicate user profile group(s) exist.', issue_count;
    END IF;

    SELECT COUNT(*) INTO issue_count
    FROM grades
    WHERE (quarter1 IS NOT NULL AND (quarter1 < 0 OR quarter1 > 100))
       OR (quarter2 IS NOT NULL AND (quarter2 < 0 OR quarter2 > 100))
       OR (quarter3 IS NOT NULL AND (quarter3 < 0 OR quarter3 > 100))
       OR (quarter4 IS NOT NULL AND (quarter4 < 0 OR quarter4 > 100))
       OR (final_grade IS NOT NULL AND (final_grade < 0 OR final_grade > 100));
    IF issue_count > 0 THEN
        RAISE EXCEPTION 'Cannot add grade range checks: % invalid grade row(s) exist.', issue_count;
    END IF;

    SELECT COUNT(*) INTO issue_count
    FROM schedules
    WHERE time_start >= time_end;
    IF issue_count > 0 THEN
        RAISE EXCEPTION 'Cannot add schedule time checks: % invalid schedule row(s) have start time >= end time.', issue_count;
    END IF;
END
$$;

-- ------------------------------------------------------------
-- 5. Add uniqueness and integrity indexes
-- ------------------------------------------------------------

CREATE UNIQUE INDEX IF NOT EXISTS uq_guardians_user_id
    ON guardians (user_id)
    WHERE user_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_teachers_user_id
    ON teachers (user_id)
    WHERE user_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_students_lrn_not_blank
    ON students ((btrim(lrn)))
    WHERE lrn IS NOT NULL AND btrim(lrn) <> '';

CREATE UNIQUE INDEX IF NOT EXISTS uq_students_identity_name_birthdate
    ON students ((lower(btrim(first_name))), (lower(btrim(last_name))), birthdate)
    WHERE btrim(coalesce(first_name, '')) <> ''
      AND btrim(coalesce(last_name, '')) <> ''
      AND birthdate IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_enrollments_student_school_year_term
    ON enrollments (student_id, school_year, term);

CREATE UNIQUE INDEX IF NOT EXISTS uq_grades_student_subject_school_year_term
    ON grades (student_id, subject_id, school_year, term);

CREATE UNIQUE INDEX IF NOT EXISTS uq_schedules_exact_class_slot
    ON schedules (subject_id, section_id, teacher_id, day_of_week, time_start, time_end, school_year, term);

-- ------------------------------------------------------------
-- 6. Add validation constraints
-- ------------------------------------------------------------

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_students_lrn_format'
          AND conrelid = 'students'::regclass
    ) THEN
        ALTER TABLE students
            ADD CONSTRAINT chk_students_lrn_format
            CHECK (lrn IS NULL OR btrim(lrn) = '' OR btrim(lrn) ~ '^[0-9]{12}$');
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_sections_capacity_positive'
          AND conrelid = 'sections'::regclass
    ) THEN
        ALTER TABLE sections
            ADD CONSTRAINT chk_sections_capacity_positive
            CHECK (capacity > 0);
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_schedules_time_order'
          AND conrelid = 'schedules'::regclass
    ) THEN
        ALTER TABLE schedules
            ADD CONSTRAINT chk_schedules_time_order
            CHECK (time_start < time_end);
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_grades_range'
          AND conrelid = 'grades'::regclass
    ) THEN
        ALTER TABLE grades
            ADD CONSTRAINT chk_grades_range
            CHECK (
                (quarter1 IS NULL OR (quarter1 >= 0 AND quarter1 <= 100)) AND
                (quarter2 IS NULL OR (quarter2 >= 0 AND quarter2 <= 100)) AND
                (quarter3 IS NULL OR (quarter3 >= 0 AND quarter3 <= 100)) AND
                (quarter4 IS NULL OR (quarter4 >= 0 AND quarter4 <= 100)) AND
                (final_grade IS NULL OR (final_grade >= 0 AND final_grade <= 100))
            );
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_payments_amount_nonnegative'
          AND conrelid = 'payments'::regclass
    ) THEN
        ALTER TABLE payments
            ADD CONSTRAINT chk_payments_amount_nonnegative
            CHECK (amount >= 0);
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_enrollment_documents_file_size_nonnegative'
          AND conrelid = 'enrollment_documents'::regclass
    ) THEN
        ALTER TABLE enrollment_documents
            ADD CONSTRAINT chk_enrollment_documents_file_size_nonnegative
            CHECK (file_size IS NULL OR file_size >= 0);
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_attendance_confidence_range'
          AND conrelid = 'attendance_logs'::regclass
    ) THEN
        ALTER TABLE attendance_logs
            ADD CONSTRAINT chk_attendance_confidence_range
            CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1));
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_enrollment_assessments_total_nonnegative'
          AND conrelid = 'enrollment_assessments'::regclass
    ) THEN
        ALTER TABLE enrollment_assessments
            ADD CONSTRAINT chk_enrollment_assessments_total_nonnegative
            CHECK (total_amount >= 0);
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_enrollment_assessment_items_amount_nonnegative'
          AND conrelid = 'enrollment_assessment_items'::regclass
    ) THEN
        ALTER TABLE enrollment_assessment_items
            ADD CONSTRAINT chk_enrollment_assessment_items_amount_nonnegative
            CHECK (amount >= 0);
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_users_failed_attempts_nonnegative'
          AND conrelid = 'users'::regclass
    ) THEN
        ALTER TABLE users
            ADD CONSTRAINT chk_users_failed_attempts_nonnegative
            CHECK (failed_attempts >= 0);
    END IF;
END
$$;

-- ------------------------------------------------------------
-- 7. Add performance/authorization lookup indexes
-- ------------------------------------------------------------

CREATE INDEX IF NOT EXISTS idx_students_guardian_section
    ON students (guardian_id, section_id);

CREATE INDEX IF NOT EXISTS idx_grades_student_subject_year_term
    ON grades (student_id, subject_id, school_year, term);

CREATE INDEX IF NOT EXISTS idx_grades_published_student_year
    ON grades (student_id, school_year, published);

CREATE INDEX IF NOT EXISTS idx_schedules_teacher_year_term
    ON schedules (teacher_id, school_year, term);

CREATE INDEX IF NOT EXISTS idx_schedules_section_year_term
    ON schedules (section_id, school_year, term);

CREATE INDEX IF NOT EXISTS idx_attendance_logs_student_date
    ON attendance_logs (student_id, attendance_date);

CREATE INDEX IF NOT EXISTS idx_enrollments_status_year_term
    ON enrollments (status, school_year, term);

CREATE INDEX IF NOT EXISTS idx_payments_enrollment_status
    ON payments (enrollment_id, status);

-- ------------------------------------------------------------
-- 8. Prevent schedule conflicts on future inserts/updates
-- ------------------------------------------------------------

CREATE OR REPLACE FUNCTION agape_prevent_schedule_conflicts()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    conflict_id INT;
BEGIN
    IF NEW.time_start >= NEW.time_end THEN
        RAISE EXCEPTION 'Schedule start time must be earlier than end time.';
    END IF;

    SELECT s.id INTO conflict_id
    FROM schedules s
    WHERE s.id <> COALESCE(NEW.id, -1)
      AND s.teacher_id = NEW.teacher_id
      AND s.school_year = NEW.school_year
      AND s.term = NEW.term
      AND s.day_of_week = NEW.day_of_week
      AND NEW.time_start < s.time_end
      AND NEW.time_end > s.time_start
    LIMIT 1;

    IF conflict_id IS NOT NULL THEN
        RAISE EXCEPTION 'Teacher schedule conflict with schedule id %.', conflict_id;
    END IF;

    SELECT s.id INTO conflict_id
    FROM schedules s
    WHERE s.id <> COALESCE(NEW.id, -1)
      AND s.section_id = NEW.section_id
      AND s.school_year = NEW.school_year
      AND s.term = NEW.term
      AND s.day_of_week = NEW.day_of_week
      AND NEW.time_start < s.time_end
      AND NEW.time_end > s.time_start
    LIMIT 1;

    IF conflict_id IS NOT NULL THEN
        RAISE EXCEPTION 'Section schedule conflict with schedule id %.', conflict_id;
    END IF;

    IF btrim(coalesce(NEW.room, '')) <> '' THEN
        SELECT s.id INTO conflict_id
        FROM schedules s
        WHERE s.id <> COALESCE(NEW.id, -1)
          AND lower(btrim(coalesce(s.room, ''))) = lower(btrim(NEW.room))
          AND s.school_year = NEW.school_year
          AND s.term = NEW.term
          AND s.day_of_week = NEW.day_of_week
          AND NEW.time_start < s.time_end
          AND NEW.time_end > s.time_start
        LIMIT 1;

        IF conflict_id IS NOT NULL THEN
            RAISE EXCEPTION 'Room schedule conflict with schedule id %.', conflict_id;
        END IF;
    END IF;

    RETURN NEW;
END
$$;

DROP TRIGGER IF EXISTS trg_schedules_prevent_conflicts ON schedules;

CREATE TRIGGER trg_schedules_prevent_conflicts
BEFORE INSERT OR UPDATE ON schedules
FOR EACH ROW
EXECUTE FUNCTION agape_prevent_schedule_conflicts();

-- ------------------------------------------------------------
-- Done.
-- ------------------------------------------------------------

SELECT 'AGAPE AIIS production readiness hardening migration completed.' AS result;

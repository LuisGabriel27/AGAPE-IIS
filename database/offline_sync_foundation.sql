-- ============================================================
-- AGAPE AIIS Offline Sync Foundation
--
-- Purpose:
-- - local Docker database records local writes into sync_outbox;
-- - a later sync worker can push those changes to Supabase when online.
--
-- This is foundation only. Conflict handling and remote apply logic must be
-- implemented in the sync worker before calling the system "offline-first".
-- ============================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE IF NOT EXISTS sync_state (
    key        VARCHAR(100) PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

INSERT INTO sync_state (key, value)
VALUES ('local_node_id', gen_random_uuid()::TEXT)
ON CONFLICT (key) DO NOTHING;

CREATE TABLE IF NOT EXISTS sync_outbox (
    id            BIGSERIAL PRIMARY KEY,
    table_name    VARCHAR(100) NOT NULL,
    operation     VARCHAR(10) NOT NULL CHECK (operation IN ('INSERT', 'UPDATE', 'DELETE')),
    record_pk     TEXT NOT NULL,
    row_data      JSONB NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    synced_at     TIMESTAMP DEFAULT NULL,
    attempt_count INT NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_sync_outbox_pending
    ON sync_outbox (synced_at, id)
    WHERE synced_at IS NULL;

CREATE OR REPLACE FUNCTION agape_sync_enqueue()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    payload JSONB;
    pk TEXT;
BEGIN
    IF current_setting('agape.sync_disabled', true) = 'on' THEN
        RETURN COALESCE(NEW, OLD);
    END IF;

    payload := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
    pk := COALESCE(payload ->> 'id', payload ->> 'key');

    IF pk IS NULL OR pk = '' THEN
        RETURN COALESCE(NEW, OLD);
    END IF;

    INSERT INTO sync_outbox (table_name, operation, record_pk, row_data)
    VALUES (TG_TABLE_NAME, TG_OP, pk, payload);

    RETURN COALESCE(NEW, OLD);
END;
$$;

DROP TRIGGER IF EXISTS trg_sync_users ON users;
CREATE TRIGGER trg_sync_users AFTER INSERT OR UPDATE OR DELETE ON users
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_user_roles ON user_roles;
CREATE TRIGGER trg_sync_user_roles AFTER INSERT OR UPDATE OR DELETE ON user_roles
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_guardians ON guardians;
CREATE TRIGGER trg_sync_guardians AFTER INSERT OR UPDATE OR DELETE ON guardians
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_teachers ON teachers;
CREATE TRIGGER trg_sync_teachers AFTER INSERT OR UPDATE OR DELETE ON teachers
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_user_profiles ON user_profiles;
CREATE TRIGGER trg_sync_user_profiles AFTER INSERT OR UPDATE OR DELETE ON user_profiles
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_subjects ON subjects;
CREATE TRIGGER trg_sync_subjects AFTER INSERT OR UPDATE OR DELETE ON subjects
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_sections ON sections;
CREATE TRIGGER trg_sync_sections AFTER INSERT OR UPDATE OR DELETE ON sections
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_students ON students;
CREATE TRIGGER trg_sync_students AFTER INSERT OR UPDATE OR DELETE ON students
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_enrollments ON enrollments;
CREATE TRIGGER trg_sync_enrollments AFTER INSERT OR UPDATE OR DELETE ON enrollments
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_enrollment_documents ON enrollment_documents;
CREATE TRIGGER trg_sync_enrollment_documents AFTER INSERT OR UPDATE OR DELETE ON enrollment_documents
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_enrollment_assessments ON enrollment_assessments;
CREATE TRIGGER trg_sync_enrollment_assessments AFTER INSERT OR UPDATE OR DELETE ON enrollment_assessments
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_enrollment_assessment_items ON enrollment_assessment_items;
CREATE TRIGGER trg_sync_enrollment_assessment_items AFTER INSERT OR UPDATE OR DELETE ON enrollment_assessment_items
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_grades ON grades;
CREATE TRIGGER trg_sync_grades AFTER INSERT OR UPDATE OR DELETE ON grades
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_schedules ON schedules;
CREATE TRIGGER trg_sync_schedules AFTER INSERT OR UPDATE OR DELETE ON schedules
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_calendar_events ON calendar_events;
CREATE TRIGGER trg_sync_calendar_events AFTER INSERT OR UPDATE OR DELETE ON calendar_events
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_payments ON payments;
CREATE TRIGGER trg_sync_payments AFTER INSERT OR UPDATE OR DELETE ON payments
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_student_face_profiles ON student_face_profiles;
CREATE TRIGGER trg_sync_student_face_profiles AFTER INSERT OR UPDATE OR DELETE ON student_face_profiles
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_attendance_logs ON attendance_logs;
CREATE TRIGGER trg_sync_attendance_logs AFTER INSERT OR UPDATE OR DELETE ON attendance_logs
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_settings ON settings;
CREATE TRIGGER trg_sync_settings AFTER INSERT OR UPDATE OR DELETE ON settings
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

DROP TRIGGER IF EXISTS trg_sync_audit_log ON audit_log;
CREATE TRIGGER trg_sync_audit_log AFTER INSERT OR UPDATE OR DELETE ON audit_log
FOR EACH ROW EXECUTE FUNCTION agape_sync_enqueue();

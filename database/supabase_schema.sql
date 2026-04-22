-- ============================================================
-- AGAPE-IIS — Full PostgreSQL Schema for Supabase
-- Run this in Supabase SQL Editor (Dashboard → SQL Editor)
-- ============================================================

-- ── Custom ENUM Types ───────────────────────────────────────
CREATE TYPE user_role AS ENUM ('admin', 'teacher', 'guardian');
CREATE TYPE gender_type AS ENUM ('male', 'female', 'other');
CREATE TYPE enrollment_status AS ENUM ('pending', 'approved', 'rejected', 'enrolled', 'archived');
CREATE TYPE event_type AS ENUM ('holiday', 'exam', 'event', 'other');
CREATE TYPE payment_method AS ENUM ('cash', 'online', 'bank');
CREATE TYPE payment_status AS ENUM ('paid', 'pending', 'failed');
CREATE TYPE attendance_status_type AS ENUM ('present', 'late', 'absent');
CREATE TYPE attendance_method AS ENUM ('face', 'manual');
CREATE TYPE day_of_week_type AS ENUM ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday');
CREATE TYPE calendar_source AS ENUM ('manual', 'deped');

-- ============================================================
-- 1. users
-- ============================================================
CREATE TABLE users (
    id              SERIAL PRIMARY KEY,
    email           VARCHAR(255) NOT NULL,
    password_hash   VARCHAR(255) DEFAULT NULL,
    google_id       VARCHAR(255) DEFAULT NULL,
    google_avatar   VARCHAR(500) DEFAULT NULL,
    role            user_role NOT NULL DEFAULT 'guardian',
    is_active       SMALLINT NOT NULL DEFAULT 1,
    failed_attempts INT NOT NULL DEFAULT 0,
    lockout_until   TIMESTAMP DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    last_login      TIMESTAMP DEFAULT NULL,
    CONSTRAINT uq_email UNIQUE (email),
    CONSTRAINT uq_google_id UNIQUE (google_id)
);

-- ============================================================
-- 2. guardians
-- ============================================================
CREATE TABLE guardians (
    id                      SERIAL PRIMARY KEY,
    user_id                 INT NOT NULL,
    full_name               VARCHAR(255) NOT NULL,
    contact_number          VARCHAR(50) DEFAULT NULL,
    address                 TEXT DEFAULT NULL,
    relationship_to_student VARCHAR(100) DEFAULT NULL,
    CONSTRAINT fk_guardian_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX idx_guardian_user ON guardians (user_id);

-- ============================================================
-- 3. teachers
-- ============================================================
CREATE TABLE teachers (
    id              SERIAL PRIMARY KEY,
    user_id         INT NOT NULL,
    full_name       VARCHAR(255) NOT NULL,
    contact_number  VARCHAR(50) DEFAULT NULL,
    department      VARCHAR(100) DEFAULT NULL,
    CONSTRAINT fk_teacher_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX idx_teacher_user ON teachers (user_id);

-- ============================================================
-- 4. subjects
-- ============================================================
CREATE TABLE subjects (
    id          SERIAL PRIMARY KEY,
    code        VARCHAR(20) NOT NULL,
    name        VARCHAR(255) NOT NULL,
    units       SMALLINT NOT NULL DEFAULT 3,
    department  VARCHAR(100) DEFAULT NULL,
    CONSTRAINT uq_subject_code UNIQUE (code)
);

-- ============================================================
-- 5. sections
-- ============================================================
CREATE TABLE sections (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    grade_level VARCHAR(20) NOT NULL,
    adviser_id  INT DEFAULT NULL,
    capacity    INT NOT NULL DEFAULT 40,
    CONSTRAINT fk_section_adviser
        FOREIGN KEY (adviser_id) REFERENCES teachers (id)
        ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX idx_section_adviser ON sections (adviser_id);

-- ============================================================
-- 6. students
-- ============================================================
CREATE TABLE students (
    id            SERIAL PRIMARY KEY,
    guardian_id   INT DEFAULT NULL,
    full_name     VARCHAR(255) NOT NULL,
    birthdate     DATE DEFAULT NULL,
    gender        gender_type DEFAULT NULL,
    grade_level   VARCHAR(20) DEFAULT NULL,
    section_id    INT DEFAULT NULL,
    lrn           VARCHAR(30) DEFAULT NULL,
    profile_photo VARCHAR(500) DEFAULT NULL,
    CONSTRAINT fk_student_guardian
        FOREIGN KEY (guardian_id) REFERENCES guardians (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_student_section
        FOREIGN KEY (section_id) REFERENCES sections (id)
        ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX idx_student_guardian ON students (guardian_id);
CREATE INDEX idx_student_section ON students (section_id);

-- ============================================================
-- 7. enrollments
-- ============================================================
CREATE TABLE enrollments (
    id                   SERIAL PRIMARY KEY,
    student_id           INT NOT NULL,
    school_year          VARCHAR(20) NOT NULL,
    term                 VARCHAR(20) NOT NULL,
    status               enrollment_status NOT NULL DEFAULT 'pending',
    remarks              TEXT DEFAULT NULL,
    enrolled_at          TIMESTAMP DEFAULT NULL,
    payment_submitted_at TIMESTAMP DEFAULT NULL,
    CONSTRAINT fk_enrollment_student
        FOREIGN KEY (student_id) REFERENCES students (id)
        ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX idx_enrollment_student ON enrollments (student_id);
CREATE INDEX idx_enrollment_status ON enrollments (status);
CREATE INDEX idx_enrollment_year ON enrollments (school_year);

-- ============================================================
-- 8. grades
-- ============================================================
CREATE TABLE grades (
    id            SERIAL PRIMARY KEY,
    student_id    INT NOT NULL,
    subject_id    INT NOT NULL,
    school_year   VARCHAR(20) NOT NULL,
    term          VARCHAR(20) NOT NULL,
    midterm       DECIMAL(5,2) DEFAULT NULL,
    finals        DECIMAL(5,2) DEFAULT NULL,
    final_grade   DECIMAL(5,2) DEFAULT NULL,
    submitted_by  INT DEFAULT NULL,
    submitted_at  TIMESTAMP DEFAULT NULL,
    updated_at    TIMESTAMP DEFAULT NULL,
    published     SMALLINT NOT NULL DEFAULT 0,
    CONSTRAINT fk_grade_student
        FOREIGN KEY (student_id) REFERENCES students (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_grade_subject
        FOREIGN KEY (subject_id) REFERENCES subjects (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_grade_teacher
        FOREIGN KEY (submitted_by) REFERENCES teachers (id)
        ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX idx_grade_student ON grades (student_id);
CREATE INDEX idx_grade_subject ON grades (subject_id);
CREATE INDEX idx_grade_teacher ON grades (submitted_by);

-- ============================================================
-- 9. schedules
-- ============================================================
CREATE TABLE schedules (
    id            SERIAL PRIMARY KEY,
    subject_id    INT NOT NULL,
    section_id    INT NOT NULL,
    teacher_id    INT NOT NULL,
    room          VARCHAR(50) DEFAULT NULL,
    day_of_week   day_of_week_type NOT NULL,
    time_start    TIME NOT NULL,
    time_end      TIME NOT NULL,
    school_year   VARCHAR(20) NOT NULL,
    term          VARCHAR(20) NOT NULL,
    CONSTRAINT fk_schedule_subject
        FOREIGN KEY (subject_id) REFERENCES subjects (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_section
        FOREIGN KEY (section_id) REFERENCES sections (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_teacher
        FOREIGN KEY (teacher_id) REFERENCES teachers (id)
        ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX idx_schedule_subject ON schedules (subject_id);
CREATE INDEX idx_schedule_section ON schedules (section_id);
CREATE INDEX idx_schedule_teacher ON schedules (teacher_id);

-- ============================================================
-- 10. calendar_events
-- ============================================================
CREATE TABLE calendar_events (
    id          SERIAL PRIMARY KEY,
    title       VARCHAR(255) NOT NULL,
    date_start  DATE NOT NULL,
    date_end    DATE NOT NULL,
    type        event_type NOT NULL DEFAULT 'event',
    description TEXT DEFAULT NULL,
    created_by  INT DEFAULT NULL,
    source      calendar_source NOT NULL DEFAULT 'manual',
    school_year VARCHAR(20) NOT NULL DEFAULT '',
    CONSTRAINT fk_cal_creator
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX idx_cal_dates ON calendar_events (date_start, date_end);

-- ============================================================
-- 11. payments
-- ============================================================
CREATE TABLE payments (
    id              SERIAL PRIMARY KEY,
    enrollment_id   INT NOT NULL,
    amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    method          payment_method NOT NULL DEFAULT 'cash',
    reference_no    VARCHAR(100) DEFAULT NULL,
    description     VARCHAR(255) DEFAULT NULL,
    status          payment_status NOT NULL DEFAULT 'pending',
    paid_at         TIMESTAMP DEFAULT NULL,
    recorded_by     INT DEFAULT NULL,
    CONSTRAINT fk_payment_enrollment
        FOREIGN KEY (enrollment_id) REFERENCES enrollments (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_recorder
        FOREIGN KEY (recorded_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX idx_payment_enrollment ON payments (enrollment_id);
CREATE INDEX idx_payment_status ON payments (status);

-- ============================================================
-- 12. audit_log
-- ============================================================
CREATE TABLE audit_log (
    id              SERIAL PRIMARY KEY,
    user_id         INT DEFAULT NULL,
    action          VARCHAR(100) NOT NULL,
    table_affected  VARCHAR(100) DEFAULT NULL,
    record_id       INT DEFAULT NULL,
    old_value       JSONB DEFAULT NULL,
    new_value       JSONB DEFAULT NULL,
    ip_address      VARCHAR(45) DEFAULT NULL,
    timestamp       TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX idx_audit_user ON audit_log (user_id);
CREATE INDEX idx_audit_time ON audit_log (timestamp);

-- ============================================================
-- 13. student_face_profiles
-- ============================================================
CREATE TABLE student_face_profiles (
    id               SERIAL PRIMARY KEY,
    student_id       INT NOT NULL,
    face_descriptor  TEXT NOT NULL,
    face_image_path  VARCHAR(500) DEFAULT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_face_profile_student UNIQUE (student_id),
    CONSTRAINT fk_face_profile_student
        FOREIGN KEY (student_id) REFERENCES students (id)
        ON DELETE CASCADE ON UPDATE CASCADE
);

-- ============================================================
-- 14. attendance_logs
-- ============================================================
CREATE TABLE attendance_logs (
    id                 SERIAL PRIMARY KEY,
    student_id         INT NOT NULL,
    attendance_date    DATE NOT NULL,
    attendance_status  attendance_status_type NOT NULL DEFAULT 'present',
    method             attendance_method NOT NULL DEFAULT 'face',
    confidence         DECIMAL(6,5) DEFAULT NULL,
    marked_by          INT DEFAULT NULL,
    marked_at          TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_attendance_student_day UNIQUE (student_id, attendance_date),
    CONSTRAINT fk_attendance_student
        FOREIGN KEY (student_id) REFERENCES students (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_marker
        FOREIGN KEY (marked_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX idx_attendance_date ON attendance_logs (attendance_date);

-- ============================================================
-- 15. settings
-- ============================================================
CREATE TABLE settings (
    "key"   VARCHAR(100) NOT NULL,
    "value" TEXT NOT NULL,
    PRIMARY KEY ("key")
);

-- ============================================================
-- Trigger: auto-update updated_at on student_face_profiles
-- (replaces MySQL ON UPDATE CURRENT_TIMESTAMP)
-- ============================================================
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_face_profiles_updated_at
    BEFORE UPDATE ON student_face_profiles
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER trg_grades_updated_at
    BEFORE UPDATE ON grades
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- ============================================================
-- Seed: Settings
-- ============================================================
INSERT INTO settings ("key", "value") VALUES
    ('active_school_year', '2024-2025'),
    ('attendance_module_enabled', '1');

-- ============================================================
-- Seed: Default admin account
-- Email: admin@academy.edu  Password: Admin@1234
-- ============================================================
INSERT INTO users (email, password_hash, role, is_active, created_at)
VALUES (
    'admin@academy.edu',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    1,
    NOW()
);

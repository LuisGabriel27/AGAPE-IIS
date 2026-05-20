-- ============================================================
-- AGAPE AIIS Profile Fields Expansion
--
-- Adds DepEd-aligned learner/parent fields and personnel-style
-- teacher/admin profile fields.
--
-- Run after:
-- 1. database/supabase_production_readiness_hardening.sql
-- 2. database/supabase_email_case_hardening.sql
-- ============================================================

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'civil_status_type') THEN
        CREATE TYPE civil_status_type AS ENUM (
            'single',
            'married',
            'widowed',
            'separated',
            'others'
        );
    END IF;
END
$$;

-- ------------------------------------------------------------
-- Students: DepEd enrollment / SF1 / LIS aligned fields
-- ------------------------------------------------------------

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

CREATE INDEX IF NOT EXISTS idx_students_middle_name
    ON students (middle_name);

CREATE INDEX IF NOT EXISTS idx_students_birth_profile
    ON students (last_name, first_name, middle_name, birthdate);

-- ------------------------------------------------------------
-- Guardians: fuller parent/legal guardian profile
-- ------------------------------------------------------------

ALTER TABLE guardians
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(100) DEFAULT '',
    ADD COLUMN IF NOT EXISTS extension_name VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS occupation VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS civil_status civil_status_type DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS nationality VARCHAR(100) DEFAULT 'Filipino',
    ADD COLUMN IF NOT EXISTS religion VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_name VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_number VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS data_privacy_consent BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS data_privacy_consented_at TIMESTAMP DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_guardians_name_full
    ON guardians (last_name, first_name, middle_name);

-- ------------------------------------------------------------
-- Teachers: personnel / PRC / employment fields
-- ------------------------------------------------------------

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

CREATE UNIQUE INDEX IF NOT EXISTS uq_teachers_employee_number_not_blank
    ON teachers ((lower(btrim(employee_number))))
    WHERE employee_number IS NOT NULL AND btrim(employee_number) <> '';

CREATE UNIQUE INDEX IF NOT EXISTS uq_teachers_prc_license_not_blank
    ON teachers ((lower(btrim(prc_license_no))))
    WHERE prc_license_no IS NOT NULL AND btrim(prc_license_no) <> '';

-- ------------------------------------------------------------
-- Admin/clerk/general user profile records
-- ------------------------------------------------------------

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

CREATE UNIQUE INDEX IF NOT EXISTS uq_user_profiles_employee_number_not_blank
    ON user_profiles ((lower(btrim(employee_number))))
    WHERE employee_number IS NOT NULL AND btrim(employee_number) <> '';

SELECT 'AGAPE AIIS profile fields expansion completed.' AS result;

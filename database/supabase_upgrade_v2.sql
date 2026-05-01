-- ============================================================
-- AGAPE-IIS Supabase Upgrade v2 (PostgreSQL)
-- Run once in: Supabase Dashboard → SQL Editor → New Query
-- ============================================================

-- 1. Add 'clerk' to the existing user_role ENUM type
--    (IF NOT EXISTS prevents error on re-run)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_enum
        WHERE enumlabel = 'clerk'
          AND enumtypid = 'user_role'::regtype
    ) THEN
        ALTER TYPE user_role ADD VALUE 'clerk';
    END IF;
END$$;

-- 2. Civil status enum type (new)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'civil_status_type') THEN
        CREATE TYPE civil_status_type AS ENUM (
            'single', 'married', 'widowed', 'separated', 'others'
        );
    END IF;
END$$;

-- 3. Dual-role junction table
CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT  NOT NULL,
    role    user_role NOT NULL,
    PRIMARY KEY (user_id, role),
    CONSTRAINT fk_ur_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
);

-- Populate user_roles from existing users (skip duplicates)
INSERT INTO user_roles (user_id, role)
    SELECT id, role FROM users
ON CONFLICT DO NOTHING;

-- 4. Expanded guardian profile fields
--    ADD COLUMN IF NOT EXISTS is supported in PostgreSQL 9.6+
ALTER TABLE guardians
    ADD COLUMN IF NOT EXISTS occupation              VARCHAR(100)      DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS civil_status            civil_status_type DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS nationality             VARCHAR(100)      DEFAULT 'Filipino',
    ADD COLUMN IF NOT EXISTS religion                VARCHAR(100)      DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_name  VARCHAR(255)      DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS emergency_contact_number VARCHAR(50)      DEFAULT NULL;

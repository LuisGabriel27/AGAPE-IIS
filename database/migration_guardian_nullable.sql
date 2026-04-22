-- ============================================================
-- Migration: Make guardian_id nullable on students table
-- Run this in Supabase SQL Editor to apply the schema change
-- ============================================================

-- 1. Drop the existing NOT NULL constraint on guardian_id
ALTER TABLE students ALTER COLUMN guardian_id DROP NOT NULL;

-- 2. Set default to NULL
ALTER TABLE students ALTER COLUMN guardian_id SET DEFAULT NULL;

-- 3. Drop the existing foreign key constraint
ALTER TABLE students DROP CONSTRAINT IF EXISTS fk_student_guardian;

-- 4. Re-add with ON DELETE SET NULL (instead of CASCADE)
ALTER TABLE students ADD CONSTRAINT fk_student_guardian
    FOREIGN KEY (guardian_id) REFERENCES guardians (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

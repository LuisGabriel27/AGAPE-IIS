-- ============================================================
-- RESET: Drop all tables and types before re-creating schema
-- Run this FIRST in Supabase SQL Editor, then run supabase_schema.sql
-- ============================================================

-- Drop tables (reverse dependency order)
DROP TABLE IF EXISTS attendance_logs CASCADE;
DROP TABLE IF EXISTS student_face_profiles CASCADE;
DROP TABLE IF EXISTS audit_log CASCADE;
DROP TABLE IF EXISTS payments CASCADE;
DROP TABLE IF EXISTS calendar_events CASCADE;
DROP TABLE IF EXISTS schedules CASCADE;
DROP TABLE IF EXISTS grades CASCADE;
DROP TABLE IF EXISTS enrollments CASCADE;
DROP TABLE IF EXISTS students CASCADE;
DROP TABLE IF EXISTS sections CASCADE;
DROP TABLE IF EXISTS subjects CASCADE;
DROP TABLE IF EXISTS teachers CASCADE;
DROP TABLE IF EXISTS guardians CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS settings CASCADE;

-- Drop trigger function
DROP FUNCTION IF EXISTS update_updated_at_column() CASCADE;

-- Drop all custom ENUM types
DROP TYPE IF EXISTS user_role CASCADE;
DROP TYPE IF EXISTS gender_type CASCADE;
DROP TYPE IF EXISTS enrollment_status CASCADE;
DROP TYPE IF EXISTS event_type CASCADE;
DROP TYPE IF EXISTS payment_method CASCADE;
DROP TYPE IF EXISTS payment_status CASCADE;
DROP TYPE IF EXISTS attendance_status_type CASCADE;
DROP TYPE IF EXISTS attendance_method CASCADE;
DROP TYPE IF EXISTS day_of_week_type CASCADE;
DROP TYPE IF EXISTS calendar_source CASCADE;

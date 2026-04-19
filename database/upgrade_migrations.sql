-- ============================================================
-- AGAPE-IIS System Upgrade Migrations
-- Run this ONCE after deploying the updated PHP files.
-- Order matters — run from top to bottom.
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- UPGRADE 1: DepEd School Calendar Integration
-- ────────────────────────────────────────────────────────────

-- Add source and school_year columns to calendar_events
ALTER TABLE `calendar_events`
    ADD COLUMN `source` ENUM('manual','deped') NOT NULL DEFAULT 'manual' AFTER `created_by`,
    ADD COLUMN `school_year` VARCHAR(20) NOT NULL DEFAULT '' AFTER `source`;

-- ────────────────────────────────────────────────────────────
-- UPGRADE 2: School Year Reset
-- ────────────────────────────────────────────────────────────

-- Create settings table
CREATE TABLE IF NOT EXISTS `settings` (
    `key`   VARCHAR(100) PRIMARY KEY,
    `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the active school year
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('active_school_year', '2024-2025');

-- Add 'archived' to enrollment status ENUM
ALTER TABLE `enrollments`
    MODIFY COLUMN `status` ENUM('pending','approved','rejected','enrolled','archived') NOT NULL DEFAULT 'pending';

-- ────────────────────────────────────────────────────────────
-- UPGRADE 4: Teacher Module Enhancements — Grade Publishing
-- ────────────────────────────────────────────────────────────

-- Add published column to grades
ALTER TABLE `grades`
    ADD COLUMN `published` TINYINT(1) NOT NULL DEFAULT 0 AFTER `updated_at`;

-- (OPTIONAL) If you want all existing grades to be immediately visible
-- to guardians, uncomment the line below:
-- UPDATE `grades` SET `published` = 1;

-- ============================================================
-- Migration complete. Verify by checking:
--   DESCRIBE calendar_events;
--   DESCRIBE settings;
--   DESCRIBE enrollments;
--   DESCRIBE grades;
-- ============================================================

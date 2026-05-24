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

-- Add payment submission timestamp to enrollment workflow
ALTER TABLE `enrollments`
    ADD COLUMN IF NOT EXISTS `payment_submitted_at` DATETIME DEFAULT NULL AFTER `enrolled_at`;

-- Seed attendance module toggle
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('attendance_module_enabled', '1');

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
-- UPGRADE 5: Enrollment Requirement Uploads
-- ============================================================

CREATE TABLE IF NOT EXISTS `enrollment_documents` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `enrollment_id` INT UNSIGNED    NOT NULL,
  `document_type` VARCHAR(50)     NOT NULL,
  `original_name` VARCHAR(255)    NOT NULL,
  `file_path`     VARCHAR(500)    NOT NULL,
  `mime_type`     VARCHAR(100)    DEFAULT NULL,
  `file_size`     BIGINT UNSIGNED DEFAULT NULL,
  `uploaded_by`   INT UNSIGNED    DEFAULT NULL,
  `uploaded_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enrollment_document_type` (`enrollment_id`, `document_type`),
  KEY `idx_enrollment_documents_enrollment` (`enrollment_id`),
  KEY `idx_enrollment_documents_uploaded_by` (`uploaded_by`),
  CONSTRAINT `chk_enrollment_document_type`
    CHECK (`document_type` IN ('psa', 'medical', 'previous_school', 'parent_data')),
  CONSTRAINT `fk_enrollment_document_enrollment`
    FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_enrollment_document_uploader`
    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Migration complete. Verify by checking:
--   DESCRIBE calendar_events;
--   DESCRIBE settings;
--   DESCRIBE enrollments;
--   DESCRIBE enrollment_documents;
--   DESCRIBE grades;
--   SELECT `key`, `value` FROM settings WHERE `key` IN ('active_school_year', 'attendance_module_enabled');
-- ============================================================

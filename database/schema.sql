-- ============================================================
-- Academy Information System — Full MySQL Schema
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- Import via phpMyAdmin or mysql CLI
-- ============================================================

CREATE DATABASE IF NOT EXISTS `academy_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `academy_db`;

-- ============================================================
-- 1. users
-- ============================================================
CREATE TABLE `users` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `email`           VARCHAR(255)    NOT NULL,
  `password_hash`   VARCHAR(255)    DEFAULT NULL,
  `google_id`       VARCHAR(255)    DEFAULT NULL,
  `google_avatar`   VARCHAR(500)    DEFAULT NULL,
  `role`            ENUM('admin','teacher','guardian') NOT NULL DEFAULT 'guardian',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `failed_attempts` INT UNSIGNED    NOT NULL DEFAULT 0,
  `lockout_until`   DATETIME        DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login`      DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email`     (`email`),
  UNIQUE KEY `uq_google_id` (`google_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. guardians
-- ============================================================
CREATE TABLE `guardians` (
  `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`               INT UNSIGNED    NOT NULL,
  `first_name`            VARCHAR(100)    NOT NULL DEFAULT '',
  `last_name`             VARCHAR(155)    NOT NULL,
  `contact_number`        VARCHAR(50)     DEFAULT NULL,
  `address`               TEXT            DEFAULT NULL,
  `relationship_to_student` VARCHAR(100)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_guardian_user` (`user_id`),
  KEY `idx_guardian_last_name` (`last_name`),
  CONSTRAINT `fk_guardian_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. teachers
-- ============================================================
CREATE TABLE `teachers` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED    NOT NULL,
  `first_name`      VARCHAR(100)    NOT NULL DEFAULT '',
  `last_name`       VARCHAR(155)    NOT NULL,
  `contact_number`  VARCHAR(50)     DEFAULT NULL,
  `department`      VARCHAR(100)    DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_teacher_user` (`user_id`),
  KEY `idx_teacher_last_name` (`last_name`),
  CONSTRAINT `fk_teacher_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. subjects
-- ============================================================
CREATE TABLE `subjects` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(20)     NOT NULL,
  `name`        VARCHAR(255)    NOT NULL,
  `units`       TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `department`  VARCHAR(100)    DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subject_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. sections
-- ============================================================
CREATE TABLE `sections` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100)    NOT NULL,
  `grade_level` VARCHAR(20)     NOT NULL,
  `adviser_id`  INT UNSIGNED    DEFAULT NULL,
  `capacity`    INT UNSIGNED    NOT NULL DEFAULT 40,
  PRIMARY KEY (`id`),
  KEY `idx_section_adviser` (`adviser_id`),
  CONSTRAINT `fk_section_adviser`
    FOREIGN KEY (`adviser_id`) REFERENCES `teachers` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. students
-- ============================================================
CREATE TABLE `students` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `guardian_id`   INT UNSIGNED    DEFAULT NULL,
  `first_name`    VARCHAR(100)    NOT NULL DEFAULT '',
  `last_name`     VARCHAR(155)    NOT NULL,
  `birthdate`     DATE            DEFAULT NULL,
  `gender`        ENUM('male','female','other') DEFAULT NULL,
  `grade_level`   VARCHAR(20)     DEFAULT NULL,
  `section_id`    INT UNSIGNED    DEFAULT NULL,
  `lrn`           VARCHAR(30)     DEFAULT NULL,
  `profile_photo` VARCHAR(500)    DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student_guardian` (`guardian_id`),
  KEY `idx_student_section` (`section_id`),
  KEY `idx_student_last_name` (`last_name`),
  CONSTRAINT `fk_student_guardian`
    FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_student_section`
    FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. enrollments
-- ============================================================
CREATE TABLE `enrollments` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `student_id`  INT UNSIGNED    NOT NULL,
  `school_year` VARCHAR(20)     NOT NULL,
  `term`        VARCHAR(20)     NOT NULL,
  `status`      ENUM('pending','approved','rejected','enrolled','archived') NOT NULL DEFAULT 'pending',
  `remarks`     TEXT            DEFAULT NULL,
  `enrolled_at` DATETIME        DEFAULT NULL,
  `payment_submitted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_enrollment_student` (`student_id`),
  KEY `idx_enrollment_status`  (`status`),
  KEY `idx_enrollment_year`    (`school_year`),
  CONSTRAINT `fk_enrollment_student`
    FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7A. enrollment_documents
-- ============================================================
CREATE TABLE `enrollment_documents` (
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
-- 8. grades
-- ============================================================
CREATE TABLE `grades` (
  `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `student_id`    INT UNSIGNED      NOT NULL,
  `subject_id`    INT UNSIGNED      NOT NULL,
  `school_year`   VARCHAR(20)       NOT NULL,
  `term`          VARCHAR(20)       NOT NULL,
  `midterm`       DECIMAL(5,2)      DEFAULT NULL,
  `finals`        DECIMAL(5,2)      DEFAULT NULL,
  `final_grade`   DECIMAL(5,2)      DEFAULT NULL,
  `submitted_by`  INT UNSIGNED      DEFAULT NULL,
  `submitted_at`  DATETIME          DEFAULT NULL,
  `updated_at`    DATETIME          DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_grade_student` (`student_id`),
  KEY `idx_grade_subject` (`subject_id`),
  KEY `idx_grade_teacher` (`submitted_by`),
  CONSTRAINT `fk_grade_student`
    FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grade_subject`
    FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grade_teacher`
    FOREIGN KEY (`submitted_by`) REFERENCES `teachers` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. schedules
-- ============================================================
CREATE TABLE `schedules` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `subject_id`    INT UNSIGNED    NOT NULL,
  `section_id`    INT UNSIGNED    NOT NULL,
  `teacher_id`    INT UNSIGNED    NOT NULL,
  `room`          VARCHAR(50)     DEFAULT NULL,
  `day_of_week`   ENUM('Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL,
  `time_start`    TIME            NOT NULL,
  `time_end`      TIME            NOT NULL,
  `school_year`   VARCHAR(20)     NOT NULL,
  `term`          VARCHAR(20)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_schedule_subject` (`subject_id`),
  KEY `idx_schedule_section` (`section_id`),
  KEY `idx_schedule_teacher` (`teacher_id`),
  CONSTRAINT `fk_schedule_subject`
    FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_schedule_section`
    FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_schedule_teacher`
    FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. calendar_events
-- ============================================================
CREATE TABLE `calendar_events` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(255)    NOT NULL,
  `date_start`  DATE            NOT NULL,
  `date_end`    DATE            NOT NULL,
  `type`        ENUM('holiday','exam','event','other') NOT NULL DEFAULT 'event',
  `description` TEXT            DEFAULT NULL,
  `created_by`  INT UNSIGNED    DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cal_dates` (`date_start`, `date_end`),
  CONSTRAINT `fk_cal_creator`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. payments
-- ============================================================
CREATE TABLE `payments` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `enrollment_id` INT UNSIGNED   NOT NULL,
  `amount`        DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  `method`        ENUM('cash','online','bank') NOT NULL DEFAULT 'cash',
  `reference_no`  VARCHAR(100)   DEFAULT NULL,
  `description`   VARCHAR(255)   DEFAULT NULL,
  `status`        ENUM('paid','pending','failed') NOT NULL DEFAULT 'pending',
  `paid_at`       DATETIME       DEFAULT NULL,
  `recorded_by`   INT UNSIGNED   DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payment_enrollment` (`enrollment_id`),
  KEY `idx_payment_status`     (`status`),
  CONSTRAINT `fk_payment_enrollment`
    FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_recorder`
    FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. audit_log
-- ============================================================
CREATE TABLE `audit_log` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED    DEFAULT NULL,
  `action`          VARCHAR(100)    NOT NULL,
  `table_affected`  VARCHAR(100)    DEFAULT NULL,
  `record_id`       INT UNSIGNED    DEFAULT NULL,
  `old_value`       JSON            DEFAULT NULL,
  `new_value`       JSON            DEFAULT NULL,
  `ip_address`      VARCHAR(45)     DEFAULT NULL,
  `timestamp`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_time` (`timestamp`),
  CONSTRAINT `fk_audit_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. student_face_profiles
-- ============================================================
CREATE TABLE `student_face_profiles` (
  `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `student_id`       INT UNSIGNED    NOT NULL,
  `face_descriptor`  LONGTEXT        NOT NULL,
  `face_image_path`  VARCHAR(500)    DEFAULT NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_face_profile_student` (`student_id`),
  CONSTRAINT `fk_face_profile_student`
    FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. attendance_logs
-- ============================================================
CREATE TABLE `attendance_logs` (
  `id`                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `student_id`         INT UNSIGNED    NOT NULL,
  `attendance_date`    DATE            NOT NULL,
  `attendance_status`  ENUM('present','late','absent') NOT NULL DEFAULT 'present',
  `method`             ENUM('face','manual') NOT NULL DEFAULT 'face',
  `confidence`         DECIMAL(6,5)    DEFAULT NULL,
  `marked_by`          INT UNSIGNED    DEFAULT NULL,
  `marked_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_student_day` (`student_id`, `attendance_date`),
  KEY `idx_attendance_date` (`attendance_date`),
  CONSTRAINT `fk_attendance_student`
    FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_marker`
    FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. settings
-- ============================================================
CREATE TABLE `settings` (
  `key`   VARCHAR(100) NOT NULL,
  `value` TEXT         NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`) VALUES
('active_school_year', '2024-2025'),
('attendance_module_enabled', '1');

-- ============================================================
-- Seed: Default admin account
-- Email: admin@academy.edu  Password: Admin@1234
-- ============================================================
INSERT INTO `users` (`email`, `password_hash`, `role`, `is_active`, `created_at`)
VALUES (
  'admin@academy.edu',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'admin',
  1,
  NOW()
);

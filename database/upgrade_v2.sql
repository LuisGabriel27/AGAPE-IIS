-- ============================================================
-- AGAPE-IIS Upgrade v2 — Run once against academy_db
-- Adds: clerk role, dual-role table, expanded guardian fields
-- ============================================================

-- 1. Add 'clerk' to users.role enum
ALTER TABLE `users`
  MODIFY COLUMN `role`
    ENUM('admin','teacher','guardian','clerk')
    NOT NULL DEFAULT 'guardian';

-- 2. Dual-role junction table
--    Allows one user account to hold multiple roles (e.g. guardian + clerk).
CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id` INT UNSIGNED NOT NULL,
  `role`    ENUM('admin','teacher','guardian','clerk') NOT NULL,
  PRIMARY KEY (`user_id`, `role`),
  CONSTRAINT `fk_ur_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Populate user_roles from existing users so legacy accounts work
INSERT IGNORE INTO `user_roles` (`user_id`, `role`)
  SELECT `id`, `role` FROM `users`;

-- 3. Expanded guardian profile fields
ALTER TABLE `guardians`
  ADD COLUMN IF NOT EXISTS `occupation`              VARCHAR(100)   DEFAULT NULL AFTER `relationship_to_student`,
  ADD COLUMN IF NOT EXISTS `civil_status`            ENUM('single','married','widowed','separated','others') DEFAULT NULL AFTER `occupation`,
  ADD COLUMN IF NOT EXISTS `nationality`             VARCHAR(100)   DEFAULT 'Filipino' AFTER `civil_status`,
  ADD COLUMN IF NOT EXISTS `religion`                VARCHAR(100)   DEFAULT NULL AFTER `nationality`,
  ADD COLUMN IF NOT EXISTS `emergency_contact_name`  VARCHAR(255)   DEFAULT NULL AFTER `religion`,
  ADD COLUMN IF NOT EXISTS `emergency_contact_number` VARCHAR(50)   DEFAULT NULL AFTER `emergency_contact_name`;

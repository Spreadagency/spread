-- =========================================================
-- Spread AI — Security Upgrade (fixes 7-17)
-- Run ONCE on the production database via phpMyAdmin
-- Safe to run on existing data (no DROP statements)
-- =========================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- 1) Rate limiting table (used by includes/rate-limit.php)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `action` VARCHAR(50) NOT NULL,
    `rate_key` VARCHAR(120) NOT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_lookup` (`action`, `rate_key`, `created_at`),
    INDEX `idx_cleanup` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 2) Replace the default admin (admin123 was publicly known)
--    New email: admin@spreadagency.net
--    New password: (delivered separately - change after first login)
-- ---------------------------------------------------------
UPDATE `admin_users`
SET `email` = 'admin@spreadagency.net',
    `password` = '$2y$10$01BhLCW0ku/5qaQe5xI6kuGS1chC022l62gSfAc/nTeIQ28eKPvI2'
WHERE `email` = 'admin@spread-ai.local';

-- ---------------------------------------------------------
-- 3) Helpful index for content history performance
-- ---------------------------------------------------------
-- (MySQL has no IF NOT EXISTS for indexes on older versions;
--  ignore the error if it already exists)
ALTER TABLE `contents` ADD INDEX `idx_user_created` (`user_id`, `created_at`);

-- =========================================================
-- Spread AI — Database Schema
-- MySQL 5.7+ / MariaDB 10.3+
-- Charset: utf8mb4
-- =========================================================

-- Run once to create the database (uncomment if needed):
-- CREATE DATABASE IF NOT EXISTS ai_content_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE ai_content_platform;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =========================================================
-- USERS (Clients)
-- =========================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('user') NOT NULL DEFAULT 'user',
    `status` ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
    `email_verified_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_email` (`email`),
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- EMAIL VERIFICATIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS `email_verifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `verified_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_token` (`token`(191)),
    INDEX `idx_user` (`user_id`),
    CONSTRAINT `fk_ev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- PASSWORD RESETS
-- =========================================================
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_token` (`token`(191)),
    INDEX `idx_user` (`user_id`),
    CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ADMIN USERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BRAND PROFILES
-- =========================================================
CREATE TABLE IF NOT EXISTS `brand_profiles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `business_name` VARCHAR(255) NOT NULL DEFAULT '',
    `industry` VARCHAR(150) NULL,
    `description` TEXT NULL,
    `audience` TEXT NULL,
    `tone` VARCHAR(100) NULL,
    `colors` VARCHAR(100) NULL,
    `dialect` VARCHAR(50) NULL,
    `keywords_use` TEXT NULL,
    `keywords_avoid` TEXT NULL,
    `logo_path` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    CONSTRAINT `fk_bp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BRAND IMAGES
-- =========================================================
CREATE TABLE IF NOT EXISTS `brand_images` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `brand_profile_id` INT NOT NULL,
    `image_path` VARCHAR(255) NOT NULL,
    `image_type` ENUM('personal','reference','design') NOT NULL DEFAULT 'reference',
    `title` VARCHAR(150) NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_brand` (`brand_profile_id`),
    CONSTRAINT `fk_bi_brand` FOREIGN KEY (`brand_profile_id`) REFERENCES `brand_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- CONTENTS (Generated posts)
-- =========================================================
CREATE TABLE IF NOT EXISTS `contents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `brand_profile_id` INT NOT NULL,
    `content_type` VARCHAR(50) NOT NULL DEFAULT 'introductory',
    `platform` ENUM('facebook','instagram','both') NOT NULL DEFAULT 'both',
    `length` VARCHAR(20) NOT NULL DEFAULT 'medium',
    `tone` VARCHAR(50) NULL,
    `extra_notes` TEXT NULL,
    `final_prompt` LONGTEXT NULL,
    `generated_text` LONGTEXT NULL,
    `hashtags` TEXT NULL,
    `cta` TEXT NULL,
    `use_logo` TINYINT(1) NOT NULL DEFAULT 1,
    `use_personal_image` TINYINT(1) NOT NULL DEFAULT 0,
    `use_reference_image` TINYINT(1) NOT NULL DEFAULT 0,
    `selected_image_id` INT NULL,
    `status` ENUM('generated','edited','saved') NOT NULL DEFAULT 'generated',
    `credits_used` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_date` (`user_id`, `created_at`),
    INDEX `idx_brand` (`brand_profile_id`),
    CONSTRAINT `fk_c_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_c_brand` FOREIGN KEY (`brand_profile_id`) REFERENCES `brand_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- CONTENT VERSIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS `content_versions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `content_id` INT NOT NULL,
    `version_number` INT NOT NULL DEFAULT 1,
    `content_text` LONGTEXT NULL,
    `hashtags` TEXT NULL,
    `cta` TEXT NULL,
    `notes` TEXT NULL,
    `version_type` ENUM('ai','edited') NOT NULL DEFAULT 'edited',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_content_ver` (`content_id`, `version_number`),
    CONSTRAINT `fk_cv_content` FOREIGN KEY (`content_id`) REFERENCES `contents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- CONTENT NOTES
-- =========================================================
CREATE TABLE IF NOT EXISTS `content_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `content_id` INT NOT NULL,
    `user_id` INT NULL,
    `admin_id` INT NULL,
    `note` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_content` (`content_id`),
    CONSTRAINT `fk_cn_content` FOREIGN KEY (`content_id`) REFERENCES `contents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- CREDIT WALLETS
-- =========================================================
CREATE TABLE IF NOT EXISTS `credit_wallets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `balance` INT NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_cw_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- CREDIT TRANSACTIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS `credit_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `action_type` ENUM('add','deduct','consume') NOT NULL,
    `amount` INT NOT NULL,
    `reference_type` VARCHAR(50) NULL,
    `reference_id` INT NULL,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_date` (`user_id`, `created_at`),
    CONSTRAINT `fk_ct_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- PROMPTS (admin-managed)
-- =========================================================
CREATE TABLE IF NOT EXISTS `prompts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `prompt_type` VARCHAR(100) NOT NULL DEFAULT 'content',
    `title` VARCHAR(200) NULL,
    `prompt_text` LONGTEXT NOT NULL,
    `version` INT NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_type_active` (`prompt_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(150) NOT NULL UNIQUE,
    `setting_value` TEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ADMIN LOGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `admin_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_user_id` INT NULL,
    `action` VARCHAR(255) NOT NULL,
    `entity_type` VARCHAR(100) NULL,
    `entity_id` INT NULL,
    `meta` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_admin_date` (`admin_user_id`, `created_at`),
    CONSTRAINT `fk_al_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- RATE LIMITS (security)
-- =========================================================
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `action` VARCHAR(50) NOT NULL,
    `rate_key` VARCHAR(120) NOT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_lookup` (`action`, `rate_key`, `created_at`),
    INDEX `idx_cleanup` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- DEFAULT DATA
-- =========================================================

-- Default settings
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
    ('content_generation_cost', '1'),
    ('content_regeneration_cost', '1'),
    ('content_design_cost', '2'),
    ('site_name', 'Spread AI'),
    ('starter_credits', '10'),
    ('max_brand_images', '10'),
    ('ai_model', 'openai/gpt-4o-mini');

-- Default admin user:
-- SECURITY: no admin is seeded with a known password anymore.
-- Create your admin manually. Generate a hash with:
--   php -r "echo password_hash('YourStrongPassword', PASSWORD_BCRYPT);"
-- Then run:
-- INSERT INTO `admin_users` (`name`, `email`, `password`, `status`)
-- VALUES ('Admin', 'admin@spreadagency.net', '<bcrypt-hash-here>', 'active');

-- Default content prompt
INSERT IGNORE INTO `prompts` (`prompt_type`, `prompt_text`, `version`, `is_active`) VALUES
    ('content',
     'أنت كاتب محتوى تسويقي محترف ومتخصص في المحتوى العربي للسوشيال ميديا. مهمتك إنشاء منشور احترافي بناءً على بيانات البراند والمتطلبات اللي هيتم تزويدك بيها.\n\nقواعد عامة:\n- المحتوى لازم يكون باللغة العربية الفصحى المبسطة أو حسب اللهجة المطلوبة\n- اكتب بأسلوب جذاب يتناسب مع الجمهور المستهدف\n- خلي البداية لافتة للنظر (Hook)\n- استخدم الإيموجي بشكل مناسب وغير مبالغ فيه\n- اختم بـ Call-to-Action واضح\n- أنشئ هاشتاجات مناسبة (5-10 هاشتاجات)\n\nأعد الناتج بالتنسيق التالي بدون أي تعليق إضافي:\n\n[CONTENT]\nنص المنشور هنا...\n[/CONTENT]\n\n[HASHTAGS]\n#هاشتاج1 #هاشتاج2 #هاشتاج3\n[/HASHTAGS]\n\n[CTA]\nنص الـ Call-to-Action هنا\n[/CTA]',
     1, 1);

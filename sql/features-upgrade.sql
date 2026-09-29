-- =========================================================
-- Spread AI — Features Upgrade (21-29)
-- Safe to run multiple times (guarded column adds)
-- Run via phpMyAdmin AFTER security-upgrade.sql
-- =========================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- Helper pattern: add column only if missing
-- ---------------------------------------------------------

-- contents.dialect
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'dialect');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents` ADD COLUMN `dialect` VARCHAR(20) NOT NULL DEFAULT ''egyptian'' AFTER `tone`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- contents.template_id
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'template_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents` ADD COLUMN `template_id` INT NULL AFTER `dialect`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- contents.model
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'model');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents` ADD COLUMN `model` VARCHAR(100) NULL AFTER `final_prompt`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- contents.tokens_in / tokens_out (actual API usage)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'tokens_in');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents` ADD COLUMN `tokens_in` INT NOT NULL DEFAULT 0, ADD COLUMN `tokens_out` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- contents scheduling / publishing columns
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'scheduled_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents`
    ADD COLUMN `scheduled_at` DATETIME NULL,
    ADD COLUMN `publish_platform` VARCHAR(20) NULL,
    ADD COLUMN `published_at` DATETIME NULL,
    ADD COLUMN `publish_post_id` VARCHAR(120) NULL,
    ADD COLUMN `publish_error` TEXT NULL,
    ADD INDEX `idx_scheduled` (`scheduled_at`, `published_at`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------
-- Content designs (AI-generated images) — feature 21
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `content_designs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `content_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `image_path` VARCHAR(255) NOT NULL,
    `prompt` TEXT NULL,
    `model` VARCHAR(100) NULL,
    `credits_used` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_content` (`content_id`),
    INDEX `idx_user` (`user_id`),
    CONSTRAINT `fk_cd_content` FOREIGN KEY (`content_id`) REFERENCES `contents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- AI usage log (actual cost tracking) — feature 25
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_usage_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(30) NOT NULL,           -- generate | regenerate | design
    `model` VARCHAR(100) NULL,
    `tokens_in` INT NOT NULL DEFAULT 0,
    `tokens_out` INT NOT NULL DEFAULT 0,
    `reference_id` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_date` (`user_id`, `created_at`),
    INDEX `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Content templates (admin-managed library) — feature 27
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `content_templates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `description` VARCHAR(255) NULL,
    `prompt_snippet` TEXT NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `order_num` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_active` (`is_active`, `order_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `content_templates` (`id`, `name`, `description`, `prompt_snippet`, `order_num`) VALUES
(1, 'قصة نجاح عميل', 'منشور يحكي قصة عميل حقق نتيجة مع البراند', 'اكتب المنشور بأسلوب قصصي (Storytelling): ابدأ بمشكلة كان يعاني منها عميل، ثم كيف ساعده البراند، ثم النتيجة المبهرة. خلي القصة واقعية ومقنعة بدون مبالغة.', 1),
(2, 'قبل / بعد', 'مقارنة توضح الفرق قبل وبعد الخدمة', 'اكتب المنشور بصيغة "قبل وبعد": وضّح الحالة قبل التعامل مع البراند (المعاناة/المشكلة) ثم الحالة بعده (التحسن/النتيجة). استخدم تباين واضح بين الحالتين.', 2),
(3, 'أسئلة شائعة', 'إجابة على سؤال يتكرر من الجمهور', 'اكتب المنشور بصيغة سؤال وجواب: ابدأ بسؤال شائع يسأله الجمهور في هذا المجال، ثم أجب عليه إجابة وافية تظهر خبرة البراند.', 3),
(4, 'نصائح سريعة', 'قائمة نصائح عملية قصيرة', 'اكتب المنشور كقائمة نصائح مرقّمة (3-5 نصائح) عملية وقابلة للتطبيق فورًا في مجال البراند. اجعل كل نصيحة سطر أو سطرين.', 4),
(5, 'كواليس العمل', 'محتوى يظهر ما وراء الكواليس', 'اكتب المنشور بأسلوب "وراء الكواليس": اعرض جانبًا إنسانيًا من يوم العمل داخل البراند، بطريقة تبني الثقة والقرب من الجمهور.', 5);

-- ---------------------------------------------------------
-- Credit packages + payment orders (Paymob) — feature 26
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `credit_packages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `credits` INT NOT NULL,
    `price_egp` DECIMAL(10,2) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `order_num` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `credit_packages` (`id`, `name`, `credits`, `price_egp`, `order_num`) VALUES
(1, 'باقة البداية', 50, 100.00, 1),
(2, 'باقة النمو', 150, 250.00, 2),
(3, 'باقة الاحتراف', 400, 550.00, 3);

CREATE TABLE IF NOT EXISTS `payment_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `package_id` INT NOT NULL,
    `credits` INT NOT NULL,
    `amount_egp` DECIMAL(10,2) NOT NULL,
    `paymob_order_id` VARCHAR(60) NULL,
    `paymob_txn_id` VARCHAR(60) NULL,
    `status` ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `paid_at` DATETIME NULL,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_paymob` (`paymob_order_id`),
    CONSTRAINT `fk_po_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Integration settings seeds (Meta / Paymob / CRM / models)
-- ---------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
    ('content_design_cost', '2'),
    ('ai_model_fallback', 'anthropic/claude-3-5-haiku'),
    ('ai_image_model', 'google/gemini-2.5-flash-image'),
    ('meta_page_id', ''),
    ('meta_page_token', ''),
    ('meta_ig_user_id', ''),
    ('paymob_api_key', ''),
    ('paymob_integration_id', ''),
    ('paymob_iframe_id', ''),
    ('paymob_hmac', ''),
    ('crm_webhook_url', ''),
    ('crm_webhook_secret', ''),
    ('cron_secret', '');

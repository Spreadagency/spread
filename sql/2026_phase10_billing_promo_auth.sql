-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة 10: الباقات والدفع اليدوي · أكواد الخصم · الدخول
--   ① payment_methods          طرق الدفع (إنستاباي · فودافون كاش · …) من الأدمن
--   ② payment_requests         طلب اشتراك/دفع: الباقة ← الإيصال ← مراجعة الأدمن ← المحفظة
--   ③ payment_request_events   سجل كامل لكل تغيير في الطلب (مين · إمتى · من حالة لحالة)
--   ④ user_notifications       إشعارات العميل (تفعيل الباقة · رفض · طلب معلومات)
--   ⑤ promo_usages             استخدامات أكواد الخصم في الشراء (منفصلة عن مكافآت الإحالة)
--   ⑥ user_remember_tokens     «افتكرني» — توكن آمن (selector + hash) مش باسورد
--   ⑦ credit_packages          هدية/Bonus لكل باقة
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `payment_methods` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `mkey` VARCHAR(40) NOT NULL,
    `label` VARCHAR(80) NOT NULL,
    `account` VARCHAR(190) NULL,
    `account_name` VARCHAR(120) NULL,
    `pay_link` VARCHAR(500) NULL,
    `instructions` TEXT NULL,
    `needs_proof` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_pm_key` (`mkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `package_id` INT NULL,
    `plan_name` VARCHAR(120) NOT NULL DEFAULT '',
    `credits` INT NOT NULL DEFAULT 0,
    `bonus_credits` INT NOT NULL DEFAULT 0,
    `validity_days` INT NOT NULL DEFAULT 30,
    `extra_days` INT NOT NULL DEFAULT 0,
    `original_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `final_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `currency` VARCHAR(8) NOT NULL DEFAULT 'EGP',
    `method` VARCHAR(40) NOT NULL DEFAULT '',
    `method_label` VARCHAR(80) NOT NULL DEFAULT '',
    `transaction_ref` VARCHAR(120) NULL,
    `proof_path` VARCHAR(255) NULL,
    `transferred_at` DATETIME NULL,
    `promo_code` VARCHAR(40) NULL,
    `offer_id` INT NULL,
    `promo_json` TEXT NULL,
    `contact_name` VARCHAR(150) NULL,
    `contact_email` VARCHAR(150) NULL,
    `contact_phone` VARCHAR(30) NULL,
    `user_note` TEXT NULL,
    `status` ENUM('pending','under_review','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    `info_request` TEXT NULL,
    `admin_note` TEXT NULL,
    `reject_reason` VARCHAR(500) NULL,
    `reviewed_by` INT NULL,
    `reviewed_at` DATETIME NULL,
    `user_plan_id` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_pr_status` (`status`, `created_at`),
    KEY `idx_pr_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_request_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT NOT NULL,
    `actor_type` ENUM('user','admin','system') NOT NULL DEFAULT 'system',
    `actor_id` INT NULL,
    `action` VARCHAR(40) NOT NULL,
    `from_status` VARCHAR(20) NULL,
    `to_status` VARCHAR(20) NULL,
    `note` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_pre_req` (`request_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `kind` VARCHAR(40) NOT NULL DEFAULT 'info',
    `title` VARCHAR(190) NOT NULL,
    `body` TEXT NULL,
    `url` VARCHAR(255) NULL,
    `tone` VARCHAR(20) NOT NULL DEFAULT 'brand',
    `read_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_un_user` (`user_id`, `read_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `promo_usages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `offer_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `payment_request_id` INT NOT NULL,
    `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `bonus_credits` INT NOT NULL DEFAULT 0,
    `extra_days` INT NOT NULL DEFAULT 0,
    `status` ENUM('granted','reversed') NOT NULL DEFAULT 'granted',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_pu_request` (`payment_request_id`),
    KEY `idx_pu_offer_user` (`offer_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_remember_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `selector` CHAR(24) NOT NULL,
    `validator_hash` CHAR(64) NOT NULL,
    `user_agent` VARCHAR(255) NULL,
    `ip` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME NULL,
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME NULL,
    `sid_hash` CHAR(64) NULL,
    UNIQUE KEY `uq_rt_selector` (`selector`),
    KEY `idx_rt_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ربط التوكن بالجلسة (إنهاء جلسة من الإعدادات بيلغي «افتكرني» بتاعها كمان)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_remember_tokens' AND column_name = 'sid_hash');
SET @sql := IF(@c = 0, 'ALTER TABLE `user_remember_tokens` ADD COLUMN `sid_hash` CHAR(64) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- هدية الباقة (Bonus)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'credit_packages' AND column_name = 'bonus_credits');
SET @sql := IF(@c = 0, 'ALTER TABLE `credit_packages` ADD COLUMN `bonus_credits` INT NOT NULL DEFAULT 0, ADD COLUMN `bonus_note` VARCHAR(190) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- طرق الدفع الأساسية (بتاخد الأرقام من الإعدادات القديمة لو موجودة)
INSERT IGNORE INTO `payment_methods` (`mkey`, `label`, `account`, `pay_link`, `instructions`, `sort_order`)
SELECT 'instapay', 'InstaPay · إنستاباي',
       (SELECT setting_value FROM settings WHERE setting_key = 'pay_instapay' ORDER BY id DESC LIMIT 1),
       (SELECT setting_value FROM settings WHERE setting_key = 'pay_instapay_link' ORDER BY id DESC LIMIT 1),
       'حوّل المبلغ على العنوان ده من تطبيق إنستاباي، وخد صورة شاشة للتحويل.', 1 FROM DUAL;
INSERT IGNORE INTO `payment_methods` (`mkey`, `label`, `account`, `instructions`, `sort_order`)
SELECT 'vodafone_cash', 'Vodafone Cash · فودافون كاش',
       (SELECT setting_value FROM settings WHERE setting_key = 'pay_vodafone' ORDER BY id DESC LIMIT 1),
       'حوّل المبلغ على الرقم ده من محفظة فودافون كاش، وخد صورة من رسالة التأكيد.', 2 FROM DUAL;

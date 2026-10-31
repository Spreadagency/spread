-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — المساعدين (المرحلة 7)
--   «الدعم»: بيعرف حساب العميل (الباقة · الرصيد · الدفع · النشر · الفيديو · الشغل اللي وقف) وبيحل المشكلة أو يفتح تذكرة للفريق
--   «الوكيل»: بيفهم المطلوب من المحادثة ← بريف حملة + تقدير ← العميل يوافق ← بينفّذ الحملة كاملة لوحده
--            (ملف الهوية ← الخطة ← الكتابة ← التصميم ← الجدولة) بنفس خطوات المحرك وحجز الكريدت بتاعها
--   assistant_threads: محادثة شغالة لكل عميل ولكل مساعد
--   agent_runs: كل مهمة للوكيل ومرحلتها وسجلها
--   credit_packages / user_plans: مين الباقات اللي فيها كل مساعد (لقطة وقت الاشتراك)
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_assistants_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `assistant_threads` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `kind` VARCHAR(10) NOT NULL COMMENT 'support · agent',
  `status` VARCHAR(10) NOT NULL DEFAULT 'active' COMMENT 'active · closed',
  `messages_json` MEDIUMTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_at_user` (`user_id`, `kind`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `agent_runs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `thread_id` INT NULL,
  `campaign_id` INT NULL,
  `title` VARCHAR(190) NOT NULL DEFAULT '',
  `stage` VARCHAR(12) NOT NULL DEFAULT 'confirm' COMMENT 'confirm · dna · plan · write · design · publish · done',
  `status` VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending (مستني موافقة) · running · paused · done · failed · cancelled',
  `brief_json` TEXT NULL,
  `opts_json` TEXT NULL COMMENT 'النشر والصفحة والمنصات + رقم الشغلانة الحالية والمحاولات',
  `estimate_json` TEXT NULL,
  `fee_credits` INT NOT NULL DEFAULT 0,
  `log_json` TEXT NULL,
  `note` VARCHAR(500) NULL COMMENT 'سبب الإيقاف/الفشل للعميل',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ar_user` (`user_id`, `status`),
  KEY `idx_ar_status` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_packages' AND COLUMN_NAME = 'assistant_agent');
SET @q := IF(@c = 0, 'ALTER TABLE `credit_packages` ADD COLUMN `assistant_support` TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN `assistant_agent` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_plans' AND COLUMN_NAME = 'assistant_agent');
SET @q := IF(@c = 0, 'ALTER TABLE `user_plans` ADD COLUMN `assistant_support` TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN `assistant_agent` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- مهام الـ AI (الموديل والسلسلة من «الموديلات والـ Router» · وموديل لكل مستوى من «مستويات الجودة»)
INSERT IGNORE INTO `ai_task_routes` (`task`, `capability`, `label`, `max_attempts`, `time_budget_s`, `json_required`, `temperature`) VALUES
('assistant_support', 'text', 'المساعد: الدعم', 2, 60, 0, 0.30),
('assistant_agent',   'text', 'المساعد: الوكيل', 2, 90, 1, 0.30);

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('assistant_support_enabled', '1'),   -- زرار «🛟 الدعم» في كل صفحات العميل
('assistant_agent_enabled', '1'),     -- «🤖 الوكيل» (للباقات اللي فيها الوكيل بس)
('assistant_support_cost', '0'),      -- كريدت لكل رسالة دعم (0 = مجاني)
('assistant_agent_msg_cost', '0'),    -- كريدت لكل رسالة للوكيل
('assistant_agent_fee', '2'),         -- رسوم تنفيذ المهمة (فوق سعر الخطة والكتابة والتصميم العادي)
('assistant_agent_daily', '3');       -- أقصى عدد مهام للعميل في اليوم

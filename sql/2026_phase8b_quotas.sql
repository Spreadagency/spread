-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة 8-ب: الحصص الشهرية + الاستهلاك بالنسبة % + ملف العميل 360
--   credit_packages.quotas_json → حصص الباقة في الدورة: منشورات · تصميمات · نشر · أبحاث · سكريبتات فيديو
--   user_plans      → دورة اشتراك العميل (بتبدأ مع شراء/تفعيل باقة وتخلص مع صلاحية الكريدت)
--   quota_overrides → زيادة حصة لعميل معيّن (بسبب وتاريخ انتهاء)
--   usage_events    → أحداث الاستهلاك اللي مالهاش جدول خاص (طلبات النشر)
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'credit_packages' AND column_name = 'quotas_json');
SET @sql := IF(@c = 0, 'ALTER TABLE `credit_packages` ADD COLUMN `quotas_json` TEXT NULL', 'SELECT 1');
PREPARE q1 FROM @sql; EXECUTE q1; DEALLOCATE PREPARE q1;

CREATE TABLE IF NOT EXISTS `user_plans` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`           INT NOT NULL,
  `package_id`        INT NULL,
  `name`              VARCHAR(100) NOT NULL,
  `source`            VARCHAR(16) NOT NULL DEFAULT 'paid',   -- paid | manual | gift | trial
  `starts_at`         DATETIME NOT NULL,
  `ends_at`           DATETIME NOT NULL,
  `credits_allowance` INT NOT NULL DEFAULT 0,
  `quotas_json`       TEXT NULL,                              -- نسخة من حصص الباقة وقت التفعيل (تغيير الباقة بعدين مايأثرش على الدورة الحالية)
  `status`            VARCHAR(10) NOT NULL DEFAULT 'active',  -- active | ended | replaced
  `note`              VARCHAR(255) NULL,
  `created_by`        INT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_up_user` (`user_id`, `status`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quota_overrides` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT NOT NULL,
  `unit`        VARCHAR(20) NOT NULL,
  `extra`       INT NOT NULL,
  `reason`      VARCHAR(255) NOT NULL,
  `expires_at`  DATETIME NULL,
  `created_by`  INT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_qo_user` (`user_id`, `unit`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usage_events` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT NOT NULL,
  `unit`        VARCHAR(20) NOT NULL,
  `ref_id`      INT NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ue` (`user_id`, `unit`, `ref_id`),
  KEY `idx_ue_date` (`user_id`, `unit`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- حصص مبدئية للباقات الموجودة (لو فاضية) — تتعدّل من «الباقات والأسعار»
UPDATE `credit_packages` SET `quotas_json` = CONCAT('{"posts":', GREATEST(5, ROUND(`credits` * 0.3)), ',"designs":', GREATEST(3, ROUND(`credits` * 0.25)),
  ',"publishes":', GREATEST(5, ROUND(`credits` * 0.3)), ',"research":', GREATEST(1, ROUND(`credits` / 100)), ',"videos":', GREATEST(1, ROUND(`credits` / 50)), '}')
  WHERE `quotas_json` IS NULL;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('credits_display',      'percent'),  -- percent: العميل بيشوف نسب % بس · visible: أرقام الكريدت زي الأول
('quotas_enforced',      '1'),        -- 1 = الحصة لما تخلص الخدمة تقف (مع رسالة ترقية) · 0 = للعرض بس
('health_inactive_days', '7'),        -- «مهدد بالإلغاء»: مفيش نشاط من كام يوم
('health_usage_high',    '85'),       -- «قرّب يخلص باقته»: استهلك كام %
('health_renew_days',    '3'),        -- «التجديد قريب»: فاضل كام يوم
('upgrade_whatsapp_msg', 'السلام عليكم، عايز أرقّي باقتي في Spread AI');

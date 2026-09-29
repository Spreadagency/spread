-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة 8-ج: مركز الإعدادات + سجل التغييرات
--   settings_audit → كل تغيير في إعداد من لوحة الأدمن: مين · إمتى · من أنهي صفحة · القيمة قبل وبعد (الأسرار مخفية)
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `settings_audit` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(150) NOT NULL,
  `old_value`   TEXT NULL,
  `new_value`   TEXT NULL,
  `is_secret`   TINYINT(1) NOT NULL DEFAULT 0,
  `admin_id`    INT NULL,
  `source`      VARCHAR(80) NULL,
  `ip`          VARCHAR(45) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sa_key` (`setting_key`, `created_at`),
  KEY `idx_sa_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

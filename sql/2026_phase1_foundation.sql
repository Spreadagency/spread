-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة 1: الأساس التقني
--   • تتبّع الترحيلات (schema_migrations)
--   • الحملات (campaigns)
--   • الحالات التسعة الموحّدة للمحتوى
--   • توسيع سجل النسخ الموجود
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════
SET NAMES utf8mb4;

-- ───────────── تتبّع الترحيلات ─────────────
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `filename`   VARCHAR(120) NOT NULL,
  `checksum`   CHAR(40) NULL,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `applied_by` VARCHAR(120) NULL,
  `duration_ms` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────── الحملات ─────────────
CREATE TABLE IF NOT EXISTS `campaigns` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT NOT NULL,
  `title`         VARCHAR(150) NOT NULL DEFAULT 'حملة جديدة',
  `goal`          VARCHAR(40)  NULL,          -- bookings | awareness | launch | offer | engagement | trust
  `basis`         VARCHAR(40)  NULL,          -- الأساس اللي الأفكار بتتبني عليه
  `topic`         VARCHAR(300) NULL,
  `ideas_count`   TINYINT UNSIGNED NOT NULL DEFAULT 6,
  `notes`         TEXT NULL,
  `period_month`  CHAR(7) NULL,               -- 2026-09
  `stage`         TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- 1 أفكار … 6 نشر
  `max_stage`     TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- أبعد مرحلة وصلها
  `status`        ENUM('active','completed','archived') NOT NULL DEFAULT 'active',
  `revision`      INT UNSIGNED NOT NULL DEFAULT 1,        -- للحفظ التلقائي ومنع التعارض
  `plan_id`       INT NULL,                   -- ربط اختياري بخطة المحتوى القديمة
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────── ربط المحتوى بالحملة + الحالة الموحّدة ─────────────
-- الحالة الفعلية بتتحسب من البيانات (includes/lifecycle.php)،
-- والعمود ده بيخزّن بس القرارات اليدوية: needs_review / needs_design / ready_to_publish
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'campaign_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents`
  ADD COLUMN `campaign_id` INT UNSIGNED NULL,
  ADD COLUMN `workflow_flag` ENUM("none","needs_review","needs_design","ready_to_publish") NOT NULL DEFAULT "none",
  ADD COLUMN `workflow_note` VARCHAR(255) NULL,
  ADD KEY `idx_campaign` (`campaign_id`)', 'SELECT 1');
PREPARE p1 FROM @sql; EXECUTE p1; DEALLOCATE PREPARE p1;

-- ───────────── توسيع سجل النسخ الموجود ─────────────
-- كان: ai | edited  →  زيادة: ai_edit (تعديل بالكلام) · restore (استرجاع نسخة)
SET @t := (SELECT COLUMN_TYPE FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'content_versions' AND column_name = 'version_type');
SET @sql := IF(@t IS NOT NULL AND @t NOT LIKE '%ai_edit%',
  'ALTER TABLE `content_versions` MODIFY `version_type` ENUM("ai","edited","ai_edit","restore") NOT NULL DEFAULT "edited"',
  'SELECT 1');
PREPARE p2 FROM @sql; EXECUTE p2; DEALLOCATE PREPARE p2;

-- ───────────── فهرس لتسريع حساب الحالة ─────────────
SET @i := (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema = DATABASE() AND table_name = 'content_designs' AND index_name = 'idx_content');
SET @sql := IF(@i = 0, 'ALTER TABLE `content_designs` ADD KEY `idx_content` (`content_id`)', 'SELECT 1');
PREPARE p3 FROM @sql; EXECUTE p3; DEALLOCATE PREPARE p3;

-- ───────────── الإعدادات ─────────────
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('campaigns_enabled',   '1'),
('api_rate_per_minute', '90');

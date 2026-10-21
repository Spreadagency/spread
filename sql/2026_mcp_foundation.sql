-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — نظام الجودة والمراقبة (MCP) · المرحلة 2: الأساس
--   prompts: حالة الإصدار + A/B (نسبة الزيارات) · contents/content_designs: الإصدار والمزود
--   ai_events (الأحداث الناقصة بس) · design_ratings · user_issues («بلّغ عن مشكلة»)
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_mcp_foundation_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'status');
SET @sql := IF(@c = 0, 'ALTER TABLE `prompts` ADD COLUMN `status` ENUM(''draft'',''approved'',''testing'',''live'',''archived'',''rejected'') NULL COMMENT ''NULL = النظام القديم (is_active)''', 'SELECT 1');
PREPARE m0 FROM @sql; EXECUTE m0; DEALLOCATE PREPARE m0;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'traffic_percent');
SET @sql := IF(@c = 0, 'ALTER TABLE `prompts` ADD COLUMN `traffic_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE m1 FROM @sql; EXECUTE m1; DEALLOCATE PREPARE m1;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'created_by_kind');
SET @sql := IF(@c = 0, 'ALTER TABLE `prompts` ADD COLUMN `created_by_kind` ENUM(''admin'',''ai'') NOT NULL DEFAULT ''admin''', 'SELECT 1');
PREPARE m2 FROM @sql; EXECUTE m2; DEALLOCATE PREPARE m2;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'reason');
SET @sql := IF(@c = 0, 'ALTER TABLE `prompts` ADD COLUMN `reason` TEXT NULL', 'SELECT 1');
PREPARE m3 FROM @sql; EXECUTE m3; DEALLOCATE PREPARE m3;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'parent_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `prompts` ADD COLUMN `parent_id` INT NULL', 'SELECT 1');
PREPARE m4 FROM @sql; EXECUTE m4; DEALLOCATE PREPARE m4;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'approved_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `prompts` ADD COLUMN `approved_at` DATETIME NULL', 'SELECT 1');
PREPARE m5 FROM @sql; EXECUTE m5; DEALLOCATE PREPARE m5;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'approved_by');
SET @sql := IF(@c = 0, 'ALTER TABLE `prompts` ADD COLUMN `approved_by` INT NULL', 'SELECT 1');
PREPARE m6 FROM @sql; EXECUTE m6; DEALLOCATE PREPARE m6;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'prompt_version_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents` ADD COLUMN `prompt_version_id` INT NULL', 'SELECT 1');
PREPARE m7 FROM @sql; EXECUTE m7; DEALLOCATE PREPARE m7;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'provider');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents` ADD COLUMN `provider` VARCHAR(60) NULL', 'SELECT 1');
PREPARE m8 FROM @sql; EXECUTE m8; DEALLOCATE PREPARE m8;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'content_designs' AND column_name = 'prompt_version_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `content_designs` ADD COLUMN `prompt_version_id` INT NULL', 'SELECT 1');
PREPARE m9 FROM @sql; EXECUTE m9; DEALLOCATE PREPARE m9;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'content_designs' AND column_name = 'provider');
SET @sql := IF(@c = 0, 'ALTER TABLE `content_designs` ADD COLUMN `provider` VARCHAR(60) NULL', 'SELECT 1');
PREPARE m10 FROM @sql; EXECUTE m10; DEALLOCATE PREPARE m10;


CREATE TABLE IF NOT EXISTS `ai_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `brand_id` INT NULL,
  `event_type` VARCHAR(50) NOT NULL COMMENT 'idea_selected · post_scheduled · post_rated · design_rated · design_downloaded · issue_reported',
  `entity_type` VARCHAR(30) NULL,
  `entity_id` BIGINT NULL,
  `prompt_version_id` INT NULL,
  `provider` VARCHAR(60) NULL,
  `latency_ms` INT NULL,
  `meta` TEXT NULL COMMENT 'JSON',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type_time` (`event_type`, `created_at`),
  KEY `idx_user_time` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `design_ratings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `design_id` INT NOT NULL,
  `content_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `rating` TINYINT NOT NULL COMMENT '1 = 👍 · -1 = 👎',
  `reason` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_design_user` (`design_id`, `user_id`),
  KEY `idx_content` (`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_issues` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `content_id` INT NULL,
  `design_id` INT NULL,
  `page_url` VARCHAR(500) NULL,
  `category_user` VARCHAR(30) NOT NULL COMMENT 'tech · content · design · ux · suggestion',
  `description` TEXT NOT NULL,
  `screenshot_path` VARCHAR(500) NULL,
  `context` MEDIUMTEXT NULL COMMENT 'JSON: المتصفح · آخر 10 أحداث · الإصدار والمزود',
  `status` ENUM('new','analyzing','analyzed','in_progress','resolved','wont_fix') NOT NULL DEFAULT 'new',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  `resolved_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_time` (`status`, `created_at`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('issues_enabled', '1'),          -- زرار «بلّغ عن مشكلة»
('issue_rate_per_hour', '5');     -- أقصى عدد بلاغات للعميل في الساعة

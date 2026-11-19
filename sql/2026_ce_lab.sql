-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — محرك المحتوى v3 · المرحلة H: مدير البرومبتات + الـ Playground + اختبار الرجوع
--   ce_prompt_versions  نسخ برومبت النظام لكل مرحلة (v0 = اللي في الكود ومش متخزن هنا)
--   ce_golden_cases     حالات الاختبار الثابتة (Golden Set): الرسالة + المتغيرات + أقل درجة/المتوقع
--   ce_regression_runs  كل اختبار رجوع (لنسخة برومبت جديدة أو موديل جديد) بالنتيجة لكل حالة
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_ce_lab_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ce_prompt_versions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stage` VARCHAR(40) NOT NULL,
  `version` SMALLINT UNSIGNED NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `notes` VARCHAR(500) NULL,
  `created_by` INT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `activated_at` DATETIME NULL COMMENT 'أول مرة اتفعّلت — بعدها الرجوع ليها من غير اختبار',
  `activated_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_pv` (`stage`, `version`),
  KEY `idx_ce_pv_active` (`stage`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ce_golden_cases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stage` VARCHAR(40) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `user_text` MEDIUMTEXT NOT NULL COMMENT 'رسالة المستخدم بالظبط زي ما اتبعتت',
  `vars_json` TEXT NULL COMMENT 'قيم متغيرات البرومبت وقت الحفظ',
  `images_json` MEDIUMTEXT NULL,
  `meta_json` TEXT NULL COMMENT 'الشكل والخانة … للتقييم',
  `min_score` DECIMAL(5,1) NOT NULL DEFAULT 0,
  `expected_json` TEXT NULL COMMENT 'للنقاد: الدرجات/الحكم المتوقع',
  `max_tokens` SMALLINT UNSIGNED NOT NULL DEFAULT 2000,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ce_gc_stage` (`stage`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ce_regression_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stage` VARCHAR(40) NOT NULL,
  `kind` ENUM('prompt','model') NOT NULL DEFAULT 'prompt',
  `candidate_version_id` INT UNSIGNED NULL,
  `candidate_model` VARCHAR(160) NULL COMMENT 'provider|model',
  `baseline_version_id` INT UNSIGNED NULL COMMENT 'NULL = v0',
  `status` ENUM('running','passed','warn','failed','error') NOT NULL DEFAULT 'running',
  `total` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `done` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `results_json` MEDIUMTEXT NULL,
  `avg_base` DECIMAL(5,1) NULL,
  `avg_new` DECIMAL(5,1) NULL,
  `delta` DECIMAL(5,1) NULL,
  `cost_usd` DECIMAL(10,5) NOT NULL DEFAULT 0,
  `note` VARCHAR(300) NULL,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ce_rr_stage` (`stage`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('ce_regression_max_drop', '3');      -- لو متوسط الدرجة نزل أكتر من كده ← تحذير

-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — محرك المحتوى v3 · المرحلة D
--   ce_jobs           طابور الشغل الطويل (worker على cron كل دقيقة + خطوات من الواجهة)
--   ce_credit_holds   حجز الكريدت قبل التشغيل وتسويته بعده (مفتاح منع تكرار)
--   ce_brand_dna      ملف الهوية المختصر (≤ ~1200 توكن) — هو بس اللي بيدخل البرومبتات
--   ce_campaigns      حملات المحرك الجديد (البريف من الـ Wizard + التقدير)
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_ce_engine_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ce_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `kind` VARCHAR(40) NOT NULL COMMENT 'brand_dna · campaign_plan · …',
  `ref_type` VARCHAR(30) NULL,
  `ref_id` BIGINT NULL,
  `status` ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
  `stage` VARCHAR(40) NULL,
  `stage_label` VARCHAR(160) NULL,
  `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `state_json` MEDIUMTEXT NULL,
  `result_json` MEDIUMTEXT NULL,
  `error` VARCHAR(500) NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `lock_token` CHAR(32) NULL,
  `locked_at` DATETIME NULL,
  `run_after` DATETIME NULL,
  `hold_id` BIGINT UNSIGNED NULL,
  `idem_key` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_jobs_idem` (`idem_key`),
  KEY `idx_ce_jobs_pick` (`status`, `run_after`),
  KEY `idx_ce_jobs_user` (`user_id`, `created_at`),
  KEY `idx_ce_jobs_ref` (`ref_type`, `ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ce_credit_holds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `purpose` VARCHAR(40) NOT NULL,
  `ref_type` VARCHAR(30) NULL,
  `ref_id` BIGINT NULL,
  `reserved` INT NOT NULL DEFAULT 0,
  `used` INT NOT NULL DEFAULT 0,
  `refunded` INT NOT NULL DEFAULT 0,
  `status` ENUM('held','settled','released') NOT NULL DEFAULT 'held',
  `idem_key` VARCHAR(100) NOT NULL,
  `note` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `settled_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_holds_idem` (`idem_key`),
  KEY `idx_ce_holds_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ce_brand_dna` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `dna_json` MEDIUMTEXT NOT NULL,
  `edited_json` TEXT NULL COMMENT 'الحقول اللي العميل عدّلها بإيده — إعادة البناء مابتغيّرهاش',
  `prev_json` MEDIUMTEXT NULL COMMENT 'النسخة اللي قبلها (للرجوع)',
  `tokens_est` INT UNSIGNED NOT NULL DEFAULT 0,
  `source_hash` CHAR(40) NULL,
  `built_by` ENUM('ai','rules','user') NOT NULL DEFAULT 'rules',
  `job_id` BIGINT UNSIGNED NULL,
  `built_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_dna_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ce_campaigns` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `title` VARCHAR(200) NULL,
  `status` ENUM('draft','ready','queued','planning','writing','review','done','cancelled') NOT NULL DEFAULT 'draft',
  `goal` VARCHAR(30) NULL,
  `brief_json` MEDIUMTEXT NULL,
  `estimate_json` TEXT NULL,
  `est_credits` INT NOT NULL DEFAULT 0,
  `dna_version` INT UNSIGNED NULL,
  `hold_id` BIGINT UNSIGNED NULL,
  `job_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ce_campaigns_user` (`user_id`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- المحرك الجديد بالسماح بس (الأدمن بيفتحه لعملاء معيّنين من «الميزات») — الحملة القديمة شغالة زي ما هي
INSERT IGNORE INTO `feature_flags` (`feature_key`, `label_ar`, `default_mode`) VALUES
('content_engine', 'محرك المحتوى الجديد (Brand DNA + الحملة بالاختيارات)', 'allowlist');

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('ce_margin_pct', '100'),            -- هامش الكريدت فوق التكلفة الفعلية (%)
('ce_egp_per_credit', '1.5'),        -- الكريدت الواحد = كام جنيه (من متوسط أسعار الباقات)
('ce_min_credits_piece', '1'),       -- أقل كريدت لكل قطعة محتوى
('ce_revision_factor', '1.4'),       -- معامل إعادة الكتابة للكاتب والناقد
('ce_dna_cost', '0'),                -- بناء الـ Brand DNA (كريدت) — 0 = مجاني
('ce_dna_max_tokens', '1200'),
('ce_max_pieces', '60');             -- أقصى عدد قطع في حملة واحدة

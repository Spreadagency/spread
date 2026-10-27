-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — محرك المحتوى v3 · المرحلة F: الأفكار ← الاختيار ← الكتابة ← مراجعة الجودة
--   ce_ideas             3 أفكار لكل خانة (كل واحدة من دافع مختلف) + درجة الاختيار + المرفوض لأنه شبه فكرة قديمة
--   ce_contents          المحتوى المكتوب لكل خانة (بالشكل: بوست · كاروسيل · ريلز) + Design Brief + تقييم الجودة
--   ce_content_versions  كل نسخة اتكتبت (الأولى + جولات التحسين) بتقييمها
--   ce_campaigns         مراجعة الخطة النهائية + حجز الكتابة
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_ce_content_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ce_ideas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT UNSIGNED NOT NULL,
  `slot_id` INT UNSIGNED NOT NULL,
  `user_id` INT NOT NULL,
  `round` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `trigger_type` VARCHAR(20) NULL COMMENT 'curiosity · reassurance · identity · proof · humor',
  `title` VARCHAR(255) NOT NULL,
  `hook` VARCHAR(255) NOT NULL,
  `core_insight` VARCHAR(500) NULL,
  `why` VARCHAR(500) NULL,
  `risk` VARCHAR(255) NULL,
  `score` DECIMAL(5,1) NULL COMMENT 'درجة الناقد في الاختيار',
  `score_note` VARCHAR(255) NULL,
  `similarity` DECIMAL(4,3) NULL COMMENT 'أعلى تشابه مع أفكار قديمة (0-1)',
  `is_dup` TINYINT(1) NOT NULL DEFAULT 0,
  `selected` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ce_ideas_slot` (`slot_id`, `selected`),
  KEY `idx_ce_ideas_user` (`user_id`, `selected`),
  KEY `idx_ce_ideas_campaign` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ce_contents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT UNSIGNED NOT NULL,
  `slot_id` INT UNSIGNED NOT NULL,
  `user_id` INT NOT NULL,
  `idea_id` INT UNSIGNED NULL,
  `format` VARCHAR(20) NOT NULL DEFAULT 'post',
  `status` ENUM('pending','writing','pass','manual','failed') NOT NULL DEFAULT 'pending' COMMENT 'manual = محتاج مراجعة يدوية بعد جولتين',
  `version` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `body_json` MEDIUMTEXT NULL COMMENT 'hooks/body/cta · slides · scenes + design_brief',
  `qa_score` DECIMAL(5,1) NULL,
  `qa_json` MEDIUMTEXT NULL,
  `rounds` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `writer` VARCHAR(120) NULL COMMENT 'المزود/الموديل اللي كتب',
  `critic` VARCHAR(120) NULL COMMENT 'المزود/الموديل اللي راجع',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_contents_slot` (`slot_id`),
  KEY `idx_ce_contents_campaign` (`campaign_id`),
  KEY `idx_ce_contents_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ce_content_versions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `content_id` INT UNSIGNED NOT NULL,
  `version` SMALLINT UNSIGNED NOT NULL,
  `body_json` MEDIUMTEXT NOT NULL,
  `qa_score` DECIMAL(5,1) NULL,
  `qa_json` MEDIUMTEXT NULL,
  `verdict` VARCHAR(10) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_cv` (`content_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ce_campaigns' AND column_name = 'final_qa_json');
SET @sql := IF(@c = 0, 'ALTER TABLE `ce_campaigns` ADD COLUMN `final_qa_json` TEXT NULL, ADD COLUMN `write_hold_id` BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE m0 FROM @sql; EXECUTE m0; DEALLOCATE PREPARE m0;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('ce_qa_pass_total', '85'),          -- PASS: الإجمالي ≥ ده
('ce_qa_min_criterion', '70'),       -- … ومفيش معيار أقل من ده
('ce_qa_max_rounds', '2'),           -- جولات التحسين قبل «مراجعة يدوية»
('ce_select_min', '75'),             -- أقل درجة للفكرة المختارة
('ce_dedup_threshold', '0.85'),      -- الفكرة اللي تشابهها أكتر من كده مع فكرة قديمة بتترفض
('ce_critic_other_provider', '1');   -- الناقد من مزود غير الكاتب (لو متاح)

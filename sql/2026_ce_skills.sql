-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — محرك المحتوى v3 · المرحلة H2: Skills التخصصات (ملفات MD)
--   ce_skills          تخصص واحد لكل صف (المفتاح · الاسم · كلمات التعرّف)
--   ce_skill_versions  نسخ ملف الـ MD (النص الأصلي + الأقسام بعد القراية) — نسخة واحدة شغالة
--   ce_golden_cases.skill_id      حالات اختبار تخص Skill
--   ce_regression_runs (kind = skill) اختبار رجوع لنسخة Skill قدام الشغالة (أو من غير Skill)
--   ce_campaigns.skill_version_id  الـ Skill اللي الحملة اتعملت بيها (لحلقة التعلّم في I)
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_ce_skills_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ce_skills` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skey` VARCHAR(40) NOT NULL COMMENT 'مفتاح بالإنجليزي (dental · restaurant …)',
  `name` VARCHAR(100) NOT NULL,
  `match_words` VARCHAR(500) NULL COMMENT 'كلمات بنعرف بيها التخصص من مجال البراند (بفاصلة)',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_skill_key` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ce_skill_versions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skill_id` INT UNSIGNED NOT NULL,
  `version` SMALLINT UNSIGNED NOT NULL,
  `body_md` MEDIUMTEXT NOT NULL COMMENT 'ملف الـ MD زي ما اترفع',
  `sections_json` MEDIUMTEXT NOT NULL COMMENT 'الأقسام بعد القراية',
  `notes` VARCHAR(500) NULL,
  `source` VARCHAR(20) NOT NULL DEFAULT 'upload' COMMENT 'upload · edit · sample',
  `created_by` INT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `activated_at` DATETIME NULL COMMENT 'أول مرة اتفعّلت — بعدها الرجوع ليها من غير اختبار',
  `activated_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_sv` (`skill_id`, `version`),
  KEY `idx_ce_sv_active` (`skill_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الأعمدة الجديدة على جداول المحرك (بنتأكد الأول إنها مش موجودة)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ce_golden_cases' AND COLUMN_NAME = 'skill_id');
SET @q := IF(@c = 0, 'ALTER TABLE `ce_golden_cases` ADD COLUMN `skill_id` INT UNSIGNED NULL AFTER `stage`, ADD KEY `idx_ce_gc_skill` (`skill_id`, `is_active`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ce_regression_runs' AND COLUMN_NAME = 'skill_id');
SET @q := IF(@c = 0, 'ALTER TABLE `ce_regression_runs` MODIFY `kind` ENUM(''prompt'',''model'',''skill'') NOT NULL DEFAULT ''prompt'', ADD COLUMN `skill_id` INT UNSIGNED NULL AFTER `kind`, ADD COLUMN `candidate_skill_version_id` INT UNSIGNED NULL AFTER `skill_id`, ADD COLUMN `baseline_skill_version_id` INT UNSIGNED NULL AFTER `candidate_skill_version_id`, ADD KEY `idx_ce_rr_skill` (`skill_id`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ce_campaigns' AND COLUMN_NAME = 'skill_version_id');
SET @q := IF(@c = 0, 'ALTER TABLE `ce_campaigns` ADD COLUMN `skill_version_id` INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

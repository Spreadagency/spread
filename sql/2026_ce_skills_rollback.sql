-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_ce_skills.sql
-- ⚠️ بيمسح الـ Skills ونسخها وحالات/اختبارات الـ Skills. المحرك بيرجع يشتغل بالبرومبت العام.
-- ═══════════════════════════════════════════════════════════════════
DELETE FROM `ce_golden_cases` WHERE `skill_id` IS NOT NULL;
DELETE FROM `ce_regression_runs` WHERE `kind` = 'skill';
ALTER TABLE `ce_regression_runs` DROP KEY `idx_ce_rr_skill`, DROP COLUMN `baseline_skill_version_id`, DROP COLUMN `candidate_skill_version_id`, DROP COLUMN `skill_id`,
  MODIFY `kind` ENUM('prompt','model') NOT NULL DEFAULT 'prompt';
ALTER TABLE `ce_golden_cases` DROP KEY `idx_ce_gc_skill`, DROP COLUMN `skill_id`;
ALTER TABLE `ce_campaigns` DROP COLUMN `skill_version_id`;
DROP TABLE IF EXISTS `ce_skill_versions`;
DROP TABLE IF EXISTS `ce_skills`;

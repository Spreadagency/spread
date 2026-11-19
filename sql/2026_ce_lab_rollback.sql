-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_ce_lab.sql
-- ⚠️ بيمسح نسخ البرومبتات وحالات الاختبار وسجل الاختبارات. المحرك بيرجع للبرومبتات اللي في الكود (v0).
-- ═══════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS `ce_regression_runs`;
DROP TABLE IF EXISTS `ce_golden_cases`;
DROP TABLE IF EXISTS `ce_prompt_versions`;
DELETE FROM `settings` WHERE `setting_key` = 'ce_regression_max_drop';

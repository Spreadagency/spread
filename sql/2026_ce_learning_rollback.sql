-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_ce_learning.sql
-- ⚠️ بيمسح النتايج اللي العملاء دخّلوها والتقارير الشهرية. ملفات الهوية مابتتغيرش.
-- ═══════════════════════════════════════════════════════════════════
UPDATE `ce_brand_dna` SET `built_by` = 'rules' WHERE `built_by` = 'learning';
ALTER TABLE `ce_brand_dna` MODIFY `built_by` ENUM('ai','rules','user') NOT NULL DEFAULT 'rules';
DROP TABLE IF EXISTS `ce_learning_reports`;
DROP TABLE IF EXISTS `ce_performance`;
DELETE FROM `settings` WHERE `setting_key` IN ('ce_learning_min_n', 'ce_learning_apply', 'ce_learning_last_period');

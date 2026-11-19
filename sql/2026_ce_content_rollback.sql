-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_ce_content.sql
-- ⚠️ بيمسح الأفكار والمحتوى المكتوب بالمحرك الجديد ونسخه. خد نسخة احتياطية الأول.
--    الحملات اللي حالتها writing/done بترجع «الخطة معتمدة».
-- ═══════════════════════════════════════════════════════════════════

DROP TABLE IF EXISTS `ce_content_versions`;
DROP TABLE IF EXISTS `ce_contents`;
DROP TABLE IF EXISTS `ce_ideas`;

UPDATE `ce_campaigns` SET `status` = 'plan_ok' WHERE `status` IN ('writing', 'done');

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ce_campaigns' AND column_name = 'final_qa_json');
SET @sql := IF(@c = 1, 'ALTER TABLE `ce_campaigns` DROP COLUMN `final_qa_json`, DROP COLUMN `write_hold_id`', 'SELECT 1');
PREPARE m0 FROM @sql; EXECUTE m0; DEALLOCATE PREPARE m0;

DELETE FROM `settings` WHERE `setting_key` IN ('ce_qa_pass_total', 'ce_qa_min_criterion', 'ce_qa_max_rounds', 'ce_select_min',
    'ce_dedup_threshold', 'ce_critic_other_provider', 'ce_qa_weights');

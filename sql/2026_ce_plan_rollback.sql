-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_ce_plan.sql
-- ⚠️ بيمسح خطط الحملات وتقييماتها ومكتبة الزوايا. خد نسخة احتياطية الأول.
--    الحملات اللي حالتها plan_ok/review بترجع «جاهزة للتشغيل».
-- ═══════════════════════════════════════════════════════════════════

DROP TABLE IF EXISTS `ce_plan_evals`;
DROP TABLE IF EXISTS `ce_slots`;
DROP TABLE IF EXISTS `ce_angles`;

UPDATE `ce_campaigns` SET `status` = 'ready' WHERE `status` IN ('plan_ok', 'review', 'planning');
ALTER TABLE `ce_campaigns` MODIFY `status` ENUM('draft','ready','queued','planning','writing','review','done','cancelled') NOT NULL DEFAULT 'draft';

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ce_campaigns' AND column_name = 'plan_score');
SET @sql := IF(@c = 1, 'ALTER TABLE `ce_campaigns` DROP COLUMN `plan_score`, DROP COLUMN `plan_passed`, DROP COLUMN `start_date`, DROP COLUMN `plan_hold_id`', 'SELECT 1');
PREPARE m0 FROM @sql; EXECUTE m0; DEALLOCATE PREPARE m0;

DELETE FROM `settings` WHERE `setting_key` IN ('ce_plan_threshold', 'ce_plan_max_rounds', 'ce_plan_min_credits', 'ce_funnel_mix', 'ce_plan_weights');

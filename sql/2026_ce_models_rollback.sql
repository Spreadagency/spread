-- الرجوع عن 2026_ce_models.sql — بيمسح سجل الموديلات والإعدادات الجاهزة ومراحل المحرك والأعمدة الجديدة (خد نسخة احتياطية الأول)
DROP TABLE IF EXISTS `ai_model_presets`;
DROP TABLE IF EXISTS `ai_model_registry`;
DELETE FROM `ai_task_routes` WHERE `stage_group` = 'engine';

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND index_name = 'idx_runs_sandbox');
SET @sql := IF(@c > 0, 'ALTER TABLE `ai_runs` DROP INDEX `idx_runs_sandbox`', 'SELECT 1');
PREPARE cr0 FROM @sql; EXECUTE cr0; DEALLOCATE PREPARE cr0;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND index_name = 'idx_runs_campaign');
SET @sql := IF(@c > 0, 'ALTER TABLE `ai_runs` DROP INDEX `idx_runs_campaign`', 'SELECT 1');
PREPARE cr1 FROM @sql; EXECUTE cr1; DEALLOCATE PREPARE cr1;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND column_name = 'campaign_id');
SET @sql := IF(@c > 0, 'ALTER TABLE `ai_runs` DROP COLUMN `campaign_id`', 'SELECT 1');
PREPARE cr3 FROM @sql; EXECUTE cr3; DEALLOCATE PREPARE cr3;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND column_name = 'prompt_version_id');
SET @sql := IF(@c > 0, 'ALTER TABLE `ai_runs` DROP COLUMN `prompt_version_id`', 'SELECT 1');
PREPARE cr4 FROM @sql; EXECUTE cr4; DEALLOCATE PREPARE cr4;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND column_name = 'model_id');
SET @sql := IF(@c > 0, 'ALTER TABLE `ai_runs` DROP COLUMN `model_id`', 'SELECT 1');
PREPARE cr5 FROM @sql; EXECUTE cr5; DEALLOCATE PREPARE cr5;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND column_name = 'tokens_cached');
SET @sql := IF(@c > 0, 'ALTER TABLE `ai_runs` DROP COLUMN `tokens_cached`', 'SELECT 1');
PREPARE cr6 FROM @sql; EXECUTE cr6; DEALLOCATE PREPARE cr6;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND column_name = 'retry_of');
SET @sql := IF(@c > 0, 'ALTER TABLE `ai_runs` DROP COLUMN `retry_of`', 'SELECT 1');
PREPARE cr7 FROM @sql; EXECUTE cr7; DEALLOCATE PREPARE cr7;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND column_name = 'sandbox');
SET @sql := IF(@c > 0, 'ALTER TABLE `ai_runs` DROP COLUMN `sandbox`', 'SELECT 1');
PREPARE cr8 FROM @sql; EXECUTE cr8; DEALLOCATE PREPARE cr8;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_attempts' AND column_name = 'tokens_cached');
SET @sql := IF(@c > 0, 'ALTER TABLE `ai_attempts` DROP COLUMN `tokens_cached`', 'SELECT 1');
PREPARE cr9 FROM @sql; EXECUTE cr9; DEALLOCATE PREPARE cr9;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_task_routes' AND column_name = 'temperature');
SET @sql := IF(@c > 0, 'ALTER TABLE `ai_task_routes` DROP COLUMN `temperature`', 'SELECT 1');
PREPARE cr10 FROM @sql; EXECUTE cr10; DEALLOCATE PREPARE cr10;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_task_routes' AND column_name = 'stage_group');
SET @sql := IF(@c > 0, 'ALTER TABLE `ai_task_routes` DROP COLUMN `stage_group`', 'SELECT 1');
PREPARE cr11 FROM @sql; EXECUTE cr11; DEALLOCATE PREPARE cr11;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_task_routes' AND column_name = 'sort_order');
SET @sql := IF(@c > 0, 'ALTER TABLE `ai_task_routes` DROP COLUMN `sort_order`', 'SELECT 1');
PREPARE cr12 FROM @sql; EXECUTE cr12; DEALLOCATE PREPARE cr12;

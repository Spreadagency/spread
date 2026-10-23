-- ═══════════════════════════════════════════════════════════════════
-- الرجوع عن 2026_mcp_foundation.sql
-- ⚠️ بيمسح البلاغات وتقييمات التصميم والأحداث — خد نسخة احتياطية الأول
-- ═══════════════════════════════════════════════════════════════════

DROP TABLE IF EXISTS `user_issues`;
DROP TABLE IF EXISTS `design_ratings`;
DROP TABLE IF EXISTS `ai_events`;

-- النسخة المفعّلة (is_active) بتفضل زي ما هي — نسخ «التجربة» بتتشال مع عمود status

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'status');
SET @sql := IF(@c = 1, 'ALTER TABLE `prompts` DROP COLUMN `status`', 'SELECT 1');
PREPARE r0 FROM @sql; EXECUTE r0; DEALLOCATE PREPARE r0;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'traffic_percent');
SET @sql := IF(@c = 1, 'ALTER TABLE `prompts` DROP COLUMN `traffic_percent`', 'SELECT 1');
PREPARE r1 FROM @sql; EXECUTE r1; DEALLOCATE PREPARE r1;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'created_by_kind');
SET @sql := IF(@c = 1, 'ALTER TABLE `prompts` DROP COLUMN `created_by_kind`', 'SELECT 1');
PREPARE r2 FROM @sql; EXECUTE r2; DEALLOCATE PREPARE r2;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'reason');
SET @sql := IF(@c = 1, 'ALTER TABLE `prompts` DROP COLUMN `reason`', 'SELECT 1');
PREPARE r3 FROM @sql; EXECUTE r3; DEALLOCATE PREPARE r3;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'parent_id');
SET @sql := IF(@c = 1, 'ALTER TABLE `prompts` DROP COLUMN `parent_id`', 'SELECT 1');
PREPARE r4 FROM @sql; EXECUTE r4; DEALLOCATE PREPARE r4;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'approved_at');
SET @sql := IF(@c = 1, 'ALTER TABLE `prompts` DROP COLUMN `approved_at`', 'SELECT 1');
PREPARE r5 FROM @sql; EXECUTE r5; DEALLOCATE PREPARE r5;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'prompts' AND column_name = 'approved_by');
SET @sql := IF(@c = 1, 'ALTER TABLE `prompts` DROP COLUMN `approved_by`', 'SELECT 1');
PREPARE r6 FROM @sql; EXECUTE r6; DEALLOCATE PREPARE r6;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'prompt_version_id');
SET @sql := IF(@c = 1, 'ALTER TABLE `contents` DROP COLUMN `prompt_version_id`', 'SELECT 1');
PREPARE r7 FROM @sql; EXECUTE r7; DEALLOCATE PREPARE r7;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'provider');
SET @sql := IF(@c = 1, 'ALTER TABLE `contents` DROP COLUMN `provider`', 'SELECT 1');
PREPARE r8 FROM @sql; EXECUTE r8; DEALLOCATE PREPARE r8;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'content_designs' AND column_name = 'prompt_version_id');
SET @sql := IF(@c = 1, 'ALTER TABLE `content_designs` DROP COLUMN `prompt_version_id`', 'SELECT 1');
PREPARE r9 FROM @sql; EXECUTE r9; DEALLOCATE PREPARE r9;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'content_designs' AND column_name = 'provider');
SET @sql := IF(@c = 1, 'ALTER TABLE `content_designs` DROP COLUMN `provider`', 'SELECT 1');
PREPARE r10 FROM @sql; EXECUTE r10; DEALLOCATE PREPARE r10;

DELETE FROM `settings` WHERE `setting_key` IN ('issues_enabled', 'issue_rate_per_hour');

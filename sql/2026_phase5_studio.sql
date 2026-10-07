-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة ⑤: Design Studio
--   التصميم بيفتكر الـ Creative Brief والكابشن بتاعه، والمنشور اللي اتحوّل له
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════
SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'studio_designs' AND column_name = 'brief');
SET @sql := IF(@c = 0, 'ALTER TABLE `studio_designs` ADD COLUMN `brief` MEDIUMTEXT NULL', 'SELECT 1');
PREPARE s1 FROM @sql; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'studio_designs' AND column_name = 'content_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `studio_designs` ADD COLUMN `content_id` INT NULL, ADD KEY `idx_studio_content` (`content_id`)', 'SELECT 1');
PREPARE s2 FROM @sql; EXECUTE s2; DEALLOCATE PREPARE s2;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('studio_idea_cost', '1');

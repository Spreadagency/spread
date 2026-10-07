-- =========================================================
-- Spread AI — Performance Indexes (v2)
-- Safe to run multiple times (checks information_schema first)
-- Run via phpMyAdmin on the production database
-- =========================================================

-- 1) contents: base schema already ships idx_user_date (user_id, created_at).
--    security-upgrade.sql may have added a duplicate named idx_user_created.
--    Clean up the duplicate if both exist:
SET @has_date := (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = 'contents'
                    AND index_name = 'idx_user_date');
SET @has_created := (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
                     WHERE table_schema = DATABASE() AND table_name = 'contents'
                       AND index_name = 'idx_user_created');
SET @sql := IF(@has_date > 0 AND @has_created > 0,
    'ALTER TABLE `contents` DROP INDEX `idx_user_created`',
    'SELECT "no duplicate contents index"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

--    And make sure at least one (user_id, created_at) index exists:
SET @has_any := (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'contents'
                   AND index_name IN ('idx_user_date', 'idx_user_created'));
SET @sql := IF(@has_any = 0,
    'ALTER TABLE `contents` ADD INDEX `idx_user_date` (`user_id`, `created_at`)',
    'SELECT "contents user/date index present"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) content_versions(content_id, version_number) — speeds MAX(version) per content
SET @idx := (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'content_versions'
               AND index_name = 'idx_content_version');
SET @sql := IF(@idx = 0,
    'ALTER TABLE `content_versions` ADD INDEX `idx_content_version` (`content_id`, `version_number`)',
    'SELECT "idx_content_version already exists"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) email_verifications(user_id, created_at) — resend-throttle lookup
SET @idx := (SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'email_verifications'
               AND index_name = 'idx_user_created');
SET @sql := IF(@idx = 0,
    'ALTER TABLE `email_verifications` ADD INDEX `idx_user_created` (`user_id`, `created_at`)',
    'SELECT "idx_user_created already exists"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

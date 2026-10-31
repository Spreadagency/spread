-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — محرك المحتوى v3 · ربط النتايج بـ Meta Insights
--   ce_performance: القطعة بتتربط بالبوست اللي نزل على فيسبوك/إنستجرام (يدوي أو تلقائي بتشابه النص)
--   والأرقام بتتسحب من Insights كل كام ساعة لحد 30 يوم بعد النشر
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_ce_meta_insights_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ce_performance' AND COLUMN_NAME = 'provider_post_id');
SET @q := IF(@c = 0, 'ALTER TABLE `ce_performance`
  ADD COLUMN `connection_id` INT NULL AFTER `source`,
  ADD COLUMN `provider_post_id` VARCHAR(120) NULL AFTER `connection_id`,
  ADD COLUMN `permalink` VARCHAR(500) NULL AFTER `provider_post_id`,
  ADD COLUMN `link_mode` VARCHAR(10) NULL COMMENT ''manual · auto'' AFTER `permalink`,
  ADD COLUMN `synced_at` DATETIME NULL AFTER `link_mode`,
  ADD COLUMN `sync_status` VARCHAR(20) NULL COMMENT ''ok · partial · error · expired'' AFTER `synced_at`,
  ADD COLUMN `sync_error` VARCHAR(300) NULL AFTER `sync_status`,
  ADD KEY `idx_ce_perf_post` (`provider_post_id`),
  ADD KEY `idx_ce_perf_sync` (`synced_at`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('ce_meta_sync_days', '30'),       -- بنسحب الأرقام لحد كام يوم بعد النشر
('ce_meta_sync_hours', '12'),      -- كل كام ساعة نحدّث البوست الواحد
('ce_meta_auto_match', '1'),       -- نربط القطع بالبوستات تلقائي لو النص شبه بعض
('ce_meta_match_min', '0.75'),     -- أقل تشابه للربط التلقائي
('ce_meta_last_sync', ''),
('ce_meta_last_match', '');

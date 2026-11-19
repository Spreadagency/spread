-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_ce_meta_insights.sql
-- الأرقام اللي اتسحبت بتفضل (كأنها اتدخلت يدوي) — بيتشال الربط بالبوستات بس.
-- ═══════════════════════════════════════════════════════════════════
UPDATE `ce_performance` SET `source` = 'manual' WHERE `source` = 'meta';
ALTER TABLE `ce_performance` DROP KEY `idx_ce_perf_post`, DROP KEY `idx_ce_perf_sync`,
  DROP COLUMN `connection_id`, DROP COLUMN `provider_post_id`, DROP COLUMN `permalink`, DROP COLUMN `link_mode`,
  DROP COLUMN `synced_at`, DROP COLUMN `sync_status`, DROP COLUMN `sync_error`;
DELETE FROM `settings` WHERE `setting_key` IN ('ce_meta_sync_days', 'ce_meta_sync_hours', 'ce_meta_auto_match', 'ce_meta_match_min', 'ce_meta_last_sync', 'ce_meta_last_match');

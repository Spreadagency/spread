-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_ce_library.sql
-- بيشيل الربط بس — المنشورات والحملات اللي اتنسخت للمكتبة بتفضل منشورات عادية.
-- ═══════════════════════════════════════════════════════════════════
ALTER TABLE `ce_campaigns` DROP COLUMN `lib_campaign_id`;
ALTER TABLE `ce_contents` DROP KEY `idx_ce_contents_lib`, DROP COLUMN `lib_content_id`, DROP COLUMN `lib_synced_at`;
ALTER TABLE `ce_designs` DROP COLUMN `lib_design_id`;

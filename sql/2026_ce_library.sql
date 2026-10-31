-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — محرك المحتوى v3 · قطع المحرك في مكتبة المحتوى والتقويم
--   كل حملة من المحرك بيبقى ليها حملة في «حملاتي» (campaigns) · كل قطعة = منشور عادي في contents
--   (بفكرته في campaign_ideas وميعادها في plan_at) · وكل تصميم نهائي = صف في content_designs
--   الأعمدة دي بتربط نسخة المكتبة بالأصل في المحرك
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_ce_library_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ce_campaigns' AND COLUMN_NAME = 'lib_campaign_id');
SET @q := IF(@c = 0, 'ALTER TABLE `ce_campaigns` ADD COLUMN `lib_campaign_id` INT NULL COMMENT ''الحملة في «حملاتي»''', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ce_contents' AND COLUMN_NAME = 'lib_content_id');
SET @q := IF(@c = 0, 'ALTER TABLE `ce_contents` ADD COLUMN `lib_content_id` INT NULL COMMENT ''المنشور في المكتبة (contents) · -1 = اتمسح من المكتبة'', ADD COLUMN `lib_synced_at` DATETIME NULL, ADD KEY `idx_ce_contents_lib` (`lib_content_id`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ce_designs' AND COLUMN_NAME = 'lib_design_id');
SET @q := IF(@c = 0, 'ALTER TABLE `ce_designs` ADD COLUMN `lib_design_id` INT NULL COMMENT ''التصميم في content_designs''', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — محرك المحتوى v3 · تعديلات التصميم بعد المراجعة
--   ce_designs.font_family   خط العنوان لتصميم واحد (من خطوط البراند اللي اختارها — لحد 3)
--   كل شرايح الكاروسيل بصورة AI بقت الافتراضي (0 = كلها)
--   غلاف الريلز بقى اختيار العميل (الإعداد ده الافتراضي في الـ Wizard بس)
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_ce_design_v2_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ce_designs' AND column_name = 'font_family');
SET @sql := IF(@c = 0, 'ALTER TABLE `ce_designs` ADD COLUMN `font_family` VARCHAR(60) NULL AFTER `subline`', 'SELECT 1');
PREPARE m0 FROM @sql; EXECUTE m0; DEALLOCATE PREPARE m0;

-- الافتراضيات الجديدة (لو لسه على القديمة بس)
UPDATE `settings` SET `setting_value` = '0' WHERE `setting_key` = 'ce_carousel_ai_images' AND `setting_value` = '1';
UPDATE `settings` SET `setting_value` = '0' WHERE `setting_key` = 'ce_reel_cover' AND `setting_value` = '1';

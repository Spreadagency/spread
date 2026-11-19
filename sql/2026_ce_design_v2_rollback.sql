-- Spread AI — الرجوع عن 2026_ce_design_v2.sql (بيشيل عمود خط العنوان · الإعدادات بتفضل زي ما هي)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ce_designs' AND column_name = 'font_family');
SET @sql := IF(@c = 1, 'ALTER TABLE `ce_designs` DROP COLUMN `font_family`', 'SELECT 1');
PREPARE m0 FROM @sql; EXECUTE m0; DEALLOCATE PREPARE m0;

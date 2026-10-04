-- ═══════════════════════════════════════════════════════════
-- موقع Spread AI — الرئيسية الجديدة (قاعدة بيانات الموقع، مش المنصة)
--   بيتشغّل تلقائيًا أول فتح للرئيسية (site/home.php ← s_home_upgrade) — آمن للتكرار
--   ① الشرائح: شارة فوق العنوان · وضع الروبوت · زرار تاني
--   ② الخدمات: عنوان عربي فرعي · نقاط · رابط «جرّب»
--   ③ الحلول: «بدل: …»
--   ④ العروض: شارة · نص الزرار · كود خصم يتطبّق في صفحة الدفع
-- ═══════════════════════════════════════════════════════════

SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'site_slides' AND column_name = 'tag');
SET @sql := IF(@c = 0, 'ALTER TABLE `site_slides` ADD COLUMN `tag` VARCHAR(150) NULL, ADD COLUMN `bot` VARCHAR(20) NULL, ADD COLUMN `cta2_text` VARCHAR(100) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'site_services' AND column_name = 'subtitle');
SET @sql := IF(@c = 0, 'ALTER TABLE `site_services` ADD COLUMN `subtitle` VARCHAR(150) NULL, ADD COLUMN `bullets` TEXT NULL, ADD COLUMN `link_text` VARCHAR(80) NULL, ADD COLUMN `link_url` VARCHAR(500) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'site_solutions' AND column_name = 'instead_of');
SET @sql := IF(@c = 0, 'ALTER TABLE `site_solutions` ADD COLUMN `instead_of` VARCHAR(150) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'site_promos' AND column_name = 'promo_code');
SET @sql := IF(@c = 0, 'ALTER TABLE `site_promos` ADD COLUMN `badge` VARCHAR(80) NULL, ADD COLUMN `btn_text` VARCHAR(80) NULL, ADD COLUMN `promo_code` VARCHAR(40) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

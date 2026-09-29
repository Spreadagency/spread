-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة 9: مشاريع الاستوديو · تصميمات بتعجبك · سلايدر الرئيسية
--   ① studio_drafts: مشروع التصميم اللي لسه مكملش بيتحفظ (الطريقة · الكلام · الـ Brief · الصور) ويرجعله العميل
--   ② brand_inspirations: صور/لينكات تصميمات عاجبة العميل (Brand Brain) — مرجع ستايل في الاستوديو
--   ③ announcements: زرار · جمهور (كل العملاء / باقة) · بداية ونهاية بالساعة — لسلايدر الرئيسية
-- آمن للتشغيل المتكرر (والكود نفسه بينشئ الناقص لو الملف ماتشغلش)
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `studio_drafts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `title` VARCHAR(160) NOT NULL DEFAULT '',
    `method` VARCHAR(40) NOT NULL DEFAULT '',
    `step` VARCHAR(16) NOT NULL DEFAULT 'input',
    `state_json` MEDIUMTEXT NULL,
    `files_json` TEXT NULL,
    `design_id` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_user_updated` (`user_id`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `brand_inspirations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `brand_profile_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `image_path` VARCHAR(255) NULL,
    `link_url` VARCHAR(1000) NULL,
    `note` VARCHAR(300) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_brand` (`brand_profile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'announcements' AND column_name = 'btn_label');
SET @sql := IF(@c = 0, 'ALTER TABLE `announcements` ADD COLUMN `btn_label` VARCHAR(60) NULL AFTER `link_url`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'announcements' AND column_name = 'audience');
SET @sql := IF(@c = 0, 'ALTER TABLE `announcements` ADD COLUMN `audience` VARCHAR(20) NOT NULL DEFAULT ''all'' AFTER `btn_label`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'announcements' AND column_name = 'package_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `announcements` ADD COLUMN `package_id` INT NULL AFTER `audience`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- المواعيد بالساعة (الإعلانات القديمة: النهاية = آخر اليوم)
SET @t := (SELECT data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'announcements' AND column_name = 'ends_at');
SET @sql := IF(@t = 'date', 'ALTER TABLE `announcements` MODIFY COLUMN `starts_at` DATETIME NULL, MODIFY COLUMN `ends_at` DATETIME NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 'date', 'UPDATE `announcements` SET `ends_at` = DATE_ADD(`ends_at`, INTERVAL ''23:59:59'' HOUR_SECOND) WHERE `ends_at` IS NOT NULL AND TIME(`ends_at`) = ''00:00:00''', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO `settings` (`setting_key`, `setting_value`)
SELECT 'ann_schema_v', '2' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'ann_schema_v');

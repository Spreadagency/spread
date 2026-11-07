-- التجربة المجانية: «عرّفنا على مشروعك» من لينك الموقع / صفحة الفيسبوك + الاستكمال من نفس المرحلة
-- الأعمدة بتتضاف تلقائيًا أول ما حد يفتح التجربة (includes/trial-brand.php) — الملف ده للتشغيل اليدوي بس.
-- «ADD COLUMN IF NOT EXISTS» محتاجة MariaDB (موجودة في cPanel). على MySQL 8 شيل «IF NOT EXISTS» لو العمود مش موجود.

ALTER TABLE `trial_sessions` ADD COLUMN IF NOT EXISTS `source_url` VARCHAR(500) NULL;
ALTER TABLE `trial_sessions` ADD COLUMN IF NOT EXISTS `source_type` VARCHAR(20) NULL;
ALTER TABLE `trial_sessions` ADD COLUMN IF NOT EXISTS `profile_json` MEDIUMTEXT NULL;
ALTER TABLE `trial_sessions` ADD COLUMN IF NOT EXISTS `source_text` MEDIUMTEXT NULL;
ALTER TABLE `trial_sessions` ADD COLUMN IF NOT EXISTS `stage` VARCHAR(20) NULL;
ALTER TABLE `trial_sessions` ADD COLUMN IF NOT EXISTS `ua_hash` CHAR(40) NULL;
ALTER TABLE `trial_sessions` ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NULL;
ALTER TABLE `trial_sessions` ADD INDEX IF NOT EXISTS `idx_trial_ip` (`ip`, `claimed_user_id`);

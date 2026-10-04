-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة ⑦-ج: أشكال المحتوى (Post · Carousel · Video · Story)
--   Post / Story  → تصميم واحد
--   Carousel      → العميل بيختار عدد الشرائح ← تصميم لكل شريحة (بنفس الهوية والمقاس)
--   Video         → سكريبت ← اعتماد العميل ← طلب تنفيذ على واتساب ← تنفيذ يدوي ← تسليم
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════

-- contents
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'format');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents` ADD COLUMN `format` VARCHAR(12) NOT NULL DEFAULT ''post'', ADD INDEX `idx_contents_format` (`format`)', 'SELECT 1');
PREPARE f1 FROM @sql; EXECUTE f1; DEALLOCATE PREPARE f1;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'slides_count');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents` ADD COLUMN `slides_count` TINYINT UNSIGNED NULL, ADD COLUMN `slides_json` MEDIUMTEXT NULL', 'SELECT 1');
PREPARE f2 FROM @sql; EXECUTE f2; DEALLOCATE PREPARE f2;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'video_status');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents` ADD COLUMN `video_status` VARCHAR(20) NULL, ADD COLUMN `video_brief_json` TEXT NULL, ADD COLUMN `video_status_at` DATETIME NULL, ADD COLUMN `video_delivery_url` VARCHAR(500) NULL, ADD COLUMN `video_admin_note` VARCHAR(500) NULL, ADD INDEX `idx_contents_video` (`video_status`)', 'SELECT 1');
PREPARE f3 FROM @sql; EXECUTE f3; DEALLOCATE PREPARE f3;

-- content_designs: رقم الشريحة + الصور المرجعية اللي اتبعتت للموديل (للأدمن)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'content_designs' AND column_name = 'slide_no');
SET @sql := IF(@c = 0, 'ALTER TABLE `content_designs` ADD COLUMN `slide_no` TINYINT UNSIGNED NULL, ADD COLUMN `refs_json` TEXT NULL', 'SELECT 1');
PREPARE f4 FROM @sql; EXECUTE f4; DEALLOCATE PREPARE f4;

-- campaign_ideas: الشكل اللي العميل اختاره للفكرة + عدد الشرائح
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'campaign_ideas' AND column_name = 'format');
SET @sql := IF(@c = 0, 'ALTER TABLE `campaign_ideas` ADD COLUMN `format` VARCHAR(12) NULL, ADD COLUMN `slides_count` TINYINT UNSIGNED NULL', 'SELECT 1');
PREPARE f5 FROM @sql; EXECUTE f5; DEALLOCATE PREPARE f5;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('video_whatsapp',          ''),     -- رقم واتساب طلبات تنفيذ الفيديو (فاضي = رقم الواتساب العائم أو رقم الدفع)
('carousel_min_slides',     '2'),
('carousel_max_slides',     '10'),   -- إنستجرام بيقبل لحد 10 صور في الكاروسيل
('carousel_default_slides', '5');

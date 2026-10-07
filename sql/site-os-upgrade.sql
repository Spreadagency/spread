-- ═══════════════════════════════════════════════════════════
-- موقع Spread AI — ترقية «Website OS» (لوحة إدارة الموقع الجديدة)
--   قاعدة بيانات الموقع (مش المنصة) — بتتشغّل تلقائيًا أول فتح لأي صفحة (site/upgrade.php)
--   والملف ده نسخة يدوية لو حابب تشغّلها من phpMyAdmin. آمن للتكرار — إضافات بس، مفيش حذف.
-- ═══════════════════════════════════════════════════════════
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `site_testimonials` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(150) NOT NULL, `company` VARCHAR(150) NULL, `role_title` VARCHAR(150) NULL,
  `avatar_path` VARCHAR(500) NULL, `avatar_url` VARCHAR(700) NULL, `content` TEXT NOT NULL, `rating` TINYINT NOT NULL DEFAULT 0,
  `video_url` VARCHAR(700) NULL, `is_featured` TINYINT(1) NOT NULL DEFAULT 0, `is_active` TINYINT(1) NOT NULL DEFAULT 1, `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_t_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_faqs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `question` VARCHAR(300) NOT NULL, `answer` TEXT NOT NULL, `category` VARCHAR(80) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1, `sort_order` INT NOT NULL DEFAULT 0, KEY `idx_f_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_media` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `path` VARCHAR(500) NOT NULL, `original_name` VARCHAR(255) NULL, `mime` VARCHAR(80) NULL,
  `size` INT NOT NULL DEFAULT 0, `width` INT NULL, `height` INT NULL, `alt` VARCHAR(255) NULL, `admin_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY `uniq_media_path` (`path`(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_activity_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY, `admin_id` INT NULL, `admin_name` VARCHAR(150) NULL, `action` VARCHAR(40) NOT NULL,
  `section` VARCHAR(60) NOT NULL, `target_id` INT NULL, `summary` VARCHAR(500) NOT NULL, `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY `idx_al_date` (`created_at`), KEY `idx_al_section` (`section`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_redirects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `from_slug` VARCHAR(120) NOT NULL UNIQUE, `to_slug` VARCHAR(120) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_pageviews` (
  `day` DATE NOT NULL, `path` VARCHAR(190) NOT NULL, `views` INT NOT NULL DEFAULT 0, PRIMARY KEY (`day`, `path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الأدوار (الأدمن الموجودين = Super Admin)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'site_admins' AND column_name = 'role');
SET @sql := IF(@c = 0, 'ALTER TABLE `site_admins` ADD COLUMN `role` VARCHAR(30) NOT NULL DEFAULT ''super_admin'', ADD COLUMN `last_login_at` DATETIME NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- الصفحات: الحالة · SEO · OG · Page Builder
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'site_pages' AND column_name = 'blocks_json');
SET @sql := IF(@c = 0, 'ALTER TABLE `site_pages` ADD COLUMN `status` VARCHAR(12) NOT NULL DEFAULT ''published'', ADD COLUMN `seo_title` VARCHAR(255) NULL,
  ADD COLUMN `seo_description` VARCHAR(500) NULL, ADD COLUMN `og_title` VARCHAR(255) NULL, ADD COLUMN `og_description` VARCHAR(500) NULL,
  ADD COLUMN `og_image` VARCHAR(700) NULL, ADD COLUMN `canonical_url` VARCHAR(700) NULL, ADD COLUMN `noindex` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `featured_image` VARCHAR(700) NULL, ADD COLUMN `blocks_json` MEDIUMTEXT NULL, ADD COLUMN `show_cta` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN `published_at` DATETIME NULL, ADD COLUMN `created_at` DATETIME NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
UPDATE `site_pages` SET `status` = 'draft' WHERE `is_active` = 0 AND `published_at` IS NULL AND `created_at` IS NULL;
UPDATE `site_pages` SET `created_at` = `updated_at` WHERE `created_at` IS NULL;

-- أقسام الرئيسية الجديدة (قبل «جاهز نبدأ»)
INSERT IGNORE INTO `site_sections` (`section_key`, `label_ar`, `title`, `subtitle`, `is_visible`, `sort_order`) VALUES
('testimonials', 'آراء العملاء', 'عملاء بيتكلموا عن Spread AI', 'تجارب حقيقية من أصحاب مشاريع بيعملوا محتواهم معانا.', 1, 12),
('faq', 'الأسئلة الشائعة', 'أسئلة بتتكرر', 'كل اللي محتاج تعرفه قبل ما تبدأ.', 1, 13);

-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة 2: Brand Brain
--   • حقائق البراند بمصدرها (من الموقع · من الملف · من اللوجو · اقتراح AI · مؤكد)
--   • اعتماد الهوية
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════
SET NAMES utf8mb4;

-- كل معلومة عن البراند ومصدرها.
-- suggested = مقترحة ومستنية قرار العميل · applied = اتطبّقت على الهوية · rejected = اترفضت
CREATE TABLE IF NOT EXISTS `brand_facts` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_profile_id` INT NOT NULL,
  `user_id`          INT NOT NULL,
  `field`            VARCHAR(40) NOT NULL,
  `value`            TEXT NOT NULL,
  `source`           ENUM('manual','website','social','file','logo','ai') NOT NULL DEFAULT 'ai',
  `source_ref`       VARCHAR(500) NULL,
  `confidence`       TINYINT UNSIGNED NOT NULL DEFAULT 70,
  `status`           ENUM('suggested','applied','rejected') NOT NULL DEFAULT 'suggested',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_brand_status` (`brand_profile_id`, `status`),
  KEY `idx_brand_field`  (`brand_profile_id`, `field`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'brand_profiles' AND column_name = 'brand_approved_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `brand_profiles` ADD COLUMN `brand_approved_at` DATETIME NULL', 'SELECT 1');
PREPARE b1 FROM @sql; EXECUTE b1; DEALLOCATE PREPARE b1;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('brand_gate_pct',       '75'),   -- المحتوى والتصميم في الحملات بيفتحوا عند النسبة دي (0 = مقفول)
('brand_analyze_cost',   '1'),    -- كريدت تحليل رابط بالـ AI (بيرجع لو فشل)
('brand_analyze_enabled','1');

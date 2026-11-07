-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — محرك المحتوى v3 · المرحلة I: حلقة التعلّم + لوحة الاستخدام
--   ce_performance       نتايج كل قطعة بعد النشر (يدوي دلوقتي — متصمم يتربط بـ Meta Insights بعدين)
--   ce_learning_reports  التقرير الشهري: علاقة درجة الناقد بالأداء الحقيقي (عام · لكل تخصص · لكل براند)
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_ce_learning_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ce_performance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `campaign_id` INT UNSIGNED NOT NULL,
  `content_id` INT UNSIGNED NOT NULL,
  `platform` VARCHAR(20) NOT NULL DEFAULT 'facebook',
  `published_at` DATE NULL,
  `reach` INT UNSIGNED NOT NULL DEFAULT 0,
  `impressions` INT UNSIGNED NOT NULL DEFAULT 0,
  `likes` INT UNSIGNED NOT NULL DEFAULT 0,
  `comments` INT UNSIGNED NOT NULL DEFAULT 0,
  `shares` INT UNSIGNED NOT NULL DEFAULT 0,
  `saves` INT UNSIGNED NOT NULL DEFAULT 0,
  `clicks` INT UNSIGNED NOT NULL DEFAULT 0,
  `messages` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'رسايل/مكالمات/حجوزات جاية من البوست',
  `eng_rate` DECIMAL(8,3) NOT NULL DEFAULT 0 COMMENT 'معدل التفاعل الموزون % (بيتحسب في الكود)',
  `source` VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual · meta · import',
  `entered_by_admin` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_perf_content` (`content_id`),
  KEY `idx_ce_perf_user` (`user_id`, `published_at`),
  KEY `idx_ce_perf_campaign` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ce_learning_reports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period` CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  `scope` VARCHAR(10) NOT NULL DEFAULT 'global' COMMENT 'global · skill · user',
  `scope_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `n` INT UNSIGNED NOT NULL DEFAULT 0,
  `spearman` DECIMAL(5,3) NULL,
  `pearson` DECIMAL(5,3) NULL,
  `report_json` MEDIUMTEXT NULL COMMENT 'المعايير · الأشكال والزوايا · أحسن القطع · الأنماط',
  `applied_json` TEXT NULL COMMENT 'اللي اتطبق على ملف الهوية (لتقارير البراند)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_lr` (`period`, `scope`, `scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('ce_learning_min_n', '5'),          -- أقل عدد قطع بنتايج عشان نحسب العلاقة
('ce_learning_apply', '1'),          -- التقرير الشهري يحدّث ملف هوية البراند (أحسن 3 قطع + الأنماط)
('ce_learning_last_period', '');     -- آخر شهر اتعمله تقرير تلقائي

-- ملف الهوية بيتحدّث من التقرير الشهري ← مصدر جديد «learning»
ALTER TABLE `ce_brand_dna` MODIFY `built_by` ENUM('ai','rules','user','learning') NOT NULL DEFAULT 'rules';

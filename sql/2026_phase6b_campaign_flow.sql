-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة ⑥-ب: الحملة كاملة جوه الشاشة (إنتاج جماعي)
--   campaign_ideas: أفكار الحملة ← المنشور (contents) ← السكريبت ← التقييم ← التصميم ← موعد النشر
--   campaigns: تخطي التقييم + تفضيلات الجدولة
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `campaign_ideas` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id`   INT UNSIGNED NOT NULL,
  `user_id`       INT NOT NULL,
  `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `angle`         VARCHAR(60) NULL,
  `title`         VARCHAR(255) NOT NULL,
  `description`   TEXT NULL,
  `audience`      VARCHAR(255) NULL,
  `hook`          VARCHAR(500) NULL,
  `formats`       VARCHAR(255) NULL,
  `selected`      TINYINT(1) NOT NULL DEFAULT 0,
  `content_id`    INT NULL,
  `script_json`   TEXT NULL,
  `eval_score`    TINYINT UNSIGNED NULL,
  `eval_json`     TEXT NULL,
  `approved`      TINYINT(1) NOT NULL DEFAULT 0,
  `plan_at`       DATETIME NULL,
  `plan_platform` VARCHAR(20) NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ci_campaign` (`campaign_id`, `sort_order`),
  KEY `idx_ci_user` (`user_id`),
  KEY `idx_ci_content` (`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'campaigns' AND column_name = 'eval_skipped');
SET @sql := IF(@c = 0, 'ALTER TABLE `campaigns` ADD COLUMN `eval_skipped` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE p1 FROM @sql; EXECUTE p1; DEALLOCATE PREPARE p1;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'campaigns' AND column_name = 'prefs_json');
SET @sql := IF(@c = 0, 'ALTER TABLE `campaigns` ADD COLUMN `prefs_json` TEXT NULL', 'SELECT 1');
PREPARE p2 FROM @sql; EXECUTE p2; DEALLOCATE PREPARE p2;

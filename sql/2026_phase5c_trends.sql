-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة ⑤-ب: Trend Studio + إدارة الترندات
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `trends` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(12)  NOT NULL,                 -- T142
  `name`           VARCHAR(120) NOT NULL,
  `type`           VARCHAR(20)  NOT NULL DEFAULT 'Visual', -- Meme · Visual · Reel · Carousel
  `platforms`      TEXT NULL,                             -- JSON
  `industries`     TEXT NULL,                             -- JSON
  `description`    TEXT NULL,
  `adapt`          TEXT NULL,                             -- إزاي الـ AI يطبّقه على أي براند
  `structure`      TEXT NULL,                             -- JSON: خطوات الترند بالترتيب
  `visual`         TEXT NULL,                             -- JSON
  `tone`           TEXT NULL,                             -- JSON
  `allowed`        TEXT NULL,                             -- JSON: مسموح تغييره
  `locked`         TEXT NULL,                             -- JSON: ثابت لا يتغير
  `prompt_template` TEXT NULL,
  `ref_image_path` VARCHAR(255) NULL,
  `status`         ENUM('active','hidden') NOT NULL DEFAULT 'active',
  `start_date`     DATE NULL,
  `end_date`       DATE NULL,                             -- بعده = منتهي (مابيظهرش للعملاء)
  `sort_order`     INT NOT NULL DEFAULT 100,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `idx_status_dates` (`status`, `start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- التصميم بيفتكر الترند اللي اتعمل منه
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'studio_designs' AND column_name = 'trend_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `studio_designs` ADD COLUMN `trend_id` INT NULL', 'SELECT 1');
PREPARE t1 FROM @sql; EXECUTE t1; DEALLOCATE PREPARE t1;

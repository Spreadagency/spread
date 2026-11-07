-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — محرك المحتوى v3 · النشر والجدولة من المحرك
--   ce_publish_queue: كل قطعة × منصة (فيسبوك/إنستجرام) — دلوقتي أو في معاد
--   الـ worker بينشر في المعاد، وبعد النشر القطعة بتتربط ببوستها والأرقام بتتسحب من Meta Insights
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_ce_publish_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ce_publish_queue` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `campaign_id` INT UNSIGNED NOT NULL,
  `content_id` INT UNSIGNED NOT NULL,
  `connection_id` INT NOT NULL,
  `platform` VARCHAR(20) NOT NULL DEFAULT 'facebook' COMMENT 'facebook · instagram',
  `scheduled_at` DATETIME NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending · processing · published · failed · cancelled',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME NULL,
  `lock_token` VARCHAR(40) NULL,
  `provider_post_id` VARCHAR(120) NULL,
  `permalink` VARCHAR(500) NULL,
  `error` VARCHAR(900) NULL,
  `published_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_pub` (`content_id`, `platform`),
  KEY `idx_ce_pub_due` (`status`, `scheduled_at`),
  KEY `idx_ce_pub_campaign` (`campaign_id`),
  KEY `idx_ce_pub_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

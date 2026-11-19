-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — محرك المحتوى v3 · نتايج القطعة من أكتر من منصة
--   ce_perf_posts: صف لكل بوست مربوط بالقطعة (فيسبوك و/أو إنستجرام) بأرقامه لوحده
--   ce_performance بيفضل صف واحد للقطعة = مجموع أرقام بوستاتها (هو اللي بيدخل التقرير الشهري)
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_ce_perf_posts_rollback.sql
-- محتاج 2026_ce_meta_insights.sql قبله
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ce_perf_posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `content_id` INT UNSIGNED NOT NULL,
  `platform` VARCHAR(20) NOT NULL COMMENT 'facebook · instagram',
  `connection_id` INT NOT NULL,
  `provider_post_id` VARCHAR(120) NOT NULL,
  `permalink` VARCHAR(500) NULL,
  `link_mode` VARCHAR(10) NOT NULL DEFAULT 'manual' COMMENT 'manual · auto · publish',
  `published_at` DATE NULL,
  `reach` INT UNSIGNED NOT NULL DEFAULT 0,
  `impressions` INT UNSIGNED NOT NULL DEFAULT 0,
  `likes` INT UNSIGNED NOT NULL DEFAULT 0,
  `comments` INT UNSIGNED NOT NULL DEFAULT 0,
  `shares` INT UNSIGNED NOT NULL DEFAULT 0,
  `saves` INT UNSIGNED NOT NULL DEFAULT 0,
  `clicks` INT UNSIGNED NOT NULL DEFAULT 0,
  `synced_at` DATETIME NULL,
  `sync_status` VARCHAR(20) NULL COMMENT 'pending · ok · partial · error · expired',
  `sync_error` VARCHAR(300) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_pp_content_platform` (`content_id`, `platform`),
  UNIQUE KEY `uq_ce_pp_post` (`provider_post_id`),
  KEY `idx_ce_pp_user` (`user_id`),
  KEY `idx_ce_pp_sync` (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الربط القديم (بوست واحد للقطعة) ← صف في الجدول الجديد بأرقامه
INSERT IGNORE INTO `ce_perf_posts` (`user_id`, `content_id`, `platform`, `connection_id`, `provider_post_id`, `permalink`, `link_mode`, `published_at`,
  `reach`, `impressions`, `likes`, `comments`, `shares`, `saves`, `clicks`, `synced_at`, `sync_status`, `sync_error`)
SELECT `user_id`, `content_id`, IF(`platform` = 'instagram', 'instagram', 'facebook'), `connection_id`, `provider_post_id`, `permalink`, COALESCE(`link_mode`, 'manual'), `published_at`,
  `reach`, `impressions`, `likes`, `comments`, `shares`, `saves`, `clicks`, `synced_at`, `sync_status`, `sync_error`
FROM `ce_performance` WHERE `provider_post_id` IS NOT NULL AND `connection_id` IS NOT NULL;

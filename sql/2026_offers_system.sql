-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — نظام العروض والأفلييت
-- آمن للتشغيل المتكرر (idempotent)
-- ═══════════════════════════════════════════════════════════
SET NAMES utf8mb4;

-- ───────────────────────── العروض ─────────────────────────
CREATE TABLE IF NOT EXISTS `offers` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                 VARCHAR(40)  NOT NULL,
  `title`                VARCHAR(191) NOT NULL,
  `description`          TEXT NULL,
  `type`                 ENUM('referral','campaign','promo') NOT NULL DEFAULT 'campaign',
  `offer_group`          VARCHAR(50) NULL,
  `owner_user_id`        INT NULL,
  `referrer_credits`     INT NOT NULL DEFAULT 0,
  `referee_credits`      INT NOT NULL DEFAULT 0,
  `credit_validity_days` SMALLINT NULL,
  `trigger_event`        ENUM('on_register','on_activation','on_first_payment') NOT NULL DEFAULT 'on_activation',
  `priority`             TINYINT NOT NULL DEFAULT 0,
  `stackable`            TINYINT(1) NOT NULL DEFAULT 0,
  `rules_json`           TEXT NULL,
  `starts_at`            DATETIME NULL,
  `expires_at`           DATETIME NULL,
  `max_uses`             INT NULL,
  `used_count`           INT NOT NULL DEFAULT 0,
  `credits_spent`        INT NOT NULL DEFAULT 0,
  `is_active`            TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`           INT NULL,
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offer_code` (`code`),
  KEY `idx_owner` (`owner_user_id`),
  KEY `idx_type_active` (`type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────── وقائع الاستفادة ────────────────────
-- الـ UNIQUE indexes هنا هي خط الدفاع الحقيقي ضد التكرار
CREATE TABLE IF NOT EXISTS `offer_redemptions` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `offer_id`        INT UNSIGNED NOT NULL,
  `user_id`         INT NOT NULL,
  `identity_hash`   CHAR(64) NULL,
  `offer_group`     VARCHAR(50) NULL,
  `ip`              VARCHAR(45) NULL,
  `device_hash`     CHAR(64) NULL,
  `role`            ENUM('referrer','referee') NOT NULL DEFAULT 'referee',
  `credits_given`   INT NOT NULL DEFAULT 0,
  `status`          ENUM('granted','reversed') NOT NULL DEFAULT 'granted',
  `reversed_reason` VARCHAR(191) NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offer_user`     (`offer_id`, `user_id`, `role`),
  UNIQUE KEY `uq_offer_identity` (`offer_id`, `identity_hash`, `role`),
  KEY `idx_user` (`user_id`),
  KEY `idx_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────── ختم مجموعات العروض ───────────────
CREATE TABLE IF NOT EXISTS `user_offer_flags` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT NOT NULL,
  `identity_hash` CHAR(64) NULL,
  `offer_group`   VARCHAR(50) NOT NULL,
  `offer_id`      INT UNSIGNED NOT NULL,
  `first_used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_group`     (`user_id`, `offer_group`),
  UNIQUE KEY `uq_identity_group` (`identity_hash`, `offer_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────── الإحالات ──────────────────────
CREATE TABLE IF NOT EXISTS `referrals` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `offer_id`         INT UNSIGNED NOT NULL,
  `referrer_user_id` INT NOT NULL,
  `referee_user_id`  INT NOT NULL,
  `status`           ENUM('pending','qualified','rewarded','rejected') NOT NULL DEFAULT 'pending',
  `reject_reason`    VARCHAR(191) NULL,
  `signup_ip`        VARCHAR(45) NULL,
  `device_hash`      CHAR(64) NULL,
  `qualified_at`     DATETIME NULL,
  `rewarded_at`      DATETIME NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_referee` (`referee_user_id`),
  KEY `idx_referrer_status` (`referrer_user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ───────────── أعمدة إضافية على users ─────────────
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'referral_code');
SET @sql := IF(@c = 0, 'ALTER TABLE `users`
  ADD COLUMN `referral_code`     VARCHAR(20) NULL,
  ADD COLUMN `referred_by`       INT NULL,
  ADD COLUMN `referral_offer_id` INT UNSIGNED NULL,
  ADD COLUMN `identity_hash`     CHAR(64) NULL,
  ADD COLUMN `signup_ip`         VARCHAR(45) NULL,
  ADD COLUMN `has_purchased`     TINYINT(1) NOT NULL DEFAULT 0,
  ADD UNIQUE KEY `uq_referral_code` (`referral_code`),
  ADD KEY `idx_identity` (`identity_hash`)', 'SELECT 1');
PREPARE o1 FROM @sql; EXECUTE o1; DEALLOCATE PREPARE o1;

-- ───────────────── الإعدادات العامة ─────────────────
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('referral_enabled',            '1'),
('referral_default_reward',     '100'),
('referral_welcome_bonus',      '100'),
('referral_trigger',            'on_activation'),
('referral_cookie_days',        '30'),
('offer_default_validity_days', '30'),
('referral_max_per_user',       '20'),
('offers_allow_stacking',       '0'),
('referral_block_same_ip',      '1');

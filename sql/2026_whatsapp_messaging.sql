-- Spread AI — نظام الرسايل والإشعارات (واتساب عبر n8n → Evolution API + داخل الموقع)
-- إضافة بس — بيتعمل تلقائيًا أول استخدام (includes/Notifier.php → wa_ensure_schema)، والملف ده للتشغيل اليدوي لو حبيت.
-- «ADD COLUMN IF NOT EXISTS» محتاجة MariaDB (موجودة في cPanel). على MySQL 8 شيل «IF NOT EXISTS» لو العمود مش موجود.

ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `whatsapp_phone` VARCHAR(20) NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `whatsapp_verified` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `whatsapp_opt_in` TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `last_active_at` DATETIME NULL;

CREATE TABLE IF NOT EXISTS `user_notification_prefs` (
    `user_id`   INT NOT NULL,
    `event_key` VARCHAR(50) NOT NULL,
    `whatsapp`  TINYINT(1) NOT NULL DEFAULT 1,
    `inapp`     TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`user_id`, `event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_templates` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `event_key`       VARCHAR(50) NOT NULL,
    `title`           VARCHAR(150) NOT NULL,
    `body`            TEXT NOT NULL,
    `default_channel` ENUM('inapp','whatsapp','both') NOT NULL DEFAULT 'both',
    `is_critical`     TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `audience_json`   TEXT NULL,
    `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_mt_event` (`event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_broadcasts` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id`      INT NULL,
    `source`        VARCHAR(30) NOT NULL DEFAULT 'manual',
    `title`         VARCHAR(150) NOT NULL,
    `body`          TEXT NOT NULL,
    `media_url`     VARCHAR(500) NULL,
    `link`          VARCHAR(500) NULL,
    `channel`       ENUM('inapp','whatsapp','both') NOT NULL DEFAULT 'both',
    `audience_json` TEXT NOT NULL,
    `scheduled_at`  DATETIME NOT NULL,
    `status`        ENUM('draft','queued','sending','done','cancelled') NOT NULL DEFAULT 'queued',
    `total`         INT NOT NULL DEFAULT 0,
    `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_mb_status` (`status`, `scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
    `id`             BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT NOT NULL,
    `type`           ENUM('notification','direct','broadcast','otp') NOT NULL,
    `event_key`      VARCHAR(50) NULL,
    `broadcast_id`   INT NULL,
    `admin_id`       INT NULL,
    `notif_id`       INT NULL,
    `title`          VARCHAR(150) NOT NULL,
    `body`           TEXT NOT NULL,
    `media_url`      VARCHAR(500) NULL,
    `media_type`     ENUM('image','video','document') NULL,
    `link`           VARCHAR(500) NULL,
    `channel`        ENUM('inapp','whatsapp','both') NOT NULL,
    `read_at`        DATETIME NULL,
    `wa_phone`       VARCHAR(20) NULL,
    `wa_status`      ENUM('none','pending','processing','sent','failed','skipped') NOT NULL DEFAULT 'none',
    `wa_attempts`    TINYINT NOT NULL DEFAULT 0,
    `wa_error`       VARCHAR(500) NULL,
    `wa_provider_id` VARCHAR(100) NULL,
    `locked_at`      DATETIME NULL,
    `scheduled_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at`        DATETIME NULL,
    `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_user_inbox` (`user_id`, `channel`, `created_at`),
    KEY `idx_wa_queue` (`wa_status`, `scheduled_at`),
    KEY `idx_broadcast` (`broadcast_id`),
    KEY `idx_user_event` (`user_id`, `event_key`, `created_at`),
    KEY `idx_notif` (`notif_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wa_otps` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT NOT NULL,
    `phone`      VARCHAR(20) NOT NULL,
    `code_hash`  CHAR(64) NOT NULL,
    `attempts`   TINYINT NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `used_at`    DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_wo_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- القوالب والإعدادات الافتراضية بتتضاف تلقائيًا أول استخدام (wa_seed_templates / wa_default_settings)

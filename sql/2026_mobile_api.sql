-- Spread AI — تطبيق الموبايل (/api/v1): توكنات الأجهزة + منع الخصم المكرر
-- إضافة بس (جداول جديدة) — بتتعمل تلقائيًا أول طلب من التطبيق، والملف ده للتشغيل اليدوي لو حبيت

CREATE TABLE IF NOT EXISTS `mobile_tokens` (
    `id`           BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT NOT NULL,
    `token_hash`   CHAR(64) NOT NULL,
    `kind`         VARCHAR(12) NOT NULL DEFAULT 'access',
    `device_name`  VARCHAR(120) NULL,
    `platform`     VARCHAR(12) NULL,
    `app_version`  VARCHAR(20) NULL,
    `push_token`   VARCHAR(255) NULL,
    `target`       VARCHAR(300) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME NULL,
    `expires_at`   DATETIME NOT NULL,
    `revoked_at`   DATETIME NULL,
    UNIQUE KEY `uq_mt_hash` (`token_hash`),
    KEY `idx_mt_user` (`user_id`, `kind`, `revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mobile_idempotency` (
    `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT NOT NULL,
    `idem_key`    VARCHAR(80) NOT NULL,
    `endpoint`    VARCHAR(60) NOT NULL,
    `status`      VARCHAR(10) NOT NULL DEFAULT 'running',
    `http_code`   SMALLINT NULL,
    `response`    MEDIUMTEXT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at` DATETIME NULL,
    UNIQUE KEY `uq_mi_key` (`user_id`, `idem_key`),
    KEY `idx_mi_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

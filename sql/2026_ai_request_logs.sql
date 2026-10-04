-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — سجل استدعاءات الذكاء الاصطناعي
-- بيسجّل اللي اتبعت للـ API بالظبط: البرومبت · الصور · الرد
-- ═══════════════════════════════════════════════════════════
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_request_logs` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`            VARCHAR(30) NOT NULL,            -- content | ideas | design | studio | logo | brand | visual_identity
  `user_id`         INT NULL,
  `reference_type`  VARCHAR(40) NULL,                -- contents | content_designs | studio_designs ...
  `reference_id`    INT NULL,
  `route`           VARCHAR(20) NOT NULL DEFAULT 'legacy',  -- legacy | smart
  `provider`        VARCHAR(60) NULL,
  `model`           VARCHAR(120) NULL,
  `endpoint`        VARCHAR(255) NULL,
  `prompt`          MEDIUMTEXT NULL,                 -- البرومبت الكامل زي ما اتبعت
  `prompt_chars`    INT NOT NULL DEFAULT 0,
  `images_sent`     TINYINT NOT NULL DEFAULT 0,
  `images_meta`     TEXT NULL,                       -- JSON: [{role,mime,bytes,thumb}]
  `options_json`    TEXT NULL,                       -- المقاس · النوع · اللهجة ...
  `status`          ENUM('ok','failed') NOT NULL DEFAULT 'ok',
  `http_code`       SMALLINT NULL,
  `error`           VARCHAR(500) NULL,
  `response_excerpt` TEXT NULL,
  `tokens_in`       INT NOT NULL DEFAULT 0,
  `tokens_out`      INT NOT NULL DEFAULT 0,
  `credits_used`    INT NOT NULL DEFAULT 0,
  `duration_ms`     INT NOT NULL DEFAULT 0,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kind_date` (`kind`, `created_at`),
  KEY `idx_user` (`user_id`),
  KEY `idx_ref` (`reference_type`, `reference_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('ai_logging_enabled',  '1'),
('ai_log_keep_days',    '30'),
('ai_log_store_thumbs', '1');

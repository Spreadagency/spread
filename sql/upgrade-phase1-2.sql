-- =========================================================
-- Spread AI v2 — Upgrade: Phase 1 (Multi-Provider AI) + Phase 2 (Brand Sources)
-- Run AFTER schema.sql + features-upgrade.sql
-- =========================================================

-- ---------------------------------------------------------
-- PHASE 1: AI PROVIDERS / MODELS / SMART ROUTING
-- ---------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ai_providers` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `provider_name`     VARCHAR(40) NOT NULL UNIQUE,      -- openrouter / openai / gemini
    `api_base_url`      VARCHAR(255) NOT NULL,
    `api_key_encrypted` TEXT NULL,                        -- AES-256-GCM (includes/crypto.php)
    `status`            ENUM('active','disabled') NOT NULL DEFAULT 'disabled',
    `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
    `priority`          INT NOT NULL DEFAULT 100,
    `supports_text`     TINYINT(1) NOT NULL DEFAULT 1,
    `supports_image`    TINYINT(1) NOT NULL DEFAULT 0,
    `supports_json`     TINYINT(1) NOT NULL DEFAULT 1,
    `supports_vision`   TINYINT(1) NOT NULL DEFAULT 0,
    `health_status`     ENUM('healthy','degraded','down','unknown') NOT NULL DEFAULT 'unknown',
    `last_health_check` DATETIME NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_models` (
    `id`                 INT AUTO_INCREMENT PRIMARY KEY,
    `provider_id`        INT NOT NULL,
    `model_name`         VARCHAR(120) NOT NULL,           -- openai/gpt-4o-mini, gemini-2.0-flash ...
    `display_name`       VARCHAR(120) NULL,
    `model_type`         ENUM('text','image','vision') NOT NULL DEFAULT 'text',
    `is_default`         TINYINT(1) NOT NULL DEFAULT 0,
    `priority_order`     INT NOT NULL DEFAULT 100,
    `input_cost_per_1k`  DECIMAL(10,5) NULL,
    `output_cost_per_1k` DECIMAL(10,5) NULL,
    `supports_json`      TINYINT(1) NOT NULL DEFAULT 1,
    `max_tokens`         INT NULL,
    `status`             ENUM('active','disabled') NOT NULL DEFAULT 'active',
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_aim_provider` FOREIGN KEY (`provider_id`) REFERENCES `ai_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `smart_routing_rules` (
    `id`                 INT AUTO_INCREMENT PRIMARY KEY,
    `task_code`          VARCHAR(60) NOT NULL UNIQUE,     -- content_generation / design_prompt / image_generation / source_summary / plan_ideas
    `preferred_model_id` INT NOT NULL,
    `fallback_chain`     TEXT NULL,                       -- JSON array of model ids e.g. [2,5]
    `is_active`          TINYINT(1) NOT NULL DEFAULT 1,
    `notes`              VARCHAR(255) NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_srr_model` FOREIGN KEY (`preferred_model_id`) REFERENCES `ai_models` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_job_logs` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `job_type`        VARCHAR(40) NOT NULL,               -- content_gen / design_gen / source_summary
    `user_id`         INT NULL,
    `reference_type`  VARCHAR(40) NULL,                   -- content / brand_source ...
    `reference_id`    INT NULL,
    `provider`        VARCHAR(40) NULL,
    `model`           VARCHAR(120) NULL,
    `task_code`       VARCHAR(60) NULL,
    `request_payload` LONGTEXT NULL,
    `tokens_in`       INT NULL,
    `tokens_out`      INT NULL,
    `cost_usd`        DECIMAL(10,5) NULL,
    `duration_ms`     INT NULL,
    `status`          ENUM('success','failed','timeout','invalid_json') NOT NULL,
    `error_message`   TEXT NULL,
    `retry_count`     INT NOT NULL DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_job` (`job_type`, `status`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_failover_logs` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `job_log_id`        INT NULL,
    `failed_provider`   VARCHAR(40) NOT NULL,
    `failed_model`      VARCHAR(120) NOT NULL,
    `failure_reason`    TEXT NULL,
    `fallback_provider` VARCHAR(40) NULL,
    `fallback_model`    VARCHAR(120) NULL,
    `outcome`           ENUM('recovered','final_failure') NOT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed providers (keys added later from admin panel)
INSERT INTO `ai_providers` (`provider_name`, `api_base_url`, `status`, `is_default`, `priority`, `supports_image`, `supports_vision`)
VALUES
('openrouter', 'https://openrouter.ai/api/v1',                          'disabled', 1, 1, 1, 1),
('openai',     'https://api.openai.com/v1',                             'disabled', 0, 2, 1, 1),
('gemini',     'https://generativelanguage.googleapis.com/v1beta',      'disabled', 0, 3, 0, 1)
ON DUPLICATE KEY UPDATE `api_base_url` = VALUES(`api_base_url`);

-- Seed default models (idempotent — آمن لإعادة التشغيل)
INSERT INTO `ai_models` (`provider_id`, `model_name`, `display_name`, `model_type`, `is_default`, `priority_order`, `max_tokens`)
SELECT p.id, 'openai/gpt-4o-mini', 'GPT-4o Mini (OpenRouter)', 'text', 1, 1, 4000 FROM `ai_providers` p
WHERE p.provider_name = 'openrouter' AND NOT EXISTS (SELECT 1 FROM `ai_models` m WHERE m.provider_id = p.id AND m.model_name = 'openai/gpt-4o-mini');
INSERT INTO `ai_models` (`provider_id`, `model_name`, `display_name`, `model_type`, `priority_order`, `max_tokens`)
SELECT p.id, 'anthropic/claude-sonnet-4.5', 'Claude Sonnet (OpenRouter)', 'text', 2, 8000 FROM `ai_providers` p
WHERE p.provider_name = 'openrouter' AND NOT EXISTS (SELECT 1 FROM `ai_models` m WHERE m.provider_id = p.id AND m.model_name = 'anthropic/claude-sonnet-4.5');
INSERT INTO `ai_models` (`provider_id`, `model_name`, `display_name`, `model_type`, `priority_order`, `max_tokens`)
SELECT p.id, 'google/gemini-2.0-flash-001', 'Gemini Flash (OpenRouter)', 'text', 3, 8000 FROM `ai_providers` p
WHERE p.provider_name = 'openrouter' AND NOT EXISTS (SELECT 1 FROM `ai_models` m WHERE m.provider_id = p.id AND m.model_name = 'google/gemini-2.0-flash-001');
INSERT INTO `ai_models` (`provider_id`, `model_name`, `display_name`, `model_type`, `priority_order`)
SELECT p.id, 'openai/gpt-image-1', 'GPT Image (OpenRouter)', 'image', 1 FROM `ai_providers` p
WHERE p.provider_name = 'openrouter' AND NOT EXISTS (SELECT 1 FROM `ai_models` m WHERE m.provider_id = p.id AND m.model_name = 'openai/gpt-image-1');
INSERT INTO `ai_models` (`provider_id`, `model_name`, `display_name`, `model_type`, `priority_order`, `max_tokens`)
SELECT p.id, 'gpt-4o-mini', 'GPT-4o Mini (OpenAI)', 'text', 10, 4000 FROM `ai_providers` p
WHERE p.provider_name = 'openai' AND NOT EXISTS (SELECT 1 FROM `ai_models` m WHERE m.provider_id = p.id AND m.model_name = 'gpt-4o-mini');
INSERT INTO `ai_models` (`provider_id`, `model_name`, `display_name`, `model_type`, `priority_order`)
SELECT p.id, 'gpt-image-1', 'GPT Image (OpenAI)', 'image', 10 FROM `ai_providers` p
WHERE p.provider_name = 'openai' AND NOT EXISTS (SELECT 1 FROM `ai_models` m WHERE m.provider_id = p.id AND m.model_name = 'gpt-image-1');
INSERT INTO `ai_models` (`provider_id`, `model_name`, `display_name`, `model_type`, `priority_order`, `max_tokens`)
SELECT p.id, 'gemini-2.0-flash', 'Gemini 2.0 Flash', 'text', 20, 8000 FROM `ai_providers` p
WHERE p.provider_name = 'gemini' AND NOT EXISTS (SELECT 1 FROM `ai_models` m WHERE m.provider_id = p.id AND m.model_name = 'gemini-2.0-flash');

-- Seed routing rules (idempotent)
INSERT INTO `smart_routing_rules` (`task_code`, `preferred_model_id`, `notes`)
SELECT 'content_generation', m.id, 'توليد المنشورات' FROM `ai_models` m
WHERE m.is_default = 1 AND NOT EXISTS (SELECT 1 FROM `smart_routing_rules` r WHERE r.task_code = 'content_generation') LIMIT 1;
INSERT INTO `smart_routing_rules` (`task_code`, `preferred_model_id`, `notes`)
SELECT 'source_summary', m.id, 'تلخيص مستندات الهوية' FROM `ai_models` m
WHERE m.is_default = 1 AND NOT EXISTS (SELECT 1 FROM `smart_routing_rules` r WHERE r.task_code = 'source_summary') LIMIT 1;
INSERT INTO `smart_routing_rules` (`task_code`, `preferred_model_id`, `notes`)
SELECT 'image_generation', m.id, 'توليد التصميمات' FROM `ai_models` m
JOIN `ai_providers` p ON p.id = m.provider_id
WHERE m.model_type = 'image' AND NOT EXISTS (SELECT 1 FROM `smart_routing_rules` r WHERE r.task_code = 'image_generation')
ORDER BY p.priority, m.priority_order LIMIT 1;

-- Settings
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('use_smart_router', '0');

-- ---------------------------------------------------------
-- PHASE 2: BRAND SOURCES (مستندات الهوية والبيزنس)
-- ---------------------------------------------------------

CREATE TABLE IF NOT EXISTS `brand_sources` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `brand_profile_id` INT NOT NULL,
    `user_id`          INT NOT NULL,
    `type`             ENUM('pdf','docx','txt','link','text') NOT NULL,
    `title`            VARCHAR(180) NULL,
    `file_path`        VARCHAR(255) NULL,
    `source_url`       VARCHAR(500) NULL,
    `raw_text`         LONGTEXT NULL,                     -- النص المستخرج
    `summary`          TEXT NULL,                         -- ملخص AI مضغوط يُستخدم في البرومبت
    `extract_status`   ENUM('pending','extracted','summarized','failed') NOT NULL DEFAULT 'pending',
    `extract_error`    VARCHAR(255) NULL,
    `use_in_prompts`   TINYINT(1) NOT NULL DEFAULT 1,
    `extracted_at`     DATETIME NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_brand` (`brand_profile_id`),
    INDEX `idx_user` (`user_id`),
    CONSTRAINT `fk_bs_brand` FOREIGN KEY (`brand_profile_id`) REFERENCES `brand_profiles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bs_user`  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('knowledge_max_chars', '4000');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('source_summary_cost', '1');

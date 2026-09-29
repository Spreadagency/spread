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
-- =========================================================
-- Spread AI v2 — Upgrade: Phase 3 (Content Plans) + Phase 4 (Studio)
-- Run AFTER upgrade-phase1-2.sql
-- =========================================================

-- ---------------------------------------------------------
-- PHASE 3: CONTENT PLANS (خطة المحتوى)
-- ---------------------------------------------------------

CREATE TABLE IF NOT EXISTS `content_plans` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`          INT NOT NULL,
    `brand_profile_id` INT NOT NULL,
    `title`            VARCHAR(180) NOT NULL,
    `goal`             VARCHAR(50) NULL,                 -- awareness / sales / engagement / trust / mixed
    `start_date`       DATE NULL,                        -- بداية الجدولة
    `notes`            TEXT NULL,                        -- توجيهات العميل للأفكار
    `ideas_count`      INT NOT NULL DEFAULT 20,          -- عدد الأفكار المطلوبة
    `status`           ENUM('draft','ideas_ready','producing','done') NOT NULL DEFAULT 'draft',
    `credits_used`     INT NOT NULL DEFAULT 0,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    CONSTRAINT `fk_cp_user`  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cp_brand` FOREIGN KEY (`brand_profile_id`) REFERENCES `brand_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_ideas` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `plan_id`        INT NOT NULL,
    `user_id`        INT NOT NULL,
    `title`          VARCHAR(255) NOT NULL,
    `angle`          VARCHAR(30) NOT NULL DEFAULT 'awareness',  -- storytelling/promotional/awareness/educational/engaging/offer/trend
    `description`    TEXT NULL,                                 -- وصف الفكرة (سطرين)
    `status`         ENUM('suggested','selected','rejected','produced') NOT NULL DEFAULT 'suggested',
    `content_id`     INT NULL,                                  -- البوست المنتَج
    `scheduled_date` DATE NULL,                                 -- تاريخ النشر في الخطة
    `sort_order`     INT NOT NULL DEFAULT 0,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_plan` (`plan_id`, `status`),
    INDEX `idx_date` (`scheduled_date`),
    CONSTRAINT `fk_pi_plan` FOREIGN KEY (`plan_id`) REFERENCES `content_plans` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pi_content` FOREIGN KEY (`content_id`) REFERENCES `contents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- أعمدة جديدة على contents: ربط بالخطة + فكرة التصميم من الـ AI
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'plan_idea_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents`
    ADD COLUMN `plan_idea_id` INT NULL,
    ADD COLUMN `design_direction` TEXT NULL,
    ADD COLUMN `image_prompt` TEXT NULL,
    ADD INDEX `idx_plan_idea` (`plan_idea_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------
-- PHASE 4: STUDIO (مكتبة التصميمات)
-- ---------------------------------------------------------

CREATE TABLE IF NOT EXISTS `media_library` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `title`       VARCHAR(180) NOT NULL,
    `category`    VARCHAR(80) NOT NULL DEFAULT 'general',   -- posts / stories / offers / medical ...
    `style_tags`  VARCHAR(255) NULL,                        -- "minimal,luxury,bold" — بتدخل في برومبت التصميم
    `image_path`  VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`  INT NOT NULL DEFAULT 0,
    `uploaded_by` INT NULL,                                 -- admin id
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_active` (`is_active`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_media_selections` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT NOT NULL,
    `media_id`   INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_media` (`user_id`, `media_id`),
    CONSTRAINT `fk_ums_user`  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ums_media` FOREIGN KEY (`media_id`) REFERENCES `media_library` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Settings + Routing seeds
-- ---------------------------------------------------------

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('plan_ideas_cost', '2');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('plan_max_ideas', '30');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('studio_max_selections', '10');

-- قواعد توجيه المرحلة 3 (idempotent)
INSERT INTO `smart_routing_rules` (`task_code`, `preferred_model_id`, `notes`)
SELECT 'plan_ideas', m.id, 'توليد أفكار خطة المحتوى' FROM `ai_models` m
WHERE m.is_default = 1 AND NOT EXISTS (SELECT 1 FROM `smart_routing_rules` r WHERE r.task_code = 'plan_ideas') LIMIT 1;
INSERT INTO `smart_routing_rules` (`task_code`, `preferred_model_id`, `notes`)
SELECT 'plan_produce', m.id, 'إنتاج بوستات الخطة (محتوى + فكرة تصميم)' FROM `ai_models` m
WHERE m.is_default = 1 AND NOT EXISTS (SELECT 1 FROM `smart_routing_rules` r WHERE r.task_code = 'plan_produce') LIMIT 1;
-- =========================================================
-- Spread AI v2 — Upgrade: Phase 5 (Watermark) + Phase 6 (Admin Control)
-- Run AFTER upgrade-phase3-4.sql
-- =========================================================

-- ---------------------------------------------------------
-- PHASE 6: الموافقة اليدوية على العملاء
-- ---------------------------------------------------------

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'approval_status');
SET @sql := IF(@c = 0, 'ALTER TABLE `users`
    ADD COLUMN `approval_status` ENUM(''pending'',''approved'',''rejected'') NOT NULL DEFAULT ''approved'',
    ADD COLUMN `approved_at` DATETIME NULL,
    ADD INDEX `idx_approval` (`approval_status`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- كل الحسابات الحالية تفضل approved (الـ DEFAULT بيغطي ده)

-- ---------------------------------------------------------
-- Settings: Phase 5 (العلامة المائية) + Phase 6
-- ---------------------------------------------------------

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('watermark_enabled', '0');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('watermark_logo', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('watermark_size', '12');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('watermark_opacity', '40');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('watermark_position', 'bottom-right');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('watermark_padding', '3');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('watermark_keep_original', '1');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('manual_approval', '0');

-- ---------------------------------------------------------
-- صلاحية الكريدت (شهر) + استرجاع خلال 3 أيام
-- ---------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'credit_wallets' AND column_name = 'expires_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `credit_wallets`
    ADD COLUMN `expires_at` DATETIME NULL,
    ADD COLUMN `expired_balance` INT NOT NULL DEFAULT 0,
    ADD COLUMN `expired_at` DATETIME NULL', 'SELECT 1');
PREPARE s2 FROM @sql; EXECUTE s2; DEALLOCATE PREPARE s2;

-- ---------------------------------------------------------
-- الدفع اليدوي (انستاباي / فودافون كاش / واتساب)
-- ---------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('manual_pay_enabled', '1');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('pay_instapay', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('pay_vodafone', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('pay_whatsapp', '');

-- ---------------------------------------------------------
-- الهوية الذكية: ملخص AI + شروط التصميم على brand_profiles
-- ---------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'brand_profiles' AND column_name = 'ai_summary');
SET @sql := IF(@c = 0, 'ALTER TABLE `brand_profiles`
    ADD COLUMN `ai_summary` TEXT NULL,
    ADD COLUMN `design_rules` TEXT NULL', 'SELECT 1');
PREPARE s3 FROM @sql; EXECUTE s3; DEALLOCATE PREPARE s3;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('brand_summary_cost', '2');

-- ---------------------------------------------------------
-- رقم الموبايل + استوديو التصميم المستقل + قوالب برومبت التصميم
-- ---------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'phone');
SET @sql := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(30) NULL AFTER `email`', 'SELECT 1');
PREPARE s4 FROM @sql; EXECUTE s4; DEALLOCATE PREPARE s4;

CREATE TABLE IF NOT EXISTS `design_templates` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `title`          VARCHAR(180) NOT NULL,
    `prompt_snippet` TEXT NOT NULL,             -- بيتضاف على برومبت التصميم
    `mode`           VARCHAR(30) NOT NULL DEFAULT 'any',  -- any/from_content/before_after/free/personal
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`     INT NOT NULL DEFAULT 0,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `design_templates` (`title`, `prompt_snippet`, `mode`, `sort_order`)
SELECT * FROM (SELECT 'ستايل فاخر (Luxury)' t, 'Luxury premium style: elegant serif typography, gold accents, deep rich colors, sophisticated minimal layout.' p, 'any' m, 1 o) x
WHERE NOT EXISTS (SELECT 1 FROM design_templates WHERE title = 'ستايل فاخر (Luxury)');
INSERT INTO `design_templates` (`title`, `prompt_snippet`, `mode`, `sort_order`)
SELECT * FROM (SELECT 'ستايل طبي نظيف' t, 'Clean medical style: white and soft blue palette, plenty of whitespace, trustworthy professional look, subtle icons.' p, 'any' m, 2 o) x
WHERE NOT EXISTS (SELECT 1 FROM design_templates WHERE title = 'ستايل طبي نظيف');
INSERT INTO `design_templates` (`title`, `prompt_snippet`, `mode`, `sort_order`)
SELECT * FROM (SELECT 'قبل / بعد احترافي' t, 'Professional before/after comparison layout: split screen with clear labels (قبل / بعد), consistent lighting, arrow or divider between the two states.' p, 'before_after' m, 3 o) x
WHERE NOT EXISTS (SELECT 1 FROM design_templates WHERE title = 'قبل / بعد احترافي');
INSERT INTO `design_templates` (`title`, `prompt_snippet`, `mode`, `sort_order`)
SELECT * FROM (SELECT 'بورتريه شخصي سينمائي' t, 'Cinematic personal portrait concept: dramatic professional lighting, shallow depth of field, modern personal-brand aesthetic.' p, 'personal' m, 4 o) x
WHERE NOT EXISTS (SELECT 1 FROM design_templates WHERE title = 'بورتريه شخصي سينمائي');

CREATE TABLE IF NOT EXISTS `studio_designs` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT NOT NULL,
    `mode`         VARCHAR(30) NOT NULL DEFAULT 'free',   -- from_content/before_after/free/personal
    `content_text` TEXT NULL,
    `design_idea`  TEXT NULL,
    `prompt`       TEXT NULL,
    `image_path`   VARCHAR(255) NOT NULL,
    `model`        VARCHAR(120) NULL,
    `template_id`  INT NULL,
    `credits_used` INT NOT NULL DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_model` (`model`),
    CONSTRAINT `fk_sd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('studio_idea_cost', '1');

-- ---------------------------------------------------------
-- الإعلانات + ايجنت الهوية + مظهر الموقع
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `title`      VARCHAR(180) NOT NULL,
    `body`       TEXT NULL,
    `image_path` VARCHAR(255) NULL,
    `link_url`   VARCHAR(500) NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `starts_at`  DATE NULL,
    `ends_at`    DATE NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `agent_sessions` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT NOT NULL,
    `messages`   MEDIUMTEXT NULL,                -- JSON [{role, content}]
    `status`     ENUM('active','done') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`, `status`),
    CONSTRAINT `fk_ag_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('site_logo', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('site_tagline', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('theme_primary', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('theme_primary_2', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('theme_primary_ink', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('header_message', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('footer_text', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('whatsapp_float', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('whatsapp_float_msg', 'أهلًا، عندي استفسار عن المنصة');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('brand_agent_cost', '0');

-- ---------------------------------------------------------
-- لوجو بالـ AI + لينك انستاباي المباشر + عمود ملخص المستندات
-- ---------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('logo_generate_cost', '3');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('pay_instapay_link', '');

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'brand_sources' AND column_name = 'summary');
SET @sql := IF(@c = 0, 'ALTER TABLE `brand_sources` ADD COLUMN `summary` MEDIUMTEXT NULL', 'SELECT 1');
PREPARE s5 FROM @sql; EXECUTE s5; DEALLOCATE PREPARE s5;

-- ---------------------------------------------------------
-- وحدة النشر الاجتماعي: اتصالات + صلاحيات + سجلات
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `social_connections` (
    `id`               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`          INT NOT NULL,
    `platform`         ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook',
    `provider_page_id` VARCHAR(64) NOT NULL,
    `page_name`        VARCHAR(255) NOT NULL,
    `page_avatar_url`  VARCHAR(512) NULL,
    `ig_user_id`       VARCHAR(64) NULL,          -- حساب انستجرام بيزنس المربوط بالصفحة (لو موجود)
    `ig_username`      VARCHAR(128) NULL,
    `access_token_enc` TEXT NOT NULL,
    `token_type`       ENUM('page','user') NOT NULL DEFAULT 'page',
    `scopes`           TEXT NULL,
    `expires_at`       DATETIME NULL,
    `status`           ENUM('active','expired','revoked','error') NOT NULL DEFAULT 'active',
    `last_error`       TEXT NULL,
    `last_verified_at` DATETIME NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_user_page` (`user_id`, `platform`, `provider_page_id`),
    INDEX `idx_user_status` (`user_id`, `status`),
    CONSTRAINT `fk_sc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- أعمدة النشر على contents (idempotent)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'connection_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents`
    ADD COLUMN `connection_id` BIGINT UNSIGNED NULL,
    ADD COLUMN `publish_status` ENUM(''draft'',''pending'',''processing'',''published'',''failed'',''cancelled'') NOT NULL DEFAULT ''draft'',
    ADD COLUMN `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN `next_attempt_at` DATETIME NULL,
    ADD COLUMN `lock_token` CHAR(36) NULL,
    ADD INDEX `idx_worker_pick` (`publish_status`, `scheduled_at`, `next_attempt_at`)', 'SELECT 1');
PREPARE s6 FROM @sql; EXECUTE s6; DEALLOCATE PREPARE s6;

CREATE TABLE IF NOT EXISTS `feature_flags` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `feature_key`  VARCHAR(64) NOT NULL UNIQUE,
    `label_ar`     VARCHAR(128) NOT NULL,
    `default_mode` ENUM('off','on','allowlist') NOT NULL DEFAULT 'off',
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_feature_access` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT NOT NULL,
    `feature_key` VARCHAR(64) NOT NULL,
    `is_enabled`  TINYINT(1) NOT NULL DEFAULT 1,
    `max_pages`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `granted_by`  INT NULL,
    `granted_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`  DATETIME NULL,
    UNIQUE KEY `uniq_user_feature` (`user_id`, `feature_key`),
    CONSTRAINT `fk_ufa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `feature_flags` (`feature_key`, `label_ar`, `default_mode`)
SELECT 'social_publishing', 'النشر التلقائي على السوشيال', 'allowlist'
WHERE NOT EXISTS (SELECT 1 FROM feature_flags WHERE feature_key = 'social_publishing');

CREATE TABLE IF NOT EXISTS `publish_logs` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `post_id`       INT NULL,
    `connection_id` BIGINT UNSIGNED NULL,
    `action`        VARCHAR(64) NOT NULL,
    `http_status`   SMALLINT NULL,
    `request_json`  TEXT NULL,
    `response_json` TEXT NULL,
    `duration_ms`   INT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_post` (`post_id`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('meta_app_id', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('meta_app_secret_enc', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('meta_webhook_verify_token', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('graph_base_url', 'https://graph.facebook.com/v21.0');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('fb_oauth_base_url', 'https://www.facebook.com/v21.0');

-- ---------------------------------------------------------
-- مقاسات التصميمات + هوية التصميم البصرية + لينكات معرض الإلهام
-- ---------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'content_designs' AND column_name = 'ratio');
SET @sql := IF(@c = 0, 'ALTER TABLE `content_designs` ADD COLUMN `ratio` VARCHAR(10) NOT NULL DEFAULT ''1:1''', 'SELECT 1');
PREPARE s7 FROM @sql; EXECUTE s7; DEALLOCATE PREPARE s7;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'studio_designs' AND column_name = 'ratio');
SET @sql := IF(@c = 0, 'ALTER TABLE `studio_designs` ADD COLUMN `ratio` VARCHAR(10) NOT NULL DEFAULT ''1:1''', 'SELECT 1');
PREPARE s8 FROM @sql; EXECUTE s8; DEALLOCATE PREPARE s8;

-- هوية التصميم البصرية (ملخص AI لستايل الصور)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'brand_profiles' AND column_name = 'visual_identity');
SET @sql := IF(@c = 0, 'ALTER TABLE `brand_profiles` ADD COLUMN `visual_identity` MEDIUMTEXT NULL, ADD COLUMN `visual_identity_at` DATETIME NULL', 'SELECT 1');
PREPARE s9 FROM @sql; EXECUTE s9; DEALLOCATE PREPARE s9;

-- معرض الإلهام: دعم اللينكات الخارجية
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'media_library' AND column_name = 'image_url');
SET @sql := IF(@c = 0, 'ALTER TABLE `media_library` ADD COLUMN `image_url` VARCHAR(1000) NULL AFTER `image_path`', 'SELECT 1');
PREPARE s10 FROM @sql; EXECUTE s10; DEALLOCATE PREPARE s10;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'media_library' AND column_name = 'image_path' AND is_nullable = 'NO');
SET @sql := IF(@c = 1, 'ALTER TABLE `media_library` MODIFY COLUMN `image_path` VARCHAR(255) NULL', 'SELECT 1');
PREPARE s11 FROM @sql; EXECUTE s11; DEALLOCATE PREPARE s11;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('visual_identity_cost', '2');

-- ---------------------------------------------------------
-- توسيع عمود رابط صورة الصفحة (روابط فيسبوك بتتجاوز 512 حرف)
-- ---------------------------------------------------------
SET @len := (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'social_connections' AND column_name = 'page_avatar_url');
SET @sql := IF(@len IS NOT NULL AND @len < 1000,
    'ALTER TABLE `social_connections` MODIFY COLUMN `page_avatar_url` VARCHAR(1000) NULL', 'SELECT 1');
PREPARE s12 FROM @sql; EXECUTE s12; DEALLOCATE PREPARE s12;

-- ---------------------------------------------------------
-- الجدولة على فيسبوك نفسه + وقت النشر الافتراضي
-- ---------------------------------------------------------
SET @e := (SELECT COLUMN_TYPE FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'contents' AND column_name = 'publish_status');
SET @sql := IF(@e IS NOT NULL AND LOCATE('scheduled', @e) = 0,
  "ALTER TABLE `contents` MODIFY COLUMN `publish_status` ENUM('draft','pending','processing','scheduled','published','failed','cancelled') NOT NULL DEFAULT 'draft'",
  'SELECT 1');
PREPARE s13 FROM @sql; EXECUTE s13; DEALLOCATE PREPARE s13;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('default_publish_hour', '21');

-- ---------------------------------------------------------
-- صور البراند بلينك خارجي (بدون رفع)
-- ---------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'brand_images' AND column_name = 'image_url');
SET @sql := IF(@c = 0, 'ALTER TABLE `brand_images` ADD COLUMN `image_url` VARCHAR(1000) NULL AFTER `image_path`', 'SELECT 1');
PREPARE s14 FROM @sql; EXECUTE s14; DEALLOCATE PREPARE s14;

SET @n := (SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'brand_images' AND column_name = 'image_path');
SET @sql := IF(@n = 'NO', 'ALTER TABLE `brand_images` MODIFY COLUMN `image_path` VARCHAR(255) NULL', 'SELECT 1');
PREPARE s15 FROM @sql; EXECUTE s15; DEALLOCATE PREPARE s15;

-- ---------------------------------------------------------
-- أدوار الأدمن + سجل الدخول بحساب العميل
-- ---------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'role');
SET @sql := IF(@c = 0,
  "ALTER TABLE `admin_users` ADD COLUMN `role` ENUM('super','manager','assistant') NOT NULL DEFAULT 'super' AFTER `password`",
  'SELECT 1');
PREPARE s16 FROM @sql; EXECUTE s16; DEALLOCATE PREPARE s16;

CREATE TABLE IF NOT EXISTS `impersonation_logs` (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `admin_id`   INT NOT NULL,
    `user_id`    INT NOT NULL,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at`   DATETIME NULL,
    `ip`         VARCHAR(45) NULL,
    INDEX `idx_admin` (`admin_id`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- الباقات المفصّلة + التحكم في القائمة + برومبتات الأدمن
-- ---------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'credit_packages' AND column_name = 'features');
SET @sql := IF(@c = 0, 'ALTER TABLE `credit_packages`
    ADD COLUMN `features` TEXT NULL,
    ADD COLUMN `validity_days` INT NOT NULL DEFAULT 30,
    ADD COLUMN `description` VARCHAR(500) NULL,
    ADD COLUMN `badge` VARCHAR(60) NULL,
    ADD COLUMN `is_featured` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s17 FROM @sql; EXECUTE s17; DEALLOCATE PREPARE s17;

-- تكاليف كل العمليات (قابلة للتعديل من الأدمن)
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('content_generation_cost', '1');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('content_regeneration_cost', '1');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('content_design_cost', '2');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('logo_generate_cost', '3');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('plan_ideas_cost', '2');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('studio_idea_cost', '1');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('source_summary_cost', '1');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('brand_summary_cost', '2');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('visual_identity_cost', '2');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('brand_agent_cost', '0');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('default_credit_validity_days', '30');

-- التحكم في عناصر القائمة الرئيسية للعميل
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('menu_hidden', '');

-- برومبت التصميم الأساسي (قابل للتعديل)
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('design_base_prompt', '');

-- ---------------------------------------------------------
-- الهوية: الخدمات وبيانات التواصل والسوشيال
-- ---------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'brand_profiles' AND column_name = 'services');
SET @sql := IF(@c = 0, 'ALTER TABLE `brand_profiles`
    ADD COLUMN `services` TEXT NULL,
    ADD COLUMN `address` VARCHAR(500) NULL,
    ADD COLUMN `phones` VARCHAR(300) NULL,
    ADD COLUMN `whatsapp` VARCHAR(50) NULL,
    ADD COLUMN `website` VARCHAR(300) NULL,
    ADD COLUMN `social_facebook` VARCHAR(300) NULL,
    ADD COLUMN `social_instagram` VARCHAR(300) NULL,
    ADD COLUMN `social_tiktok` VARCHAR(300) NULL,
    ADD COLUMN `social_linkedin` VARCHAR(300) NULL,
    ADD COLUMN `working_hours` VARCHAR(300) NULL', 'SELECT 1');
PREPARE s18 FROM @sql; EXECUTE s18; DEALLOCATE PREPARE s18;

-- ---------------------------------------------------------
-- برومبت مخصص لكل نوع محتوى/تصميم + تسعير الأفكار بالشرائح
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `type_prompts` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `category`    ENUM('content','design','plan') NOT NULL DEFAULT 'content',
    `type_key`    VARCHAR(60) NOT NULL,
    `label_ar`    VARCHAR(120) NOT NULL,
    `prompt_text` TEXT NULL,
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`  INT NOT NULL DEFAULT 0,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_cat_type` (`category`, `type_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `type_prompts` (`category`, `type_key`, `label_ar`, `sort_order`) VALUES
('content','introductory','تعريفي',1),
('content','promotional','إعلاني / ترويجي',2),
('content','educational','توعوي / تعليمي',3),
('content','storytelling','قصصي',4),
('content','engagement','تفاعلي',5),
('content','offer','عرض وخصم',6),
('content','trend','ترند',7),
('content','testimonial','آراء عملاء',8),
('design','post','تصميم بوست',1),
('design','general','تصميم عام',2),
('design','logo','لوجو',3),
('design','before_after','قبل وبعد',4),
('design','personal','بورتريه شخصي',5),
('design','story','ستوري / ريلز',6),
('plan','ideas','توليد أفكار الخطة',1),
('plan','produce','إنتاج بوست من فكرة',2);

CREATE TABLE IF NOT EXISTS `idea_pricing_tiers` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `min_ideas`  INT NOT NULL,
    `max_ideas`  INT NOT NULL,
    `credits`    INT NOT NULL,
    `label_ar`   VARCHAR(120) NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    INDEX `idx_range` (`min_ideas`, `max_ideas`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `idea_pricing_tiers` (`min_ideas`, `max_ideas`, `credits`, `label_ar`)
SELECT * FROM (SELECT 1 a, 10 b, 2 c, 'حتى 10 أفكار' d) x
WHERE NOT EXISTS (SELECT 1 FROM idea_pricing_tiers WHERE min_ideas = 1 AND max_ideas = 10);
INSERT INTO `idea_pricing_tiers` (`min_ideas`, `max_ideas`, `credits`, `label_ar`)
SELECT * FROM (SELECT 11 a, 20 b, 4 c, '11 إلى 20 فكرة' d) x
WHERE NOT EXISTS (SELECT 1 FROM idea_pricing_tiers WHERE min_ideas = 11 AND max_ideas = 20);
INSERT INTO `idea_pricing_tiers` (`min_ideas`, `max_ideas`, `credits`, `label_ar`)
SELECT * FROM (SELECT 21 a, 30 b, 6 c, '21 إلى 30 فكرة' d) x
WHERE NOT EXISTS (SELECT 1 FROM idea_pricing_tiers WHERE min_ideas = 21 AND max_ideas = 30);

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('ai_api_key_enc', '');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('ai_provider', 'openrouter');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('ai_image_model', 'google/gemini-2.5-flash-image');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('allow_mock', '0');

-- ---------------------------------------------------------
-- جلسات التجربة المجانية (اصنع منشورك الآن) — قمع التسجيل
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `trial_sessions` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `token`           CHAR(40) NOT NULL UNIQUE,
    `business_name`   VARCHAR(200) NULL,
    `industry`        VARCHAR(150) NULL,
    `audience`        VARCHAR(300) NULL,
    `services`        TEXT NULL,
    `tone`            VARCHAR(80) NULL,
    `dialect`         VARCHAR(40) NULL,
    `goal`            VARCHAR(150) NULL,
    `ideas_json`      TEXT NULL,
    `chosen_idea`     TEXT NULL,
    `generated_text`  MEDIUMTEXT NULL,
    `hashtags`        VARCHAR(600) NULL,
    `cta`             VARCHAR(400) NULL,
    `ip`              VARCHAR(45) NULL,
    `claimed_user_id` INT NULL,
    `claimed_at`      DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_created` (`created_at`),
    INDEX `idx_claimed` (`claimed_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('trial_enabled', '1');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('trial_ideas_count', '4');
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('trial_per_ip_hour', '5');

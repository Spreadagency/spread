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

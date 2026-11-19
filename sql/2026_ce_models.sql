-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — Content Engine v3 · المرحلة C: سجل الموديلات + خريطة المراحل + الإعدادات الجاهزة
--   ai_model_registry  الموديلات (بتتجاب من الـ API بتاع المزود) بنوعها وسعرها وحدودها
--   ai_model_presets   اقتصادي · متوازن · أفضل جودة
--   ai_task_routes     + temperature · stage_group · sort_order  (+ مراحل المحرك الجديدة)
--   ai_runs            + campaign_id · prompt_version_id · model_id · tokens_cached · retry_of · sandbox
--   ai_attempts        + tokens_cached
-- آمن للتشغيل أكتر من مرة · مابيغيّرش ولا يمسح أي بيانات موجودة · الرجوع: 2026_ce_models_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND column_name = 'campaign_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_runs` ADD COLUMN `campaign_id` INT NULL COMMENT ''رقم الحملة (لو العملية تبع حملة)''', 'SELECT 1');
PREPARE ce0 FROM @sql; EXECUTE ce0; DEALLOCATE PREPARE ce0;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND column_name = 'prompt_version_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_runs` ADD COLUMN `prompt_version_id` INT NULL COMMENT ''prompts.id المستخدم''', 'SELECT 1');
PREPARE ce1 FROM @sql; EXECUTE ce1; DEALLOCATE PREPARE ce1;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND column_name = 'model_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_runs` ADD COLUMN `model_id` INT NULL COMMENT ''ai_model_registry.id''', 'SELECT 1');
PREPARE ce2 FROM @sql; EXECUTE ce2; DEALLOCATE PREPARE ce2;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND column_name = 'tokens_cached');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_runs` ADD COLUMN `tokens_cached` INT NOT NULL DEFAULT 0 COMMENT ''توكنز الدخل اللي اتحسبت من الكاش''', 'SELECT 1');
PREPARE ce3 FROM @sql; EXECUTE ce3; DEALLOCATE PREPARE ce3;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND column_name = 'retry_of');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_runs` ADD COLUMN `retry_of` BIGINT UNSIGNED NULL COMMENT ''إعادة تشغيل لعملية سابقة''', 'SELECT 1');
PREPARE ce4 FROM @sql; EXECUTE ce4; DEALLOCATE PREPARE ce4;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND column_name = 'sandbox');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_runs` ADD COLUMN `sandbox` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = Playground / تجربة — مابيخصمش كريدت''', 'SELECT 1');
PREPARE ce5 FROM @sql; EXECUTE ce5; DEALLOCATE PREPARE ce5;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_attempts' AND column_name = 'tokens_cached');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_attempts` ADD COLUMN `tokens_cached` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE ce6 FROM @sql; EXECUTE ce6; DEALLOCATE PREPARE ce6;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_task_routes' AND column_name = 'temperature');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_task_routes` ADD COLUMN `temperature` DECIMAL(3,2) NULL COMMENT ''NULL = حسب الكود اللي بينادي''', 'SELECT 1');
PREPARE ce7 FROM @sql; EXECUTE ce7; DEALLOCATE PREPARE ce7;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_task_routes' AND column_name = 'stage_group');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_task_routes` ADD COLUMN `stage_group` VARCHAR(20) NULL COMMENT ''engine = مراحل محرك المحتوى v3''', 'SELECT 1');
PREPARE ce8 FROM @sql; EXECUTE ce8; DEALLOCATE PREPARE ce8;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_task_routes' AND column_name = 'sort_order');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_task_routes` ADD COLUMN `sort_order` SMALLINT NOT NULL DEFAULT 100', 'SELECT 1');
PREPARE ce9 FROM @sql; EXECUTE ce9; DEALLOCATE PREPARE ce9;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND index_name = 'idx_runs_sandbox');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_runs` ADD INDEX `idx_runs_sandbox` (`sandbox`, `created_at`)', 'SELECT 1');
PREPARE ce10 FROM @sql; EXECUTE ce10; DEALLOCATE PREPARE ce10;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ai_runs' AND index_name = 'idx_runs_campaign');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_runs` ADD INDEX `idx_runs_campaign` (`campaign_id`)', 'SELECT 1');
PREPARE ce11 FROM @sql; EXECUTE ce11; DEALLOCATE PREPARE ce11;

CREATE TABLE IF NOT EXISTS `ai_model_registry` (
  `id`              INT NOT NULL AUTO_INCREMENT,
  `provider_name`   VARCHAR(60) NOT NULL COMMENT 'ai_providers.provider_name',
  `model`           VARCHAR(160) NOT NULL COMMENT 'اسم الموديل زي ما المزود بيرجّعه',
  `label`           VARCHAR(120) NULL,
  `type`            ENUM('text','image','vision','embeddings','web') NOT NULL DEFAULT 'text',
  `supports_vision` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'بيقرا صور مع النص',
  `supports_json`   TINYINT(1) NOT NULL DEFAULT 0,
  `supports_cache`  TINYINT(1) NOT NULL DEFAULT 0,
  `max_context`     INT NULL,
  `price_in_1m`     DECIMAL(12,6) NULL,
  `price_out_1m`    DECIMAL(12,6) NULL,
  `price_cached_1m` DECIMAL(12,6) NULL,
  `price_image`     DECIMAL(12,6) NULL COMMENT 'للصورة الواحدة',
  `tier`            ENUM('economy','balanced','quality') NOT NULL DEFAULT 'balanced',
  `enabled`         TINYINT(1) NOT NULL DEFAULT 1,
  `source`          ENUM('api','manual','list') NOT NULL DEFAULT 'api',
  `meta`            TEXT NULL COMMENT 'JSON من رد المزود (مختصر)',
  `created_by`      INT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_model` (`provider_name`, `model`),
  KEY `idx_type` (`type`, `enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_model_presets` (
  `preset`     VARCHAR(20) NOT NULL PRIMARY KEY COMMENT 'economy · balanced · quality',
  `label`      VARCHAR(60) NOT NULL,
  `map_json`   TEXT NULL COMMENT '{task: {"model_id":1,"fallback_id":2,"temperature":0.7}} — فاضي = بيتبني تلقائي من مستوى الموديلات',
  `applied_at` DATETIME NULL,
  `updated_by` INT NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ai_model_presets` (`preset`, `label`) VALUES
('economy', 'اقتصادي'), ('balanced', 'متوازن'), ('quality', 'أفضل جودة');

-- مراحل محرك المحتوى v3 (بتتربط بموديل وبديل وحرارة من «الموديلات ← خريطة المراحل»)
INSERT IGNORE INTO `ai_task_routes` (`task`, `capability`, `label`, `max_attempts`, `time_budget_s`, `json_required`, `temperature`, `stage_group`, `sort_order`) VALUES
('ce_brand_dna',     'text', 'Brand DNA (ملف الهوية المختصر)', 3, 150, 1, 0.30, 'engine', 10),
('ce_matrix',        'text', 'خطة المحتوى (Matrix)',          3, 180, 1, 0.40, 'engine', 20),
('ce_plan_critic',   'text', 'بوابة الخطة (ناقد)',            2, 120, 1, 0.10, 'engine', 30),
('ce_ideation',      'text', 'الأفكار (3 لكل خانة)',          3, 150, 1, 0.90, 'engine', 40),
('ce_selection',     'text', 'اختيار الفكرة (ناقد)',          2,  90, 1, 0.10, 'engine', 50),
('ce_writer',        'text', 'الكتابة',                       3, 150, 1, 0.70, 'engine', 60),
('ce_critic',        'text', 'مراجعة الجودة (ناقد)',          2, 120, 1, 0.10, 'engine', 70),
('ce_final_qa',      'text', 'مراجعة الخطة النهائية',         2, 180, 1, 0.10, 'engine', 80),
('ce_design_prompt', 'text', 'برومبت التصميم',                3,  90, 1, 0.50, 'engine', 90),
('ce_vision_qa',     'text', 'مراجعة الصورة (Vision)',        2,  90, 1, 0.10, 'engine', 100);

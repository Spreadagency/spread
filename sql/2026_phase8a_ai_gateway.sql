-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة 8-أ: بوابة الذكاء الاصطناعي + الاستهلاك والتكلفة + محاسبة الكريدت
--   ai_runs          → العملية اللي العميل طلبها (منشور · تصميم · بحث …)
--   ai_attempts      → كل محاولة عند مزود (ناجحة/فاشلة) بالتوكنز والتكلفة وسعر الصرف وقتها
--   ai_prices        → أسعار الموديلات بتاريخ سريان (السعر القديم مايتعدلش — بيتضاف سعر جديد)
--   fx_rates         → سعر الدولار بالجنيه بتاريخ سريان (التغيير يسري على اللي بعده بس)
--   ai_task_routes   → سلسلة المزودين لكل مهمة (أساسي ← بديل ← بديل)
--   ai_provider_state→ Circuit Breaker لكل مزود × قدرة
--   admin_alerts     → تنبيهات الأدمن (جرس + إيميل)
--   credit_lots      → دفعات الكريدت حسب المصدر (مدفوع/مجاني/عرض/تعويض) لحساب الإيراد الحقيقي
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ai_runs` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_key`         CHAR(32) NOT NULL,
  `request_id`      CHAR(16) NULL,
  `user_id`         INT NULL,
  `task`            VARCHAR(40) NOT NULL,
  `feature`         VARCHAR(60) NULL,
  `route`           VARCHAR(12) NOT NULL DEFAULT 'legacy',     -- legacy | smart | gateway
  `ref_type`        VARCHAR(40) NULL,
  `ref_id`          INT NULL,
  `status`          VARCHAR(12) NOT NULL DEFAULT 'running',    -- running | ok | failed | refused
  `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `failovers`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `provider`        VARCHAR(60) NULL,
  `model`           VARCHAR(120) NULL,
  `tokens_in`       INT NOT NULL DEFAULT 0,
  `tokens_out`      INT NOT NULL DEFAULT 0,
  `images_count`    SMALLINT NOT NULL DEFAULT 0,
  `cost_usd`        DECIMAL(12,6) NOT NULL DEFAULT 0,          -- كل المحاولات (الناجحة والفاشلة) = تكلفة المنصة
  `cost_failed_usd` DECIMAL(12,6) NOT NULL DEFAULT 0,          -- منها: المحاولات الفاشلة
  `cost_egp`        DECIMAL(12,4) NOT NULL DEFAULT 0,
  `cost_source`     VARCHAR(12) NOT NULL DEFAULT 'none',       -- provider | calculated | estimated | mixed | none
  `fx_rate`         DECIMAL(10,4) NULL,
  `credits_charged` INT NOT NULL DEFAULT 0,
  `revenue_egp`     DECIMAL(12,4) NOT NULL DEFAULT 0,
  `error_class`     VARCHAR(20) NULL,
  `error`           VARCHAR(300) NULL,
  `duration_ms`     INT NOT NULL DEFAULT 0,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at`     DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_run_key` (`run_key`),
  KEY `idx_runs_date` (`created_at`),
  KEY `idx_runs_user` (`user_id`, `created_at`),
  KEY `idx_runs_task` (`task`, `created_at`),
  KEY `idx_runs_ref` (`ref_type`, `ref_id`),
  KEY `idx_runs_req` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_attempts` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id`        BIGINT UNSIGNED NOT NULL,
  `seq`           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `provider`      VARCHAR(60) NULL,
  `model`         VARCHAR(120) NULL,
  `capability`    VARCHAR(10) NOT NULL DEFAULT 'text',
  `status`        VARCHAR(10) NOT NULL,                      -- ok | failed
  `error_class`   VARCHAR(20) NULL,                          -- network | timeout | rate_limit | auth | quota | server | bad_json | policy | bad_request | not_found | empty
  `http_code`     SMALLINT NULL,
  `error`         VARCHAR(300) NULL,
  `tokens_in`     INT NOT NULL DEFAULT 0,
  `tokens_out`    INT NOT NULL DEFAULT 0,
  `images_count`  SMALLINT NOT NULL DEFAULT 0,
  `image_size`    VARCHAR(12) NULL,
  `cost_usd`      DECIMAL(12,6) NOT NULL DEFAULT 0,
  `cost_source`   VARCHAR(12) NOT NULL DEFAULT 'none',
  `price_ref`     VARCHAR(60) NULL,                          -- أرقام صفوف الأسعار المستخدمة
  `fx_rate`       DECIMAL(10,4) NULL,
  `cost_egp`      DECIMAL(12,4) NOT NULL DEFAULT 0,
  `duration_ms`   INT NOT NULL DEFAULT 0,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_att_run` (`run_id`),
  KEY `idx_att_prov` (`provider`, `created_at`),
  KEY `idx_att_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_prices` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `provider`       VARCHAR(60) NOT NULL DEFAULT '*',           -- * = أي مزود بنفس اسم الموديل
  `model`          VARCHAR(120) NOT NULL,
  `unit`           VARCHAR(12) NOT NULL,                       -- in_1m | out_1m | image | request
  `size`           VARCHAR(12) NOT NULL DEFAULT '',            -- للصور: 1024x1024 … (فاضي = أي مقاس)
  `price_usd`      DECIMAL(12,6) NOT NULL,
  `effective_from` DATETIME NOT NULL,
  `note`           VARCHAR(160) NULL,
  `created_by`     INT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_price_lookup` (`model`, `unit`, `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fx_rates` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `currency`       CHAR(3) NOT NULL DEFAULT 'USD',
  `rate_egp`       DECIMAL(10,4) NOT NULL,
  `effective_from` DATETIME NOT NULL,
  `note`           VARCHAR(160) NULL,
  `created_by`     INT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_fx` (`currency`, `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_task_routes` (
  `task`            VARCHAR(40) NOT NULL PRIMARY KEY,
  `capability`      VARCHAR(10) NOT NULL DEFAULT 'text',       -- text | image | web
  `label`           VARCHAR(80) NULL,
  `chain_json`      TEXT NULL,                                 -- [{"p":"openrouter","m":"openai/gpt-4o-mini"}, …] — فاضي = حسب أولوية المزودين
  `max_attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `time_budget_s`   SMALLINT UNSIGNED NOT NULL DEFAULT 120,
  `cost_budget_usd` DECIMAL(10,4) NULL,
  `json_required`   TINYINT(1) NOT NULL DEFAULT 0,
  `allow_fallback`  TINYINT(1) NOT NULL DEFAULT 1,
  `updated_by`      INT NULL,
  `updated_at`      DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_provider_state` (
  `provider`      VARCHAR(60) NOT NULL,
  `capability`    VARCHAR(10) NOT NULL,
  `state`         VARCHAR(10) NOT NULL DEFAULT 'closed',       -- closed (شغال) | open (متوقف مؤقتًا) | half (اختبار)
  `fail_count`    SMALLINT NOT NULL DEFAULT 0,
  `window_start`  DATETIME NULL,
  `opened_until`  DATETIME NULL,
  `reason`        VARCHAR(20) NULL,
  `last_error`    VARCHAR(300) NULL,
  `last_ok_at`    DATETIME NULL,
  `last_fail_at`  DATETIME NULL,
  `updated_at`    DATETIME NULL,
  PRIMARY KEY (`provider`, `capability`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_alerts` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `level`       VARCHAR(10) NOT NULL DEFAULT 'info',           -- info | warn | critical
  `kind`        VARCHAR(40) NOT NULL,
  `title`       VARCHAR(200) NOT NULL,
  `body`        VARCHAR(1000) NULL,
  `link`        VARCHAR(255) NULL,
  `dedupe_key`  VARCHAR(120) NULL,
  `hits`        INT NOT NULL DEFAULT 1,
  `emailed_at`  DATETIME NULL,
  `read_at`     DATETIME NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_alert_open` (`read_at`, `last_at`),
  KEY `idx_alert_dedupe` (`dedupe_key`, `last_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `credit_lots` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT NOT NULL,
  `source`        VARCHAR(16) NOT NULL,                        -- paid | free | bonus | promo | compensation | legacy
  `credits`       INT NOT NULL,
  `remaining`     INT NOT NULL,
  `frozen`        INT NOT NULL DEFAULT 0,                      -- اتجمّد مع انتهاء الصلاحية (بيرجع لو جدّد خلال فترة السماح)
  `forfeited`     INT NOT NULL DEFAULT 0,                      -- سقط نهائيًا
  `unit_egp`      DECIMAL(10,4) NOT NULL DEFAULT 0,            -- قيمة الكريدت الواحد (مدفوع = المبلغ ÷ الكريدت)
  `estimated`     TINYINT(1) NOT NULL DEFAULT 0,
  `tx_id`         INT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_lots_user` (`user_id`, `remaining`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- credit_transactions: المصدر · رقم الطلب · مفتاح منع التكرار · الإيراد · الرصيد بعد العملية
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'credit_transactions' AND column_name = 'source');
SET @sql := IF(@c = 0, 'ALTER TABLE `credit_transactions` ADD COLUMN `source` VARCHAR(16) NULL, ADD COLUMN `request_id` CHAR(16) NULL, ADD COLUMN `idem_key` VARCHAR(100) NULL, ADD COLUMN `revenue_egp` DECIMAL(12,4) NULL, ADD COLUMN `alloc_json` TEXT NULL, ADD COLUMN `balance_after` INT NULL, ADD UNIQUE KEY `uq_ct_idem` (`idem_key`), ADD KEY `idx_ct_req` (`request_id`)', 'SELECT 1');
PREPARE p1 FROM @sql; EXECUTE p1; DEALLOCATE PREPARE p1;

-- ai_providers: نوع المزود · القدرات · الموديلات · سياسة البدائل وبيانات العملاء · آخر اختبار
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_providers' AND column_name = 'kind');
SET @sql := IF(@c = 0, 'ALTER TABLE `ai_providers` ADD COLUMN `kind` VARCHAR(20) NOT NULL DEFAULT ''custom'', ADD COLUMN `label` VARCHAR(80) NULL, ADD COLUMN `supports_web` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `supports_image_edit` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `allow_fallback` TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN `allow_customer_data` TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN `timeout_s` SMALLINT NOT NULL DEFAULT 60, ADD COLUMN `models_json` TEXT NULL, ADD COLUMN `last_test_at` DATETIME NULL, ADD COLUMN `last_test_ok` TINYINT(1) NULL, ADD COLUMN `last_test_msg` VARCHAR(255) NULL, ADD COLUMN `updated_at` DATETIME NULL', 'SELECT 1');
PREPARE p2 FROM @sql; EXECUTE p2; DEALLOCATE PREPARE p2;

-- ai_request_logs.route كان ENUM(legacy,smart) → نص علشان «gateway»
SET @t := (SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_request_logs' AND column_name = 'route');
SET @sql := IF(@t = 'enum', 'ALTER TABLE `ai_request_logs` MODIFY `route` VARCHAR(20) NOT NULL DEFAULT ''legacy''', 'SELECT 1');
PREPARE p3 FROM @sql; EXECUTE p3; DEALLOCATE PREPARE p3;

-- المزودين المعروفين
UPDATE `ai_providers` SET `kind` = `provider_name`, `label` = COALESCE(`label`, 'OpenRouter'), `supports_web` = 1, `supports_image` = 1, `supports_image_edit` = 1, `supports_vision` = 1
  WHERE `provider_name` = 'openrouter' AND `kind` = 'custom';
UPDATE `ai_providers` SET `kind` = `provider_name`, `label` = COALESCE(`label`, 'OpenAI'), `supports_web` = 1, `supports_image` = 1, `supports_image_edit` = 1, `supports_vision` = 1
  WHERE `provider_name` = 'openai' AND `kind` = 'custom';
-- Gemini: الرابط المخزّن بيفضل زي ما هو (الـ Smart Router القديم بيستخدمه) — البوابة بتضيف /openai لوحدها
UPDATE `ai_providers` SET `kind` = `provider_name`, `label` = COALESCE(`label`, 'Google Gemini'), `supports_vision` = 1
  WHERE `provider_name` = 'gemini' AND `kind` = 'custom';
INSERT IGNORE INTO `ai_providers` (`provider_name`, `kind`, `label`, `api_base_url`, `status`, `priority`, `supports_text`, `supports_web`, `supports_image`, `supports_vision`)
  VALUES ('perplexity', 'perplexity', 'Perplexity', 'https://api.perplexity.ai', 'disabled', 4, 1, 1, 0, 0);
UPDATE `ai_providers` SET `models_json` = '{"text":["openai/gpt-4o-mini","anthropic/claude-3.5-haiku","google/gemini-2.0-flash-001"],"image":["google/gemini-2.5-flash-image"],"web":["openai/gpt-4o-mini"]}' WHERE `provider_name` = 'openrouter' AND `models_json` IS NULL;
UPDATE `ai_providers` SET `models_json` = '{"text":["gpt-4o-mini","gpt-4o"],"image":["gpt-image-1"],"web":["gpt-4o-mini-search-preview"]}' WHERE `provider_name` = 'openai' AND `models_json` IS NULL;
UPDATE `ai_providers` SET `models_json` = '{"text":["gemini-2.0-flash","gemini-2.5-flash"]}' WHERE `provider_name` = 'gemini' AND `models_json` IS NULL;
UPDATE `ai_providers` SET `models_json` = '{"text":["sonar"],"web":["sonar","sonar-pro"]}' WHERE `provider_name` = 'perplexity' AND `models_json` IS NULL;

-- المهام (السلسلة فاضية = المزودين النشطين حسب الأولوية)
INSERT IGNORE INTO `ai_task_routes` (`task`, `capability`, `label`, `max_attempts`, `time_budget_s`, `json_required`) VALUES
('content',          'text',  'كتابة المحتوى',            3, 120, 0),
('ideas',            'text',  'الأفكار والخطط',            3, 120, 0),
('eval',             'text',  'تقييم المحتوى',             2,  60, 0),
('brand',            'text',  'Brand Brain والمصادر',      3, 150, 0),
('research_analyze', 'text',  'تحليل البحث العميق',         3, 240, 1),
('research_search',  'web',   'بحث الويب',                 3, 300, 0),
('design',           'image', 'التصميمات والصور',           3, 240, 0),
('general',          'text',  'مهام عامة',                 3, 120, 0);

-- سعر الدولار المبدئي (يتغير من «تكاليف الـ AI» ويسري على اللي بعده بس)
INSERT INTO `fx_rates` (`currency`, `rate_egp`, `effective_from`, `note`)
  SELECT 'USD', 50.0000, '2026-01-01 00:00:00', 'السعر المبدئي' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `fx_rates` WHERE `currency` = 'USD');

-- أسعار مبدئية للموديلات الشائعة (USD) — راجعها وحدّثها من «تكاليف الـ AI»
INSERT INTO `ai_prices` (`provider`, `model`, `unit`, `size`, `price_usd`, `effective_from`, `note`)
  SELECT * FROM (
    SELECT '*' p, 'gpt-4o-mini' m, 'in_1m' u, '' s, 0.150000 pr, '2026-01-01 00:00:00' ef, 'سعر مبدئي — راجعه' n UNION ALL
    SELECT '*', 'gpt-4o-mini', 'out_1m', '', 0.600000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'gpt-4o', 'in_1m', '', 2.500000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'gpt-4o', 'out_1m', '', 10.000000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'gpt-4o-mini-search-preview', 'in_1m', '', 0.150000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'gpt-4o-mini-search-preview', 'out_1m', '', 0.600000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'gpt-4o-mini-search-preview', 'request', '', 0.027500, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'claude-3.5-haiku', 'in_1m', '', 0.800000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'claude-3.5-haiku', 'out_1m', '', 4.000000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'gemini-2.0-flash', 'in_1m', '', 0.100000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'gemini-2.0-flash', 'out_1m', '', 0.400000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'gemini-2.0-flash-001', 'in_1m', '', 0.100000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'gemini-2.0-flash-001', 'out_1m', '', 0.400000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'sonar', 'in_1m', '', 1.000000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'sonar', 'out_1m', '', 1.000000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'sonar', 'request', '', 0.005000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه' UNION ALL
    SELECT '*', 'gpt-image-1', 'image', '1024x1024', 0.042000, '2026-01-01 00:00:00', 'سعر مبدئي (جودة متوسطة) — راجعه' UNION ALL
    SELECT '*', 'gpt-image-1', 'image', '1024x1536', 0.063000, '2026-01-01 00:00:00', 'سعر مبدئي (جودة متوسطة) — راجعه' UNION ALL
    SELECT '*', 'gpt-image-1', 'image', '1536x1024', 0.063000, '2026-01-01 00:00:00', 'سعر مبدئي (جودة متوسطة) — راجعه' UNION ALL
    SELECT '*', 'gemini-2.5-flash-image', 'image', '', 0.039000, '2026-01-01 00:00:00', 'سعر مبدئي — راجعه'
  ) seed
  WHERE NOT EXISTS (SELECT 1 FROM `ai_prices` LIMIT 1);

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('ai_gateway_enabled',        '0'),    -- تشغيل البوابة الموحدة بالبدائل (مقفولة لحد ما تضبط المزودين وتختبر)
('ai_breaker_threshold',      '3'),    -- عدد الأعطال المؤقتة اللي تفتح الـ Circuit Breaker
('ai_breaker_window_min',     '5'),    -- خلال كام دقيقة
('ai_breaker_cooldown_min',   '5'),    -- يفضل متوقف كام دقيقة قبل محاولة اختبار واحدة
('ai_est_text_in_1m',         '0.5'),  -- تقدير لو الموديل مالوش سعر (USD لكل مليون توكن)
('ai_est_text_out_1m',        '1.5'),
('ai_est_image',              '0.05'),
('alerts_email',              ''),     -- إيميل تنبيهات الأدمن (فاضي = بدون إيميل)
('alerts_email_min_level',    'warn'), -- warn | critical
('ai_failover_alert_per_hour','5');    -- تنبيه لو التحويل للبديل حصل أكتر من كده في الساعة

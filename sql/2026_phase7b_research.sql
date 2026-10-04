-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة ⑦-ب: البحث العميق
--   researches: سؤال البحث + النطاق + خطوات التنفيذ + المصادر الحقيقية + النتيجة المنظّمة
--   المصادر بتيجي من بحث ويب حقيقي (OpenRouter web / OpenAI search / Perplexity) — مفيش أرقام متألفة
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `researches` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT NOT NULL,
  `title`         VARCHAR(190) NOT NULL DEFAULT '',
  `question`      TEXT NOT NULL,
  `rtype`         VARCHAR(20) NOT NULL DEFAULT 'custom',
  `scope_json`    TEXT NULL,                 -- السوق · المدينة · الفترة · اللغة · العمق · الاختيارات · المصادر المستبعدة
  `status`        ENUM('draft','running','done','failed') NOT NULL DEFAULT 'draft',
  `step`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `steps_json`    TEXT NULL,                 -- خطوات التنفيذ ومحاولات كل خطوة
  `sources_json`  MEDIUMTEXT NULL,           -- [{n,url,title,site,kind,snippet,took,date,pinned,excluded}]
  `axes_json`     MEDIUMTEXT NULL,           -- نتايج البحث الخام لكل محور (بأرقام المصادر)
  `result_json`   MEDIUMTEXT NULL,           -- النتيجة المنظمة (بعد التحقق من المصادر)
  `prev_json`     MEDIUMTEXT NULL,           -- النتيجة قبل آخر تحديث (لحساب الجديد)
  `changes_json`  TEXT NULL,
  `brain_json`    TEXT NULL,                 -- اللي اتضاف لـ Brand Brain
  `saved`         TINYINT(1) NOT NULL DEFAULT 0,
  `credits`       INT NOT NULL DEFAULT 0,
  `engine`        VARCHAR(80) NULL,
  `error`         VARCHAR(255) NULL,
  `runs`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rs_user` (`user_id`, `id`),
  KEY `idx_rs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('research_enabled',     '1'),
('research_cost_quick',  '2'),    -- بحث سريع: طلب بحث واحد + تحليل
('research_cost_medium', '4'),    -- متوسط: 3 طلبات بحث + تحليل
('research_cost_deep',   '8'),    -- عميق: طلب بحث لكل محور + تحليل
('research_model',       ''),     -- فاضي = الموديل الأساسي (OpenRouter) أو gpt-4o-mini-search-preview (OpenAI) أو sonar (Perplexity)
('research_timeout',     '120');

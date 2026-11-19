-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — قاعدة الـ AI المنفصلة (نظام الجودة · MCP) — المرحلة 3
--
-- ⚠️ بيتشغّل على قاعدة الـ AI (MCP_AI_DB_NAME) — مش قاعدة المنصة.
--    الأسهل: لوحة الأدمن ← «إعداد الـ MCP» ← «إنشاء جداول قاعدة الـ AI».
--
-- كل اللي الـ MCP بيكتبه هنا: التقييمات · تحليل البلاغات · المسودات · الاقتراحات ·
-- التقارير · الأمان والترابط · E2E · سجل كل طلب. قاعدة المنصة للقراءة بس.
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_mcp_ai_db_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ai_evaluations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `content_id` INT NOT NULL,
  `design_id` INT NULL,
  `prompt_version_id` INT NULL,
  `evaluator` VARCHAR(30) NOT NULL DEFAULT 'claude',
  `content_scores` TEXT NULL COMMENT 'JSON: hook · clarity · brand_voice_match · relevance_to_services · cta · dialect_correctness · originality · platform_fit (1–10)',
  `design_scores` TEXT NULL COMMENT 'JSON: brand_colors_match · text_legibility · arabic_text_correctness · composition · idea_match · watermark_placement (1–10)',
  `content_total` DECIMAL(4,2) NULL,
  `design_total` DECIMAL(4,2) NULL,
  `overall` DECIMAL(4,2) NULL,
  `strengths` TEXT NULL,
  `weaknesses` TEXT NULL,
  `fix_suggestion` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_content` (`content_id`),
  KEY `idx_version` (`prompt_version_id`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `issue_analysis` (
  `issue_id` INT UNSIGNED NOT NULL,
  `ai_category` VARCHAR(30) NOT NULL COMMENT 'bug · content_quality · design_quality · ux · feature_request · security',
  `ai_severity` ENUM('low','medium','high','critical') NOT NULL,
  `root_cause` TEXT NULL,
  `fix` TEXT NULL,
  `related_issue_ids` TEXT NULL COMMENT 'JSON',
  `analyzed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`issue_id`),
  KEY `idx_sev` (`ai_severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reply_drafts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issue_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `channel` ENUM('inbox','whatsapp') NOT NULL DEFAULT 'inbox',
  `status` ENUM('draft','approved','sent','rejected') NOT NULL DEFAULT 'draft',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` DATETIME NULL,
  `reviewed_by` INT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_issue` (`issue_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `change_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(40) NOT NULL COMMENT 'prompt · style · ux · code · config · content_rule',
  `target` VARCHAR(255) NOT NULL,
  `diff` MEDIUMTEXT NULL,
  `reason` TEXT NOT NULL,
  `evidence` TEXT NULL COMMENT 'JSON',
  `expected_impact` TEXT NULL,
  `priority` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `status` ENUM('pending','approved','rejected','applied','reverted','postponed') NOT NULL DEFAULT 'pending',
  `fingerprint` CHAR(40) NOT NULL COMMENT 'sha1(type|target|diff) — المرفوض مايتعادش من غير دليل جديد',
  `evidence_hash` CHAR(40) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` DATETIME NULL,
  `reviewed_by` INT NULL,
  `review_note` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`, `created_at`),
  KEY `idx_fp` (`fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prompt_drafts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `prompt_type` VARCHAR(100) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `reason` TEXT NOT NULL,
  `parent_prompt_id` INT NULL COMMENT 'الإصدار اللي المسودة مبنية عليه (prompts.id)',
  `status` ENUM('draft','approved','rejected') NOT NULL DEFAULT 'draft',
  `applied_prompt_id` INT NULL COMMENT 'الصف اللي اتعمل في prompts بعد الموافقة',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` DATETIME NULL,
  `reviewed_by` INT NULL,
  `review_note` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`prompt_type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `style_preset_drafts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `preset_id` INT NULL,
  `payload` TEXT NOT NULL COMMENT 'JSON',
  `reason` TEXT NOT NULL,
  `status` ENUM('draft','approved','rejected') NOT NULL DEFAULT 'draft',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` DATETIME NULL,
  `reviewed_by` INT NULL,
  `review_note` TEXT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sandbox_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `content_id` INT NOT NULL,
  `prompt_draft_id` INT UNSIGNED NULL,
  `prompt_version_id` INT NULL,
  `before_text` MEDIUMTEXT NULL,
  `after_text` MEDIUMTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_reports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('daily','weekly','security') NOT NULL,
  `report_date` DATE NOT NULL,
  `summary` TEXT NOT NULL,
  `payload` MEDIUMTEXT NULL COMMENT 'JSON',
  `whatsapp_sent` TINYINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_date` (`type`, `report_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `security_findings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` ENUM('code_review','zap','config','logs','dependency') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `severity` ENUM('info','low','medium','high','critical') NOT NULL,
  `location` VARCHAR(500) NOT NULL,
  `description` TEXT NULL,
  `recommendation` TEXT NULL,
  `status` ENUM('open','in_progress','fixed','accepted_risk','false_positive') NOT NULL DEFAULT 'open',
  `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fixed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_src_loc` (`source`, `location`(191)),
  KEY `idx_sev` (`severity`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `integrity_issues` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `check_key` VARCHAR(80) NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_ids` TEXT NULL COMMENT 'JSON (أول 200)',
  `count` INT NOT NULL DEFAULT 0,
  `description` TEXT NULL,
  `suggested_fix` TEXT NULL,
  `status` ENUM('open','fixed','ignored') NOT NULL DEFAULT 'open',
  `detected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_check` (`check_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `e2e_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flow_key` VARCHAR(80) NOT NULL,
  `status` ENUM('passed','failed') NOT NULL,
  `duration_ms` INT NULL,
  `error` TEXT NULL,
  `screenshot_path` VARCHAR(500) NULL,
  `run_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_flow_time` (`flow_key`, `run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mcp_audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client` VARCHAR(40) NULL COMMENT 'claude · n8n · test',
  `tool` VARCHAR(80) NOT NULL,
  `scope` VARCHAR(10) NOT NULL,
  `params` TEXT NULL COMMENT 'JSON (من غير بيانات شخصية)',
  `result_status` VARCHAR(20) NOT NULL,
  `duration_ms` INT NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_time` (`created_at`),
  KEY `idx_tool` (`tool`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

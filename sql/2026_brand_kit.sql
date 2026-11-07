-- Brand Kit: الهوية المنظّمة اللي بتأثر على صناعة المحتوى
-- الجداول بتتعمل تلقائيًا أول مرة (includes/brand-kit.php → bk_ensure_schema) — الملف ده للتشغيل اليدوي بس.
-- «ADD COLUMN IF NOT EXISTS» محتاجة MariaDB (موجودة في cPanel). على MySQL 8 شيل «IF NOT EXISTS» لو العمود مش موجود.
-- الصور المرجعية للشخصيات والـ Presets بتتخزن كمسارات ملفات (uploads/characters · uploads/presets) زي باقي صور المنصة.

CREATE TABLE IF NOT EXISTS brand_kits (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, sector_template_id INT NULL, data LONGTEXT NOT NULL,
  completeness TINYINT NOT NULL DEFAULT 0, version INT NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bk_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brand_kit_versions (
  id INT AUTO_INCREMENT PRIMARY KEY, brand_kit_id INT NOT NULL, version INT NOT NULL, data LONGTEXT NOT NULL,
  changed_by ENUM('user','ai','admin') NOT NULL DEFAULT 'user', note VARCHAR(255) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bkv_kit (brand_kit_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brand_services (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(150) NOT NULL, description TEXT NULL,
  priority ENUM('high','medium','low') NOT NULL DEFAULT 'medium', is_focus TINYINT(1) NOT NULL DEFAULT 0, seasons TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bs_user (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brand_topics (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, service_id INT NULL, title VARCHAR(255) NOT NULL,
  source ENUM('user','ai','template','library') NOT NULL DEFAULT 'user', times_used INT NOT NULL DEFAULT 0, last_used_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bt_user (user_id, is_active), KEY idx_bt_service (service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brand_characters (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(100) NOT NULL,
  type ENUM('real_person','ai_character','mascot') NOT NULL, role VARCHAR(150) NULL, description TEXT NULL, personality TEXT NULL,
  use_in VARCHAR(255) NULL, share_percent TINYINT NOT NULL DEFAULT 0, voice_id VARCHAR(100) NULL,
  consent_confirmed TINYINT(1) NOT NULL DEFAULT 0, consent_at DATETIME NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP, KEY idx_bc_user (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brand_character_images (
  id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, image_path VARCHAR(255) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, KEY idx_bci_char (character_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brand_formats (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, format ENUM('carousel','reel','single_image','story','text') NOT NULL,
  `rank` TINYINT NOT NULL DEFAULT 1, per_week TINYINT NOT NULL DEFAULT 0, settings TEXT NULL, is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_bf (user_id, format)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brand_feedback (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, content_id INT NULL, field VARCHAR(100) NULL,
  action ENUM('like','dislike','edit','regenerate','chip') NOT NULL, before_value TEXT NULL, after_value TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP, KEY idx_bfb_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brand_suggestions (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, field VARCHAR(100) NOT NULL, message TEXT NOT NULL, proposed_value TEXT NULL,
  status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, decided_at DATETIME NULL,
  KEY idx_bsg_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sector_templates (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, keywords VARCHAR(500) NULL, brief_questions TEXT NULL,
  default_pillars TEXT NULL, default_topics TEXT NULL, forbidden TEXT NULL, notes TEXT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS style_presets (
  id INT AUTO_INCREMENT PRIMARY KEY, kind ENUM('design_style','palette','tone','visual','character_style') NOT NULL, name VARCHAR(100) NOT NULL,
  preview_path VARCHAR(255) NULL, data TEXT NULL, sector_template_id INT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
  KEY idx_sp_kind (kind, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brand_learning (
  id INT AUTO_INCREMENT PRIMARY KEY, kind ENUM('method','hooks','rules','topics','reference') NOT NULL DEFAULT 'reference',
  title VARCHAR(200) NOT NULL, summary VARCHAR(500) NULL, body MEDIUMTEXT NULL, source_url VARCHAR(500) NULL, sector_template_id INT NULL,
  agents VARCHAR(100) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, extracted_at DATETIME NULL, created_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_bl_kind (kind, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learning_topics (
  id INT AUTO_INCREMENT PRIMARY KEY, learning_id INT NULL, sector_template_id INT NULL, title VARCHAR(255) NOT NULL,
  angle VARCHAR(100) NULL, pillar VARCHAR(100) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, times_used INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP, KEY idx_lt_sector (sector_template_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `contents` ADD COLUMN IF NOT EXISTS `brand_overrides` TEXT NULL;
ALTER TABLE `contents` ADD COLUMN IF NOT EXISTS `brand_snapshot` MEDIUMTEXT NULL;
ALTER TABLE `contents` ADD COLUMN IF NOT EXISTS `ai_reasoning` TEXT NULL;
ALTER TABLE `contents` ADD COLUMN IF NOT EXISTS `brief_json` TEXT NULL;
ALTER TABLE `contents` ADD COLUMN IF NOT EXISTS `bk_service_id` INT NULL;
ALTER TABLE `contents` ADD COLUMN IF NOT EXISTS `bk_topic_id` INT NULL;
ALTER TABLE `contents` ADD COLUMN IF NOT EXISTS `bk_character_id` INT NULL;
ALTER TABLE `contents` ADD COLUMN IF NOT EXISTS `feedback` TINYINT NULL;
ALTER TABLE `campaigns` ADD COLUMN IF NOT EXISTS `brief_json` TEXT NULL;
ALTER TABLE `campaign_ideas` ADD COLUMN IF NOT EXISTS `brief_json` TEXT NULL;

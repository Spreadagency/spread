-- =============================================================
-- شوف نفسك بعد التخسيس — database schema + seed
-- MySQL 8 / MariaDB 10.4+ · utf8mb4_unicode_ci
-- Import once from phpMyAdmin (Import tab) or:
--   mysql -u USER -p DBNAME < install/schema.sql
-- =============================================================
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- Leads (one row per phone number)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leads (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(100) NOT NULL,
  phone          VARCHAR(15)  NOT NULL COMMENT 'normalized 01xxxxxxxxx',
  utm_source     VARCHAR(100) NULL,
  utm_medium     VARCHAR(100) NULL,
  utm_campaign   VARCHAR(150) NULL,
  utm_content    VARCHAR(150) NULL,
  utm_term       VARCHAR(150) NULL,
  fbclid         VARCHAR(255) NULL,
  fbp            VARCHAR(100) NULL,
  fbc            VARCHAR(255) NULL,
  ip             VARCHAR(45)  NULL,
  user_agent     VARCHAR(255) NULL,
  device_cookie  VARCHAR(64)  NULL,
  consent        TINYINT(1)   NOT NULL DEFAULT 0,
  status         ENUM('new','contacted','booked','not_interested') NOT NULL DEFAULT 'new',
  notes          TEXT NULL,
  regen_allowed  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'admin can allow one more generation',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_leads_phone (phone),
  KEY ix_leads_created (created_at),
  KEY ix_leads_status (status),
  KEY ix_leads_campaign (utm_campaign)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Generations (one row per uploaded photo)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS generations (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id        INT UNSIGNED NOT NULL,
  original_path  VARCHAR(255) NULL COMMENT 'relative to storage/uploads/originals',
  result_path    VARCHAR(255) NULL COMMENT 'relative to storage/uploads/results',
  share_token    VARCHAR(32)  NULL,
  status         ENUM('uploaded','processing','done','failed','rejected') NOT NULL DEFAULT 'uploaded',
  error_message  VARCHAR(500) NULL,
  attempts       INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms    INT UNSIGNED NULL,
  ip             VARCHAR(45)  NULL,
  device_cookie  VARCHAR(64)  NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at     DATETIME     NULL,
  completed_at   DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gen_token (share_token),
  KEY ix_gen_lead (lead_id),
  KEY ix_gen_status (status),
  KEY ix_gen_created (created_at),
  KEY ix_gen_ip (ip, created_at),
  KEY ix_gen_cookie (device_cookie, created_at),
  CONSTRAINT fk_gen_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Events (analytics + Pixel dedup ids)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id        INT UNSIGNED NULL,
  generation_id  INT UNSIGNED NULL,
  type           ENUM('page_view','lead','upload','generate','view_result','whatsapp_click','website_click','share','download','call_click','directions_click') NOT NULL,
  event_id       VARCHAR(64) NULL,
  meta           JSON NULL,
  ip             VARCHAR(45) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ev_type_created (type, created_at),
  KEY ix_ev_lead (lead_id),
  KEY ix_ev_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Settings (everything the admin panel controls)
-- Secret values are stored as "enc:..." (AES-256-GCM with APP_KEY).
-- A plain value also works, so a key can be pasted from phpMyAdmin.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(100) NOT NULL,
  `value`     LONGTEXT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branches (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(150) NOT NULL,
  address       VARCHAR(255) NULL,
  map_url       VARCHAR(500) NULL,
  map_embed     TEXT NULL COMMENT 'Google Maps embed src URL',
  phone         VARCHAR(30)  NULL,
  working_hours VARCHAR(255) NULL,
  sort_order    INT NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_br_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type        ENUM('experience','certificate','stat','faq','step','fact') NOT NULL,
  title       VARCHAR(255) NOT NULL,
  body        TEXT NULL,
  icon        VARCHAR(50)  NULL,
  value       VARCHAR(100) NULL COMMENT 'stat value / experience year',
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_ci_type (type, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(100) NOT NULL,
  email          VARCHAR(190) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('owner','editor','viewer') NOT NULL DEFAULT 'editor',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at  DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`         VARCHAR(120) NOT NULL COMMENT 'ip / phone / cookie',
  action        VARCHAR(40)  NOT NULL,
  `count`       INT UNSIGNED NOT NULL DEFAULT 0,
  window_start  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rl (`key`, action),
  KEY ix_rl_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id    INT UNSIGNED NULL,
  action      VARCHAR(100) NOT NULL,
  details     TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_al_admin (admin_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- Seed
-- =============================================================

-- Owner admin: admin@example.com / ChangeMe!2026  (must be changed on first login)
INSERT IGNORE INTO admins (name, email, password_hash, role, must_change_password) VALUES
('Owner', 'admin@example.com', '$2y$12$/8wgQD9Na5UiMl6CNxTYr.NH.dAlY8W12vKTIH4MS0cqvwcMrUiiW', 'owner', 1);

-- Default settings (app/settings.php has the same defaults as a fallback)
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('site_title',            'شوف نفسك بعد التخسيس — د. محمد حسام الدين المرسي'),
('meta_description',      'ارفع صورتك واحصل على محاكاة تقريبية بالذكاء الاصطناعي لشكلك بعد التخسيس — مجانًا. د. محمد حسام الدين المرسي، استشاري جراحات الغدد وجراحات المناظير والسمنة.'),
('meta_keywords',         'تكميم المعدة, عملية التكميم, جراحة السمنة, دكرنس, المنصورة'),
('canonical_url',         ''),
('locale',                'ar_EG'),
('og_title',              'شوف نفسك بعد التخسيس'),
('og_description',        'محاكاة مجانية بالذكاء الاصطناعي في ثواني — د. محمد حسام الدين المرسي'),
('og_image',              ''),
('schema_physician',      '1'),
('schema_clinic',         '1'),
('schema_faq',            '1'),
('robots_txt',            "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /r/\nDisallow: /api/"),

('hero_badge',            'محاكاة مجانية بالذكاء الاصطناعي'),
('hero_title',            'شوف نفسك بعد التخسيس'),
('hero_subtitle',         'ارفع صورتك واحصل على محاكاة تقريبية بالذكاء الاصطناعي في ثواني — مجانًا'),
('hero_button',           'ابدأ دلوقتي'),
('disclaimer_text',       'الصورة دي محاكاة تخيلية بالذكاء الاصطناعي، والنتيجة الفعلية بتختلف من شخص لشخص حسب الحالة. الاستشارة الطبية هي اللي بتحدد المناسب ليك.'),
('encouragement_title',   'عايز تعرف توصل للشكل ده إزاي؟'),
('encouragement_text',    'الدكتور يقدر يقولك العملية مناسبة ليك ولا لأ.'),
('footer_disclaimer',     'المحتوى في الصفحة دي للتوعية فقط ومش بديل عن الاستشارة الطبية. المحاكاة بالذكاء الاصطناعي صورة تقريبية وتخيلية، والنتيجة الفعلية بتختلف من شخص لشخص.'),
('privacy_text',          "بنستخدم اسمك ورقمك عشان فريق الدكتور يقدر يتواصل معاك بخصوص استفسارك بس.\nصورتك بتتحفظ في مكان آمن مش متاح للعامة، وبتتستخدم لإنشاء المحاكاة فقط، وبتتمسح تلقائيًا بعد مدة محددة.\nمش بنشارك بياناتك مع أي طرف تالت لأغراض تسويقية. تقدر تطلب مسح بياناتك في أي وقت على واتساب."),

('doctor_name',           'د. محمد حسام الدين المرسي'),
('doctor_title',          'استشاري جراحات الغدد وجراحات المناظير والسمنة'),
('doctor_bio',            'دكتوراه الجراحة العامة، ومدرس بكلية الطب جامعة المنصورة، متخصص في جراحات الغدد والمناظير والسمنة.'),
('doctor_photo',          'assets/img/doctor.webp'),
('logo',                  'assets/img/logo.png'),
('show_logo_on_result',   '1'),

('whatsapp_number',       ''),
('whatsapp_message',      'مساء الخير، أنا {name}، جربت محاكاة التخسيس وعايز أستفسر عن عملية التكميم.'),
('website_url',           ''),
('social_facebook',       ''),
('social_instagram',      ''),
('social_youtube',        ''),
('social_tiktok',         ''),
('color_primary',         '#1F73B7'),
('color_secondary',       '#0E2B45'),
('color_accent',          '#C9A24B'),

('meta_pixel_id',         ''),
('meta_capi_token',       ''),
('meta_graph_version',    'v21.0'),
('meta_test_event_code',  ''),
('pixel_event_lead',      '1'),
('pixel_event_viewcontent','1'),
('pixel_event_contact',   '1'),
('pixel_event_share',     '1'),
('ga4_id',                ''),
('gtm_id',                ''),
('custom_head_code',      ''),
('custom_body_code',      ''),
('csp_extra_hosts',       ''),

('ai_provider',           'gemini'),
('openai_api_key',        ''),
('openai_model',          'gpt-image-1'),
('openai_safety_model',   'gpt-4.1-mini'),
('openai_quality',        'medium'),
('openrouter_api_key',    ''),
('openrouter_model',      'google/gemini-2.5-flash-image'),
('openrouter_safety_model','google/gemini-2.5-flash'),
('gemini_api_key',        ''),
('gemini_model',          'gemini-2.5-flash-image'),
('gemini_safety_model',   'gemini-2.5-flash'),
('gemini_timeout',        '90'),
('gemini_auto_retry',     '1'),
('gemini_prompt',         'Edit this photo to show the same person after healthy, realistic weight loss (approximately 25–35% body fat reduction). Keep the exact same face identity, facial features, skin tone, hairstyle, age, clothing style, pose, camera angle, lighting, and background. Only slim down the body, face fullness, neck, arms, belly, and legs in a natural, believable, medically realistic way. Clothes should fit the slimmer body naturally. Photorealistic, high quality, no filters, no beautification beyond weight loss, no nudity, do not change gender or ethnicity, no text or watermark.'),
('gemini_safety_prompt',  'You are a strict image safety checker for a medical weight-loss simulation. Look at the photo and answer ONLY with JSON: {"ok": true|false, "reason": "none|no_person|multiple_people|minor|nudity|unclear"}. Set ok=false if there is no clearly visible human, more than one person, the person appears to be under 18, there is any nudity, or the person is too unclear to edit.'),

('limit_daily_global',    '300'),
('limit_per_ip',          '3'),
('limit_per_cookie',      '2'),
('limit_per_phone',       '1'),
('limit_leads_per_ip_hour','10'),
('turnstile_site_key',    ''),
('turnstile_secret',      ''),

('webhook_n8n_url',       ''),
('webhook_crm_url',       ''),
('webhook_secret',        ''),
('webhook_on_lead',       '1'),
('webhook_on_whatsapp',   '1'),

('retention_originals_days','30'),
('retention_results_days', '90'),
('share_show_before',     '0'),
('maintenance_mode',      '0'),
('timezone',              'Africa/Cairo');

-- Doctor content (stats are seeded inactive: fill the real numbers from the admin, then activate)
INSERT INTO content_items (type, title, body, icon, value, sort_order, is_active) VALUES
('stat', 'سنين خبرة',      NULL, 'clock',   '',  1, 0),
('stat', 'عدد العمليات',   NULL, 'pulse',   '',  2, 0),
('stat', 'سنين نجاح',      NULL, 'heart',   '',  3, 0),
('stat', 'رضا المرضى',     NULL, 'smile',   '',  4, 0),
('experience', 'دكتوراه الجراحة العامة', NULL, NULL, '', 1, 1),
('experience', 'مدرس بكلية الطب', 'جامعة المنصورة', NULL, '', 2, 1),
('experience', 'استشاري جراحات الغدد والمناظير والسمنة', 'عيادة دكرنس', NULL, 'حاليًا', 3, 1),
('step', 'استشارة',      'بنسمع حالتك وتاريخك الصحي، والدكتور يحدد لو العملية مناسبة ليك.', 'chat',  NULL, 1, 1),
('step', 'تقييم وتحاليل', 'فحوصات وتحاليل نتأكد بيها إن جسمك جاهز للعملية بأمان.',           'flask', NULL, 2, 1),
('step', 'عملية ومتابعة', 'العملية بالمنظار، وبعدها متابعة منتظمة وخطة أكل تساعدك توصل لهدفك.', 'pulse', NULL, 3, 1),
('faq', 'مين المناسب لعملية التكميم؟', 'القرار بيعتمد على مؤشر كتلة الجسم وحالتك الصحية ومحاولاتك السابقة في التخسيس. الدكتور بيحدد في الاستشارة لو العملية مناسبة ليك.', NULL, NULL, 1, 1),
('faq', 'العملية بتاخد وقت قد إيه؟', 'العملية نفسها بتاخد في المتوسط من ساعة لساعتين بالمنظار، والإقامة في المستشفى غالبًا يوم أو يومين.', NULL, NULL, 2, 1),
('faq', 'هحس بألم بعد العملية؟', 'بيكون فيه ألم بسيط في أول كام يوم وبيتحكم فيه بالمسكنات، ولأنها بالمنظار التعافي بيكون أسرع.', NULL, NULL, 3, 1),
('faq', 'التكلفة كام؟', 'التكلفة بتختلف حسب الحالة والمستشفى. كلّمنا على واتساب ونبعتلك التفاصيل.', NULL, NULL, 4, 1),
('faq', 'هاكل إزاي بعد العملية؟', 'هتمشي على نظام أكل متدرّج: سوائل، بعدين أكل مهروس، وبعدين أكل طبيعي بكميات صغيرة، مع متابعة مستمرة.', NULL, NULL, 5, 1),
('faq', 'الصورة اللي هتطلعلي هي النتيجة الفعلية؟', 'لأ، الصورة محاكاة تخيلية بالذكاء الاصطناعي للتوضيح بس. النتيجة الفعلية بتختلف من شخص لشخص.', NULL, NULL, 6, 1),
('fact', 'عملية التكميم بتقلل حجم المعدة حوالي 80%', NULL, NULL, NULL, 1, 1),
('fact', 'أغلب المرضى بيرجعوا لحياتهم الطبيعية خلال أسبوع لأسبوعين', NULL, NULL, NULL, 2, 1),
('fact', 'العملية بتتم بالمنظار من غير جرح كبير', NULL, NULL, NULL, 3, 1),
('fact', 'المتابعة بعد العملية جزء أساسي من النجاح', NULL, NULL, NULL, 4, 1),
('fact', 'نزول الوزن بيحسّن السكر والضغط عند كتير من المرضى', NULL, NULL, NULL, 5, 1);

INSERT INTO branches (name, address, map_url, map_embed, phone, working_hours, sort_order, is_active) VALUES
('عيادة دكرنس', 'شارع العروبة، بجوار عمر أفندي، أعلى زكي سنتر — دكرنس',
 'https://maps.google.com/?q=%D8%B2%D9%83%D9%8A+%D8%B3%D9%86%D8%AA%D8%B1+%D8%B4%D8%A7%D8%B1%D8%B9+%D8%A7%D9%84%D8%B9%D8%B1%D9%88%D8%A8%D8%A9+%D8%AF%D9%83%D8%B1%D9%86%D8%B3',
 'https://maps.google.com/maps?q=%D8%B4%D8%A7%D8%B1%D8%B9%20%D8%A7%D9%84%D8%B9%D8%B1%D9%88%D8%A8%D8%A9%20%D8%AF%D9%83%D8%B1%D9%86%D8%B3&z=15&output=embed',
 '', '', 1, 1);

-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — محرك المحتوى v3 · المرحلة G: التصميم + قوالب النص + مراجعة الصورة (Vision QA)
--   ce_text_templates  قوالب النص العربي اللي بيتحط فوق الصورة (الأدمن بيعدّلها ويضيف)
--   ce_designs         تصميم لكل خانة (والكاروسيل: لكل شريحة) — الصورة النضيفة + النهائية بالنص + تقييم الصورة
--   ai_task_routes     مرحلة توليد الصورة (ce_image) — بتتربط بموديل من «الموديلات ← خريطة المراحل»
--   الإعدادات          حدود مراجعة الصورة + عدد صور الكاروسيل + غلاف الريلز + أقل كريدت للصورة
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_ce_design_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ce_text_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tkey` VARCHAR(40) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `style_json` TEXT NOT NULL COMMENT 'layout · scrim · ألوان · مقاسات · الخطوط · الشعار',
  `formats` VARCHAR(60) NOT NULL DEFAULT 'post,carousel,reel',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_tt_key` (`tkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ce_text_templates` (`tkey`, `name`, `style_json`, `formats`, `sort_order`) VALUES
('clean_fade', 'تدرّج هادي', '{"layout":"auto","scrim":"fade","scrim_color":"dark","scrim_opacity":78,"text_color":"auto","align":"center","h_size":8,"s_size":4.2,"font_h":"Cairo-800","font_s":"Cairo-400","accent":1,"logo":"corner","band":36}', 'post,carousel,reel', 1),
('brand_band', 'شريط البراند', '{"layout":"auto","scrim":"solid","scrim_color":"primary","scrim_opacity":92,"text_color":"auto","align":"right","h_size":7.2,"s_size":4,"font_h":"Cairo-700","font_s":"Cairo-400","accent":0,"logo":"corner","band":30}', 'post,carousel,reel', 2),
('white_card', 'كارت أبيض', '{"layout":"auto","scrim":"card","scrim_color":"light","scrim_opacity":94,"text_color":"auto","align":"center","h_size":7,"s_size":4,"font_h":"Tajawal-800","font_s":"Tajawal-400","accent":1,"logo":"corner","band":34}', 'post,carousel,reel', 3),
('bold_center', 'عنوان كبير في النص', '{"layout":"center","scrim":"full","scrim_color":"dark","scrim_opacity":45,"text_color":"auto","align":"center","h_size":10,"s_size":4.6,"font_h":"Tajawal-800","font_s":"Tajawal-700","accent":0,"logo":"corner","band":50}', 'post,carousel,reel', 4);

CREATE TABLE IF NOT EXISTS `ce_designs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT UNSIGNED NOT NULL,
  `slot_id` INT UNSIGNED NOT NULL,
  `user_id` INT NOT NULL,
  `frame` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'الكاروسيل: رقم الشريحة · غيره 1',
  `kind` ENUM('ai','text') NOT NULL DEFAULT 'ai' COMMENT 'text = شريحة نص على خلفية البراند من غير صورة AI',
  `status` ENUM('pending','generating','pass','manual','failed') NOT NULL DEFAULT 'pending' COMMENT 'manual = الصورة ماعدّتش المراجعة بعد إعادتين',
  `aspect` VARCHAR(6) NOT NULL DEFAULT '1:1',
  `prompt` TEXT NULL,
  `negative` VARCHAR(500) NULL,
  `image_path` VARCHAR(255) NULL COMMENT 'الصورة النضيفة (من غير نص)',
  `final_path` VARCHAR(255) NULL COMMENT 'الصورة النهائية بالنص والشعار',
  `template_id` INT UNSIGNED NULL,
  `headline` VARCHAR(160) NULL,
  `subline` VARCHAR(200) NULL,
  `qa_json` TEXT NULL,
  `qa_score` DECIMAL(5,1) NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `model` VARCHAR(120) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_design_frame` (`slot_id`, `frame`),
  KEY `idx_ce_designs_campaign` (`campaign_id`),
  KEY `idx_ce_designs_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ai_task_routes` (`task`, `capability`, `label`, `max_attempts`, `time_budget_s`, `json_required`, `temperature`, `stage_group`, `sort_order`) VALUES
('ce_image', 'image', 'توليد صورة التصميم', 2, 180, 0, NULL, 'engine', 95);

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('ce_vision_max_regen', '2'),         -- إعادة توليد الصورة لو ماعدّتش المراجعة
('ce_vision_min_idea', '70'),         -- أقل درجة لمطابقة الفكرة
('ce_vision_min_palette', '60'),      -- أقل درجة لألوان البراند
('ce_carousel_ai_images', '0'),       -- كام شريحة في الكاروسيل بصورة AI (0 = كلها · غير كده الباقي نص على خلفية البراند)
('ce_reel_cover', '0'),               -- غلاف الريلز: الافتراضي في الـ Wizard (العميل بيختار)
('ce_min_credits_image', '1');        -- أقل كريدت للصورة الواحدة

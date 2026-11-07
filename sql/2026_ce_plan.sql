-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — محرك المحتوى v3 · المرحلة E: خطة المحتوى (Matrix) + بوابة الخطة
--   ce_angles        مكتبة الزوايا (الأدمن بيديرها)
--   ce_slots         خانات الخطة (الترتيب · التاريخ · المرحلة · العمود · الخدمة · العميل · الشكل · الزاوية · الـ CTA)
--   ce_plan_evals    تقييم الخطة في كل جولة (درجات الكود + الناقد + الملاحظات)
--   ce_campaigns     حالة «الخطة اتعتمدت» + درجة الخطة + حجز الخطة
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_ce_plan_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ce_angles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `akey` VARCHAR(40) NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `hint` VARCHAR(255) NULL COMMENT 'الزاوية دي معناها إيه (بتروح للـ AI)',
  `safeguard` VARCHAR(255) NULL COMMENT 'قيد لازم يتراعى (مثلًا قبل/بعد: بموافقة ومن غير وعود)',
  `pillars` VARCHAR(120) NULL COMMENT 'الأعمدة المناسبة (مفصولة بفاصلة) — فاضي = كلها',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ce_angles_key` (`akey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ce_angles` (`akey`, `label`, `hint`, `safeguard`, `pillars`, `sort_order`) VALUES
('myth_fact',      'خرافة وحقيقة',     'معلومة شائعة غلط والتصحيح بتاعها', NULL, 'education,proof', 1),
('common_mistake', 'غلطة شائعة',        'غلطة الناس بتعملها وإزاي تتجنبها', NULL, 'education,behind', 2),
('faq',            'سؤال بيتكرر',       'سؤال العملاء بيسألوه كتير وإجابته', NULL, 'education,offer', 3),
('patient_story',  'قصة عميل',          'تجربة عميل حقيقية من البداية للنتيجة', 'بموافقة العميل ومن غير بيانات شخصية', 'story,proof', 4),
('shock_number',   'رقم صادم',          'إحصائية أو رقم يلفت النظر', 'رقم حقيقي من مصدر — ماتألفش أرقام', 'education,proof', 5),
('comparison',     'مقارنة',            'مقارنة بين اختيارين أو قبل/بعد القرار', 'من غير ذكر منافسين بالاسم', 'education,offer', 6),
('parents_note',   'رسالة للأهل',       'كلام موجّه للأب والأم عن ولادهم', NULL, 'story,education', 7),
('day_in_life',    'يوم في المكان',     'كواليس يوم شغل حقيقي', NULL, 'behind,story', 8),
('quick_quiz',     'اختبار سريع',       'سؤال تفاعلي أو اختبار صغير', NULL, 'education,behind', 9),
('reply_comment',  'رد على تعليق',      'رد على سؤال أو تعليق من الجمهور', NULL, 'proof,education', 10),
('before_after',   'قبل وبعد',          'نتيجة حقيقية قبل وبعد', 'بموافقة مكتوبة · من غير وعود بنتيجة مضمونة · نتايج الأفراد بتختلف', 'proof,offer', 11);

CREATE TABLE IF NOT EXISTS `ce_slots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT UNSIGNED NOT NULL,
  `user_id` INT NOT NULL,
  `position` SMALLINT UNSIGNED NOT NULL,
  `slot_date` DATE NULL,
  `funnel` ENUM('tofu','mofu','bofu') NOT NULL DEFAULT 'tofu',
  `pillar` VARCHAR(20) NOT NULL,
  `service_id` INT NULL,
  `persona_id` INT NULL,
  `format` VARCHAR(20) NOT NULL DEFAULT 'post',
  `angle` VARCHAR(40) NOT NULL,
  `cta_level` ENUM('soft','medium','hard') NOT NULL DEFAULT 'soft',
  `objective` VARCHAR(255) NULL,
  `locked` TINYINT(1) NOT NULL DEFAULT 0,
  `source` ENUM('ai','rules','user') NOT NULL DEFAULT 'ai',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ce_slots_campaign` (`campaign_id`, `position`),
  KEY `idx_ce_slots_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ce_plan_evals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT UNSIGNED NOT NULL,
  `round` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `score` DECIMAL(5,1) NOT NULL DEFAULT 0,
  `passed` TINYINT(1) NOT NULL DEFAULT 0,
  `scores_json` TEXT NULL COMMENT 'لكل معيار: الكود · الناقد · النهائي',
  `issues_json` TEXT NULL COMMENT 'مخالفات القواعد اللي الكود لقاها',
  `critic_json` MEDIUMTEXT NULL COMMENT 'ملاحظات الناقد والإصلاحات المقترحة',
  `run_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ce_evals_campaign` (`campaign_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ce_campaigns: حالة «الخطة اتعتمدت» + الدرجة + تاريخ البداية
ALTER TABLE `ce_campaigns` MODIFY `status` ENUM('draft','ready','queued','planning','review','plan_ok','writing','done','cancelled') NOT NULL DEFAULT 'draft';

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ce_campaigns' AND column_name = 'plan_score');
SET @sql := IF(@c = 0, 'ALTER TABLE `ce_campaigns` ADD COLUMN `plan_score` DECIMAL(5,1) NULL, ADD COLUMN `plan_passed` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `start_date` DATE NULL, ADD COLUMN `plan_hold_id` BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE m0 FROM @sql; EXECUTE m0; DEALLOCATE PREPARE m0;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('ce_plan_threshold', '80'),         -- أقل درجة للخطة قبل الكتابة
('ce_plan_max_rounds', '2'),         -- عدد مرات إعادة البناء لو الدرجة أقل
('ce_plan_min_credits', '1');        -- أقل كريدت للخطة (لو التكلفة الفعلية أقل)

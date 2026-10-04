-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة ⑤-ب: طرق الاستوديو + المقاسات من الأدمن
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════
SET NAMES utf8mb4;

-- طرق Design Studio — الأساسية (built_in: المحرك ثابت، والأدمن يعدّل العرض) + طرق جديدة من الأدمن (custom)
CREATE TABLE IF NOT EXISTS `studio_methods` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mkey`        VARCHAR(40)  NOT NULL,
  `kind`        ENUM('built_in','custom') NOT NULL DEFAULT 'custom',
  `title`       VARCHAR(80)  NOT NULL,
  `description` VARCHAR(300) NULL,
  `badge`       VARCHAR(30)  NULL,
  `icon`        VARCHAR(30)  NOT NULL DEFAULT 'sparkles',
  `color`       VARCHAR(20)  NOT NULL DEFAULT 'blue',
  `sort_order`  INT          NOT NULL DEFAULT 100,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `config`      MEDIUMTEXT   NULL,          -- JSON: الصور المطلوبة · الخانة النصية · الاختيارات · قالب البرومبت · المقاس الافتراضي · الـ Brief
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mkey` (`mkey`),
  KEY `idx_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `studio_methods` (`mkey`, `kind`, `title`, `description`, `badge`, `icon`, `color`, `sort_order`, `config`) VALUES
('before_after', 'built_in', 'قبل / بعد', 'اعرض نتيجة حقيقية بتصميم احترافي — مثالي للعيادات والتجميل والعقارات.', 'مميز', 'split', 'sunset', 10, '{"ratio":"1:1"}'),
('from_image',   'built_in', 'من صورة', 'ارفع صورة منتجك، والـ AI يبني التصميم حواليها.', NULL, 'image', 'blue', 20,
    '{"ratio":"1:1","purposes":["إعلان منتج","عرض أو خصم","تعريف بخدمة","نتيجة لعميل","منشور تفاعلي"]}'),
('free',         'built_in', 'اكتب فكرتك', 'اكتب بالعربي زي ما بتتكلم، والباقي علينا.', NULL, 'bulb', 'teal', 30, '{"ratio":"1:1"}'),
('pro',          'built_in', 'Prompt احترافي', 'للمحترفين — والهوية بتتضاف للـ Prompt تلقائيًا.', NULL, 'code', 'dark', 40, '{"ratio":"1:1"}'),
('trend',        'built_in', 'Trend Studio', 'ترندات مختارة من فريقنا، متفصّلة على براندك وجمهورك — مش نسخ.', 'جديد', 'trend', 'violet', 50, '{"ratio":"1:1"}');

-- المقاسات: NULL = الافتراضي في الكود. الأدمن بيحفظ JSON هنا.
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('design_ratios_json', '');

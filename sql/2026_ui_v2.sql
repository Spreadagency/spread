-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — الواجهة الجديدة (المرحلة 0)
-- مفتاح تفعيل: الواجهة الجديدة بتشتغل جنب القديمة
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════
SET NAMES utf8mb4;

-- تفضيل كل مستخدم: auto = حسب إعداد المنصة · v2 = الجديدة · v1 = القديمة
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'ui_pref');
SET @sql := IF(@c = 0,
  'ALTER TABLE `users` ADD COLUMN `ui_pref` ENUM("auto","v2","v1") NOT NULL DEFAULT "auto"',
  'SELECT 1');
PREPARE u1 FROM @sql; EXECUTE u1; DEALLOCATE PREPARE u1;

-- وضع المنصة:
--   off   = الواجهة القديمة للكل
--   optin = القديمة افتراضيًا، واللي عايز يجرّب يفعّلها من ملفه
--   all   = الجديدة للكل (ويقدر أي حد يرجع للقديمة)
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('ui_v2_mode',     'optin'),
('ui_v2_thinking', '1');

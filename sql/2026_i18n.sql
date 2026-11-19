-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الترجمة · المرحلة 2: لغة الواجهة لكل عميل (العربي أو الإنجليزي)
--   users.locale: اختيار العميل من زرار «ع | EN» — بيترجع معاه على أي جهاز يدخل منه،
--   والإيميلات والرسائل بتتبعت بيه بعدين. NULL = العربي (زي دلوقتي).
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_i18n_rollback.sql
-- ═══════════════════════════════════════════════════════════════════
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'locale');
SET @q := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `locale` VARCHAR(5) NULL DEFAULT NULL COMMENT ''لغة الواجهة: ar · en — NULL = العربي''', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

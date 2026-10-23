-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — Content Engine v3 · المرحلة B: إصلاحات المراجعة (docs/CONTENT_ENGINE_AUDIT.md)
--   ① payment_orders.paymob_order_id بقى UNIQUE (لو مفيش تكرار في البيانات الحالية)
--   ② السجل المالي مايتمسحش مع حذف العميل: credit_transactions / payment_orders → ON DELETE SET NULL
--   ③ فهارس للوحة الأدمن والمكتبة (من مراجعة الأداء)
-- آمن للتشغيل أكتر من مرة · مابيمسحش ولا يغيّر أي صف · الرجوع: 2026_ce_fixes_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

-- ① UNIQUE على رقم طلب Paymob (بس لو مفيش تكرار — غير كده بيتساب ويتسجل تحذير في «فحص التحديث»)
SET @dup := (SELECT COUNT(*) - COUNT(DISTINCT paymob_order_id) FROM payment_orders WHERE paymob_order_id IS NOT NULL AND paymob_order_id <> '');
SET @has := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payment_orders' AND index_name = 'uq_paymob');
SET @sql := IF(@has = 0 AND @dup = 0, 'ALTER TABLE `payment_orders` ADD UNIQUE KEY `uq_paymob` (`paymob_order_id`)', 'SELECT 1');
PREPARE cf0 FROM @sql; EXECUTE cf0; DEALLOCATE PREPARE cf0;

-- ② credit_transactions: السجل يفضل لو العميل اتمسح (user_id بيبقى NULL)
SET @rule := (SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_transactions' AND CONSTRAINT_NAME = 'fk_ct_user');
SET @sql := IF(@rule = 'CASCADE', 'ALTER TABLE `credit_transactions` DROP FOREIGN KEY `fk_ct_user`', 'SELECT 1');
PREPARE cf1 FROM @sql; EXECUTE cf1; DEALLOCATE PREPARE cf1;
SET @sql := IF(@rule = 'CASCADE', 'ALTER TABLE `credit_transactions` MODIFY `user_id` INT NULL', 'SELECT 1');
PREPARE cf2 FROM @sql; EXECUTE cf2; DEALLOCATE PREPARE cf2;
SET @sql := IF(@rule = 'CASCADE', 'ALTER TABLE `credit_transactions` ADD CONSTRAINT `fk_ct_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE cf3 FROM @sql; EXECUTE cf3; DEALLOCATE PREPARE cf3;

-- ② payment_orders: السجل يفضل لو العميل اتمسح (user_id بيبقى NULL)
SET @rule := (SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_orders' AND CONSTRAINT_NAME = 'fk_po_user');
SET @sql := IF(@rule = 'CASCADE', 'ALTER TABLE `payment_orders` DROP FOREIGN KEY `fk_po_user`', 'SELECT 1');
PREPARE cf4 FROM @sql; EXECUTE cf4; DEALLOCATE PREPARE cf4;
SET @sql := IF(@rule = 'CASCADE', 'ALTER TABLE `payment_orders` MODIFY `user_id` INT NULL', 'SELECT 1');
PREPARE cf5 FROM @sql; EXECUTE cf5; DEALLOCATE PREPARE cf5;
SET @sql := IF(@rule = 'CASCADE', 'ALTER TABLE `payment_orders` ADD CONSTRAINT `fk_po_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE cf6 FROM @sql; EXECUTE cf6; DEALLOCATE PREPARE cf6;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'contents' AND index_name = 'idx_contents_user_upd');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents` ADD INDEX `idx_contents_user_upd` (`user_id`, `updated_at`)', 'SELECT 1');
PREPARE cf7 FROM @sql; EXECUTE cf7; DEALLOCATE PREPARE cf7;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'contents' AND index_name = 'idx_contents_created');
SET @sql := IF(@c = 0, 'ALTER TABLE `contents` ADD INDEX `idx_contents_created` (`created_at`)', 'SELECT 1');
PREPARE cf8 FROM @sql; EXECUTE cf8; DEALLOCATE PREPARE cf8;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'content_designs' AND index_name = 'idx_cd_created');
SET @sql := IF(@c = 0, 'ALTER TABLE `content_designs` ADD INDEX `idx_cd_created` (`created_at`)', 'SELECT 1');
PREPARE cf9 FROM @sql; EXECUTE cf9; DEALLOCATE PREPARE cf9;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_created');
SET @sql := IF(@c = 0, 'ALTER TABLE `users` ADD INDEX `idx_users_created` (`created_at`)', 'SELECT 1');
PREPARE cf10 FROM @sql; EXECUTE cf10; DEALLOCATE PREPARE cf10;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'credit_transactions' AND index_name = 'idx_ct_action_date');
SET @sql := IF(@c = 0, 'ALTER TABLE `credit_transactions` ADD INDEX `idx_ct_action_date` (`action_type`, `created_at`)', 'SELECT 1');
PREPARE cf11 FROM @sql; EXECUTE cf11; DEALLOCATE PREPARE cf11;

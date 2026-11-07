-- الرجوع عن 2026_ce_fixes.sql (الفهارس + UNIQUE). الـ FKs بترجع CASCADE بس لو مفيش صفوف user_id = NULL (يعني محدش اتمسح بعد الترحيل).

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'contents' AND index_name = 'idx_contents_user_upd');
SET @sql := IF(@c > 0, 'ALTER TABLE `contents` DROP INDEX `idx_contents_user_upd`', 'SELECT 1');
PREPARE rf1 FROM @sql; EXECUTE rf1; DEALLOCATE PREPARE rf1;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'contents' AND index_name = 'idx_contents_created');
SET @sql := IF(@c > 0, 'ALTER TABLE `contents` DROP INDEX `idx_contents_created`', 'SELECT 1');
PREPARE rf2 FROM @sql; EXECUTE rf2; DEALLOCATE PREPARE rf2;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'content_designs' AND index_name = 'idx_cd_created');
SET @sql := IF(@c > 0, 'ALTER TABLE `content_designs` DROP INDEX `idx_cd_created`', 'SELECT 1');
PREPARE rf3 FROM @sql; EXECUTE rf3; DEALLOCATE PREPARE rf3;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_created');
SET @sql := IF(@c > 0, 'ALTER TABLE `users` DROP INDEX `idx_users_created`', 'SELECT 1');
PREPARE rf4 FROM @sql; EXECUTE rf4; DEALLOCATE PREPARE rf4;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'credit_transactions' AND index_name = 'idx_ct_action_date');
SET @sql := IF(@c > 0, 'ALTER TABLE `credit_transactions` DROP INDEX `idx_ct_action_date`', 'SELECT 1');
PREPARE rf5 FROM @sql; EXECUTE rf5; DEALLOCATE PREPARE rf5;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payment_orders' AND index_name = 'uq_paymob');
SET @sql := IF(@c > 0, 'ALTER TABLE `payment_orders` DROP INDEX `uq_paymob`', 'SELECT 1');
PREPARE rf6 FROM @sql; EXECUTE rf6; DEALLOCATE PREPARE rf6;

SET @nul := (SELECT COUNT(*) FROM `credit_transactions` WHERE `user_id` IS NULL);
SET @rule := (SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_transactions' AND CONSTRAINT_NAME = 'fk_ct_user');
SET @sql := IF(@rule = 'SET NULL' AND @nul = 0, 'ALTER TABLE `credit_transactions` DROP FOREIGN KEY `fk_ct_user`', 'SELECT 1');
PREPARE r7 FROM @sql; EXECUTE r7; DEALLOCATE PREPARE r7;
SET @sql := IF(@rule = 'SET NULL' AND @nul = 0, 'ALTER TABLE `credit_transactions` MODIFY `user_id` INT NOT NULL', 'SELECT 1');
PREPARE r8 FROM @sql; EXECUTE r8; DEALLOCATE PREPARE r8;
SET @sql := IF(@rule = 'SET NULL' AND @nul = 0, 'ALTER TABLE `credit_transactions` ADD CONSTRAINT `fk_ct_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE', 'SELECT 1');
PREPARE r9 FROM @sql; EXECUTE r9; DEALLOCATE PREPARE r9;

SET @nul := (SELECT COUNT(*) FROM `payment_orders` WHERE `user_id` IS NULL);
SET @rule := (SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_orders' AND CONSTRAINT_NAME = 'fk_po_user');
SET @sql := IF(@rule = 'SET NULL' AND @nul = 0, 'ALTER TABLE `payment_orders` DROP FOREIGN KEY `fk_po_user`', 'SELECT 1');
PREPARE r10 FROM @sql; EXECUTE r10; DEALLOCATE PREPARE r10;
SET @sql := IF(@rule = 'SET NULL' AND @nul = 0, 'ALTER TABLE `payment_orders` MODIFY `user_id` INT NOT NULL', 'SELECT 1');
PREPARE r11 FROM @sql; EXECUTE r11; DEALLOCATE PREPARE r11;
SET @sql := IF(@rule = 'SET NULL' AND @nul = 0, 'ALTER TABLE `payment_orders` ADD CONSTRAINT `fk_po_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE', 'SELECT 1');
PREPARE r12 FROM @sql; EXECUTE r12; DEALLOCATE PREPARE r12;

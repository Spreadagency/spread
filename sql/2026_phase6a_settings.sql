-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة ⑥-أ: مركز الإعدادات
--   users: آخر تغيير لكلمة المرور · التحقق بخطوتين · طلب حذف الحساب (مهلة 14 يوم)
--   user_sessions: الجلسات النشطة (الجهاز · المكان · آخر نشاط) + إنهاء جلسة من جهاز تاني
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'password_changed_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME NULL', 'SELECT 1');
PREPARE p1 FROM @sql; EXECUTE p1; DEALLOCATE PREPARE p1;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'two_fa_enabled');
SET @sql := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `two_fa_enabled` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE p2 FROM @sql; EXECUTE p2; DEALLOCATE PREPARE p2;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'deletion_requested_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `deletion_requested_at` DATETIME NULL, ADD KEY `idx_users_deletion` (`deletion_requested_at`)', 'SELECT 1');
PREPARE p3 FROM @sql; EXECUTE p3; DEALLOCATE PREPARE p3;

CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT NOT NULL,
  `sid_hash`     CHAR(64) NOT NULL,
  `user_agent`   VARCHAR(255) NULL,
  `ip`           VARCHAR(45) NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sid` (`sid_hash`),
  KEY `idx_us_user` (`user_id`, `revoked_at`, `last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

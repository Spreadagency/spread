-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — تسجيل الدخول بجوجل
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════
SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'google_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `users`
  ADD COLUMN `google_id`     VARCHAR(40) NULL,
  ADD COLUMN `avatar_url`    VARCHAR(500) NULL,
  ADD COLUMN `auth_provider` ENUM("password","google","both") NOT NULL DEFAULT "password",
  ADD UNIQUE KEY `uq_google_id` (`google_id`)', 'SELECT 1');
PREPARE g1 FROM @sql; EXECUTE g1; DEALLOCATE PREPARE g1;

-- كلمة المرور تبقى اختيارية عشان حسابات جوجل
SET @c2 := (SELECT IS_NULLABLE FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'password');
SET @sql2 := IF(@c2 = 'NO', 'ALTER TABLE `users` MODIFY `password` VARCHAR(255) NULL', 'SELECT 1');
PREPARE g2 FROM @sql2; EXECUTE g2; DEALLOCATE PREPARE g2;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('google_login_enabled',   '0'),
('google_client_id',       ''),
('google_client_secret_enc', ''),
('google_auto_approve',    '1');

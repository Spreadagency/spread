-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — صفحة «باقتي» الموحدة + اختيار مكافأة «ادع واكسب»
--   referral_prefs: العميل بيختار مكافأته (كريدت · تصميمات · خصم على الاشتراك) — الخيارات وقيمها من الأدمن
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_plan_page_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `referral_prefs` (
  `user_id` INT NOT NULL,
  `reward` VARCHAR(10) NOT NULL DEFAULT 'credits' COMMENT 'credits · designs · discount',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- المُحيل بياخد مكافأة عن كل صاحب بيفعّل (قبل كده المفتاح الفريد كان بيسمح بمكافأة واحدة للمُحيل طول عمره)
--   referral_id = رقم الإحالة لمكافأة المُحيل · 0 لهدية المستخدم الجديد (بتفضل مرة واحدة زي ما هي)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'offer_redemptions' AND COLUMN_NAME = 'referral_id');
SET @q := IF(@c = 0, 'ALTER TABLE `offer_redemptions`
  ADD COLUMN `referral_id` INT NOT NULL DEFAULT 0 AFTER `role`,
  DROP INDEX `uq_offer_user`, ADD UNIQUE KEY `uq_offer_user` (`offer_id`, `user_id`, `role`, `referral_id`),
  DROP INDEX `uq_offer_identity`, ADD UNIQUE KEY `uq_offer_identity` (`offer_id`, `identity_hash`, `role`, `referral_id`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('plan_page_unified', '1'),             -- الباقات والرصيد وادع واكسب في صفحة واحدة («باقتي»)
('referral_reward_credits_on', '1'),    -- المكافأة كريدت متاحة (القيمة من «مكافأة المُحيل»)
('referral_reward_designs', '0'),       -- المكافأة تصميمات: العدد (0 = مش متاحة)
('referral_reward_designs_days', '60'), -- صلاحية التصميمات (لو مفيش باقة شغالة)
('referral_reward_discount_pct', '0'),  -- المكافأة خصم على الاشتراك: النسبة % (0 = مش متاحة)
('referral_reward_discount_days', '60');-- صلاحية كوبون الخصم

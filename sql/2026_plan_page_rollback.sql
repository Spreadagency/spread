-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_plan_page.sql (الكوبونات اللي اتعملت بتفضل في العروض)
-- ═══════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS `referral_prefs`;
DELETE FROM `settings` WHERE `setting_key` IN ('plan_page_unified', 'referral_reward_credits_on', 'referral_reward_designs', 'referral_reward_designs_days', 'referral_reward_discount_pct', 'referral_reward_discount_days');
-- ⚠️ المفاتيح القديمة (مكافأة واحدة للمُحيل) مش هترجع لو المُحيل خد أكتر من مكافأة — امسح التكرار الأول لو محتاج
ALTER TABLE `offer_redemptions`
  DROP INDEX `uq_offer_user`, ADD UNIQUE KEY `uq_offer_user` (`offer_id`, `user_id`, `role`),
  DROP INDEX `uq_offer_identity`, ADD UNIQUE KEY `uq_offer_identity` (`offer_id`, `identity_hash`, `role`),
  DROP COLUMN `referral_id`;

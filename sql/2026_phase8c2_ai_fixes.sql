-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — إصلاح 8-ج (2): تكلفة المحاولات المرفوضة
--   الطلب اللي المزود رفضه (HTTP 4xx: مفتاح غلط · باراميتر مش مدعوم · حد) مابيتحاسبش عليه —
--   كان بيتحسب له تكلفة تقديرية. هنا بنصفّرها ونعيد حساب إجمالي العمليات المتأثرة.
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════

UPDATE `ai_attempts` SET `http_code` = CAST(SUBSTRING(`error`, LOCATE('HTTP ', `error`) + 5, 3) AS UNSIGNED)
 WHERE `status` = 'failed' AND `http_code` IS NULL AND `error` REGEXP 'HTTP [0-9]{3}';

UPDATE `ai_attempts` SET `cost_usd` = 0, `cost_egp` = 0, `cost_source` = 'none', `price_ref` = NULL
 WHERE `status` = 'failed' AND `http_code` BETWEEN 400 AND 499 AND `cost_source` <> 'provider' AND `cost_usd` > 0;

UPDATE `ai_runs` r
  JOIN (SELECT `run_id`, COALESCE(SUM(`cost_usd`), 0) c, COALESCE(SUM(`cost_egp`), 0) ce, COALESCE(SUM(IF(`status` = 'failed', `cost_usd`, 0)), 0) cf
          FROM `ai_attempts` GROUP BY `run_id`) a ON a.`run_id` = r.`id`
   SET r.`cost_usd` = a.c, r.`cost_egp` = a.ce, r.`cost_failed_usd` = a.cf
 WHERE r.`id` IN (SELECT DISTINCT `run_id` FROM `ai_attempts` WHERE `status` = 'failed' AND `http_code` BETWEEN 400 AND 499)
   AND (r.`cost_usd` <> a.c OR r.`cost_failed_usd` <> a.cf);

-- ═══════════════════════════════════════════════════════════════════
-- الرجوع عن 2026_mcp_ai_db.sql — بيتشغّل على قاعدة الـ AI بس (مش قاعدة المنصة)
-- ⚠️ بيمسح كل التقييمات والتقارير والمسودات وسجل الـ MCP — خد نسخة احتياطية الأول
-- قاعدة المنصة مابتتأثرش خالص.
-- ═══════════════════════════════════════════════════════════════════

DROP TABLE IF EXISTS `mcp_audit_log`;
DROP TABLE IF EXISTS `e2e_runs`;
DROP TABLE IF EXISTS `integrity_issues`;
DROP TABLE IF EXISTS `security_findings`;
DROP TABLE IF EXISTS `ai_reports`;
DROP TABLE IF EXISTS `sandbox_runs`;
DROP TABLE IF EXISTS `style_preset_drafts`;
DROP TABLE IF EXISTS `prompt_drafts`;
DROP TABLE IF EXISTS `change_requests`;
DROP TABLE IF EXISTS `reply_drafts`;
DROP TABLE IF EXISTS `issue_analysis`;
DROP TABLE IF EXISTS `ai_evaluations`;

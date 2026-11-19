#!/usr/bin/env node
/**
 * Spread AI — بيبعت نتايج الفحوصات اللي اشتغلت على الـ VPS للموقع (Internal API · ai_write) — MCP المرحلة 8
 * بتظهر في «مركز الجودة ← الأمان»، وClaude بيقراها (spread_get_e2e_results · spread_dependency_audit · spread_security_scan).
 *
 *   node scripts/push-checks.mjs e2e  e2e-results.json     (من checks/site-e2e.mjs)
 *   node scripts/push-checks.mjs zap  zap-report.json      (OWASP ZAP baseline: -J zap-report.json)
 *   node scripts/push-checks.mjs npm  npm-audit.json       (npm audit --json)
 *
 * بياخد INTERNAL_API_URL و INTERNAL_API_TOKEN من .env (نفس بتوع الخادم). مابيحذفش حاجة:
 * في zap/npm اللي كانت مفتوحة ومش موجودة في التقرير الجديد بتتعلّم «اتصلّحت».
 */
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

const envFile = resolve(process.env.ENV_FILE ?? ".env");
if (existsSync(envFile)) process.loadEnvFile(envFile);

const [mode, file] = process.argv.slice(2);
const API = (process.env.INTERNAL_API_URL ?? "").replace(/\/+$/, "");
const TOKEN = process.env.INTERNAL_API_TOKEN ?? "";
if (!["e2e", "zap", "npm"].includes(mode) || !file || !existsSync(file)) {
  console.error("الاستخدام: node scripts/push-checks.mjs e2e|zap|npm report.json");
  process.exit(2);
}
if (!API || TOKEN.length < 32) {
  console.error("INTERNAL_API_URL / INTERNAL_API_TOKEN ناقصين — شغّله من فولدر الخادم (اللي فيه .env)");
  process.exit(2);
}

const json = JSON.parse(readFileSync(file, "utf8"));
const strip = s => String(s ?? "").replace(/<[^>]+>/g, "").trim();

async function post(route, body) {
  const url = `${API}/index.php?r=${encodeURIComponent(route)}`;
  const r = await fetch(url, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      "X-Internal-Token": TOKEN,
      "X-Scope": "ai_write",
      "X-Client": "vps-checks",
      "Content-Type": "application/json",
      Accept: "application/json",
      "User-Agent": "spread-mcp-checks/1.0",
    },
    body: JSON.stringify(body),
    redirect: "error",
    signal: AbortSignal.timeout(60000),
  });
  const out = await r.json().catch(() => ({}));
  if (!r.ok || out.ok === false) {
    console.error(`✕ ${route}: HTTP ${r.status} — ${out.error?.message ?? out.message ?? "رد مش مفهوم"}`);
    process.exit(1);
  }
  return out;
}

if (mode === "e2e") {
  const results = Array.isArray(json.results) ? json.results : [];
  if (!results.length) { console.error("الملف مافيهوش results"); process.exit(1); }
  const out = await post("save_e2e_results", { run_at: json.run_at, results: results.slice(0, 500) });
  console.log(`✓ اختبار الموقع: اتسجّل ${out.saved ?? out.data?.saved} · فشل ${out.failed ?? out.data?.failed ?? 0}`);
} else {
  const findings = [];
  if (mode === "zap") {
    const risk = { 0: "info", 1: "low", 2: "medium", 3: "high" };
    for (const site of json.site ?? []) {
      for (const a of site.alerts ?? []) {
        const uri = a.instances?.[0]?.uri ?? site["@name"] ?? "";
        let path = "/";
        try { path = new URL(uri).pathname; } catch {}
        findings.push({
          title: a.name ?? a.alert ?? "ZAP",
          severity: risk[String(a.riskcode ?? "0")] ?? "info",
          location: `zap:${a.pluginid ?? ""}:${path}`,
          description: `${strip(a.desc)}\nأماكن: ${(a.instances ?? []).length} · مثال: ${uri}`,
          recommendation: strip(a.solution),
        });
      }
    }
  } else {
    const map = { critical: "critical", high: "high", moderate: "medium", low: "low", info: "info" };
    for (const [name, v] of Object.entries(json.vulnerabilities ?? {})) {
      const via = (v.via ?? []).filter(x => x && typeof x === "object");
      findings.push({
        title: `مكتبة فيها ثغرة: ${name}`,
        severity: map[v.severity] ?? "low",
        location: `npm:${name}`,
        description: `${via[0]?.title ?? ""} ${via[0]?.url ?? ""} · الإصدارات: ${v.range ?? ""}`.trim(),
        recommendation: v.fixAvailable ? "npm audit fix (أو حدّث المكتبة) ← npm run build ← restart" : "مفيش تحديث لسه — راقبها أو استبدل المكتبة",
      });
    }
  }
  const out = await post("sync_security_findings", { source: mode === "zap" ? "zap" : "dependency", findings: findings.slice(0, 300) });
  console.log(`✓ ${mode}: ${findings.length} ملاحظة · ${out.marked_fixed ?? out.data?.marked_fixed ?? 0} اتعلّمت «اتصلّحت»`);
}

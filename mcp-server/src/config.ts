/**
 * الإعدادات من .env (أو متغيرات البيئة من systemd). مفيش ولا سر مكتوب في الكود.
 */
import { existsSync } from "node:fs";
import { resolve } from "node:path";

const envFile = resolve(process.env.ENV_FILE ?? ".env");
if (existsSync(envFile)) process.loadEnvFile(envFile);

function req(name: string, minLen = 1): string {
  const v = (process.env[name] ?? "").trim();
  if (v.length < minLen) {
    console.error(`[config] ${name} ناقص${minLen > 1 ? ` (لازم ${minLen} حرف على الأقل)` : ""} — راجع .env.example`);
    process.exit(1);
  }
  return v;
}

function list(name: string, fallback = ""): string[] {
  return (process.env[name] ?? fallback).split(",").map(s => s.trim()).filter(Boolean);
}

function bool(name: string, fallback: boolean): boolean {
  const v = (process.env[name] ?? "").trim().toLowerCase();
  if (v === "") return fallback;
  return ["1", "true", "yes", "on"].includes(v);
}

const transport = (process.env.TRANSPORT ?? "http").trim() === "stdio" ? "stdio" : "http";
const publicUrl = new URL((process.env.MCP_PUBLIC_URL ?? "http://localhost:3000").trim().replace(/\/+$/, ""));

export const config = {
  transport,
  host: (process.env.HOST ?? "127.0.0.1").trim(),
  port: Number(process.env.PORT ?? 3000),
  /** العنوان العام (من غير /mcp) — هو الـ issuer والـ resource بتوع OAuth */
  publicUrl,
  mcpUrl: new URL("/mcp", publicUrl),

  /** الـ Internal API بتاع الموقع (PHP) — الخادم ده مابيكلمش MySQL أبدًا */
  internalApiUrl: req("INTERNAL_API_URL").replace(/\/+$/, ""),
  internalApiToken: req("INTERNAL_API_TOKEN", 32),
  internalApiTimeoutMs: Number(process.env.INTERNAL_API_TIMEOUT_MS ?? 30000),

  /** OAuth 2.1 (موصّل Claude) */
  ownerPasswordHash: transport === "http" ? (process.env.MCP_OWNER_PASSWORD_HASH ?? "").trim() : "",
  jwtSecret: transport === "http" ? req("MCP_JWT_SECRET", 32) : "",
  accessTokenTtl: Number(process.env.MCP_ACCESS_TOKEN_TTL ?? 3600),
  refreshTokenTtl: Number(process.env.MCP_REFRESH_TOKEN_TTL ?? 30 * 86400),
  allowDcr: bool("MCP_ALLOW_DCR", true),
  allowedRedirectHosts: list("MCP_ALLOWED_REDIRECT_HOSTS", "claude.ai,claude.com,localhost,127.0.0.1"),
  staticClientId: (process.env.MCP_OAUTH_CLIENT_ID ?? "").trim(),
  staticClientSecret: (process.env.MCP_OAUTH_CLIENT_SECRET ?? "").trim(),
  dataDir: resolve(process.env.MCP_DATA_DIR ?? "data"),

  /** Bearer ثابت (Claude Code · المهام المجدولة · الاختبار) — كل واحد 32 حرف على الأقل */
  staticTokens: list("MCP_STATIC_TOKENS").filter(t => t.length >= 32),

  /** أدوات المسودات بتتفعّل في المرحلة 6 (لما صفحات الموافقة تبقى جاهزة) */
  enableDraftTools: bool("MCP_ENABLE_DRAFT_TOOLS", false),

  requestsPerMinute: Number(process.env.MCP_RATE_PER_MIN ?? 240),
  trustProxy: (process.env.TRUST_PROXY ?? "loopback").trim(),
};

if (list("MCP_STATIC_TOKENS").some(t => t.length < 32)) {
  console.error("[config] فيه توكن في MCP_STATIC_TOKENS أقل من 32 حرف — اتجاهل");
}
if (config.transport === "http" && !config.ownerPasswordHash && !config.staticTokens.length) {
  console.error("[config] لازم MCP_OWNER_PASSWORD_HASH (لموصّل Claude) أو MCP_STATIC_TOKENS — من غيرهم محدش يقدر يدخل");
  process.exit(1);
}
if (config.transport === "http" && config.publicUrl.protocol !== "https:" && !["localhost", "127.0.0.1"].includes(config.publicUrl.hostname)) {
  console.error("[config] MCP_PUBLIC_URL لازم يبقى https");
  process.exit(1);
}

/** الصلاحيات (OAuth scopes) — كل واحدة بتفتح مجموعة أدوات */
export const SCOPES = {
  read: "spread:read",
  evaluate: "spread:evaluate",
  draft: "spread:draft",
} as const;
export const ALL_SCOPES: string[] = [SCOPES.read, SCOPES.evaluate, SCOPES.draft];

/**
 * OAuth 2.1 لموصّل Claude: الخادم هو الـ Authorization Server والـ Resource Server في نفس الوقت.
 *
 * - Authorization Code + PKCE (S256 بس) · Refresh tokens بتتغيّر مع كل استخدام (واستخدامها مرتين بيلغي العيلة كلها)
 * - Dynamic Client Registration (RFC 7591) — بس الـ redirect لازم يكون على دومين مسموح (claude.ai افتراضيًا)
 * - الموافقة بباسورد صاحب الموقع (scrypt) + قفل بعد محاولات غلط
 * - Access token = JWT HS256 لمدة ساعة، للـ resource ده بس (aud = /mcp)
 * - + Bearer ثابت (MCP_STATIC_TOKENS) لـ Claude Code والاختبار
 */
import express, { type Express, type Request, type Response } from "express";
import { mcpAuthMetadataRouter } from "@modelcontextprotocol/express";
import { OAuthError, OAuthErrorCode, type AuthInfo, type OAuthTokenVerifier } from "@modelcontextprotocol/server";
import { ALL_SCOPES, config, SCOPES } from "../config.js";
import { count, hit } from "../ratelimit.js";
import { b64url, hmac, pkceOk, randomToken, safeEqual, sha256hex, signJwt, verifyJwt, verifyPassword } from "./crypto.js";
import * as store from "./store.js";

export const issuer = config.publicUrl.href.replace(/\/$/, "");
const resource = config.mcpUrl.href;
const now = () => Math.floor(Date.now() / 1000);

const SCOPE_LABELS: Record<string, string> = {
  [SCOPES.read]: "قراءة الأرقام والمنشورات والهوية والبلاغات والأمان (من غير بيانات شخصية كاملة)",
  [SCOPES.evaluate]: "حفظ التقييمات والتقارير وتحليل البلاغات (في قاعدة الـ AI بس)",
  [SCOPES.draft]: "إضافة مسودات واقتراحات (ماتتطبقش غير بموافقة الأدمن)",
};

/* ═══════════ العملاء ═══════════ */

const staticClient: store.Client | null =
  config.staticClientId && config.staticClientSecret.length >= 32
    ? {
        client_id: config.staticClientId,
        client_secret_hash: sha256hex(config.staticClientSecret),
        client_name: "Claude (عميل ثابت)",
        redirect_uris: [],
        token_endpoint_auth_method: "client_secret_post",
        created_at: 0,
        static: true,
      }
    : null;

function findClient(id: unknown): store.Client | undefined {
  if (typeof id !== "string" || id === "") return undefined;
  if (staticClient && id === staticClient.client_id) return staticClient;
  return store.getClient(id);
}

const LOCAL = ["localhost", "127.0.0.1", "[::1]"];

function redirectAllowed(uri: string): boolean {
  let u: URL;
  try { u = new URL(uri); } catch { return false; }
  if (u.hash || u.username || u.password) return false;
  const local = LOCAL.includes(u.hostname);
  if (u.protocol !== "https:" && !(local && u.protocol === "http:")) return false;
  return config.allowedRedirectHosts.some(h => u.hostname === h || u.hostname.endsWith(`.${h}`));
}

function redirectMatches(c: store.Client, uri: string): boolean {
  if (!redirectAllowed(uri)) return false;
  if (c.static) return true;
  // localhost: البورت بيتغيّر (RFC 8252) — نفس المسار والدومين كفاية
  return c.redirect_uris.some(r => {
    if (r === uri) return true;
    try {
      const a = new URL(r), b = new URL(uri);
      return LOCAL.includes(a.hostname) && a.hostname === b.hostname && a.pathname === b.pathname && a.protocol === b.protocol;
    } catch { return false; }
  });
}

/** العميل بيثبت نفسه في /token (لو عنده secret) */
function authClient(req: Request): store.Client | null {
  let id = req.body?.client_id as string | undefined;
  let secret = req.body?.client_secret as string | undefined;
  const h = req.headers.authorization ?? "";
  if (h.startsWith("Basic ")) {
    const raw = Buffer.from(h.slice(6), "base64").toString();
    const i = raw.indexOf(":");
    if (i > 0) { id = decodeURIComponent(raw.slice(0, i)); secret = decodeURIComponent(raw.slice(i + 1)); }
  }
  const c = findClient(id);
  if (!c) return null;
  if (c.token_endpoint_auth_method !== "none") {
    if (!secret || !c.client_secret_hash || !safeEqual(sha256hex(secret), c.client_secret_hash)) return null;
  }
  return c;
}

function parseScopes(raw: unknown): string[] {
  const asked = (typeof raw === "string" ? raw.split(/\s+/) : Array.isArray(raw) ? raw.map(String) : []).filter(Boolean);
  const s = asked.length ? asked.filter(x => ALL_SCOPES.includes(x)) : [...ALL_SCOPES];
  return [...new Set(s)];
}

function sameResource(r: unknown): boolean {
  if (r === undefined || r === "") return true;
  const norm = (s: string) => s.replace(/#.*$/, "").replace(/\/$/, "");
  return typeof r === "string" && (norm(r) === norm(resource) || norm(r) === norm(issuer));
}

/* ═══════════ التوكنات ═══════════ */

function issueTokens(clientId: string, scope: string[], family?: string) {
  const t = now();
  const access = signJwt(
    { iss: issuer, aud: resource, sub: "owner", client_id: clientId, scope: scope.join(" "), iat: t, exp: t + config.accessTokenTtl, jti: randomToken(12), gen: store.gen() },
    config.jwtSecret,
  );
  const refresh = randomToken(32);
  store.addRefresh(sha256hex(refresh), { client_id: clientId, scope, exp: t + config.refreshTokenTtl, family: family ?? randomToken(12), used: false });
  return { access_token: access, token_type: "Bearer", expires_in: config.accessTokenTtl, refresh_token: refresh, scope: scope.join(" ") };
}

export const verifier: OAuthTokenVerifier = {
  async verifyAccessToken(token: string): Promise<AuthInfo> {
    const t = now();
    const idx = config.staticTokens.findIndex(s => safeEqual(s, token));
    if (idx >= 0) return { token, clientId: `static-${idx + 1}`, scopes: [...ALL_SCOPES], expiresAt: t + 3600, resource: config.mcpUrl };
    const c = config.jwtSecret ? verifyJwt(token, config.jwtSecret) : null;
    if (!c || c.iss !== issuer || c.aud !== resource || typeof c.exp !== "number" || c.exp < t || c.gen !== store.gen()) {
      throw new OAuthError(OAuthErrorCode.InvalidToken, "Invalid or expired token — reconnect the Spread connector");
    }
    return { token, clientId: String(c.client_id), scopes: String(c.scope ?? "").split(" ").filter(Boolean), expiresAt: c.exp, resource: new URL(String(c.aud)) };
  },
};

/* ═══════════ الأكواد المؤقتة ═══════════ */

interface Ticket {
  client_id: string;
  redirect_uri: string;
  code_challenge: string;
  scope: string[];
  state?: string;
  iat: number;
}
const codes = new Map<string, Ticket & { exp: number }>();
setInterval(() => { const t = now(); for (const [k, c] of codes) if (c.exp < t) codes.delete(k); }, 60_000).unref();

const signTicket = (t: Ticket) => { const b = b64url(JSON.stringify(t)); return `${b}.${hmac(config.jwtSecret, `ticket|${b}`)}`; };
function readTicket(s: unknown): Ticket | null {
  if (typeof s !== "string") return null;
  const [b, sig] = s.split(".");
  if (!b || !sig || !safeEqual(sig, hmac(config.jwtSecret, `ticket|${b}`))) return null;
  try {
    const t = JSON.parse(Buffer.from(b, "base64url").toString()) as Ticket;
    return now() - t.iat <= 600 ? t : null;
  } catch { return null; }
}

/* ═══════════ الصفحات ═══════════ */

const esc = (s: string) => s.replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]!);

function page(res: Response, status: number, body: string, formTo?: string): void {
  const formAction = ["'self'", ...(formTo ? [new URL(formTo).origin] : [])].join(" ");
  res.status(status)
    .set("Content-Security-Policy", `default-src 'none'; style-src 'unsafe-inline'; form-action ${formAction}; frame-ancestors 'none'; base-uri 'none'`)
    .set("X-Frame-Options", "DENY")
    .set("Referrer-Policy", "no-referrer")
    .set("Cache-Control", "no-store")
    .type("html")
    .send(`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Spread AI — ربط Claude</title><style>
body{font-family:system-ui,"Segoe UI",Tahoma,sans-serif;background:#f6f7fb;color:#1d2433;margin:0;padding:24px 16px}
.c{max-width:440px;margin:40px auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 4px 24px rgba(20,30,60,.08)}
h1{font-size:20px;margin:0 0 6px}p{line-height:1.7;margin:8px 0}.m{color:#667085;font-size:13px}
label.s{display:flex;gap:8px;align-items:flex-start;margin:10px 0;font-size:14px;line-height:1.6}
input[type=password]{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #d0d5dd;border-radius:10px;font-size:15px;margin:6px 0 14px}
.b{display:flex;gap:8px}button{flex:1;padding:11px;border:0;border-radius:10px;font-size:15px;cursor:pointer}
.ok{background:#4f46e5;color:#fff}.no{background:#eef0f4;color:#1d2433}.e{background:#fef3f2;color:#b42318;padding:10px 12px;border-radius:10px;font-size:14px}
code{background:#f2f4f7;padding:1px 6px;border-radius:6px;direction:ltr;display:inline-block}</style></head><body><div class="c">${body}</div></body></html>`);
}

const errorPage = (res: Response, msg: string, status = 400) => page(res, status, `<h1>تعذّر الربط</h1><p class="e">${esc(msg)}</p>`);

function redirectWith(res: Response, uri: string, params: Record<string, string | undefined>): void {
  const u = new URL(uri);
  for (const [k, v] of Object.entries(params)) if (v !== undefined) u.searchParams.set(k, v);
  res.set("Cache-Control", "no-store").redirect(302, u.href);
}

function consentPage(res: Response, t: Ticket, client: store.Client, error?: string): void {
  const host = new URL(t.redirect_uri).host;
  const scopes = t.scope.map(s =>
    `<label class="s"><input type="checkbox" name="scope" value="${esc(s)}" checked ${s === SCOPES.read ? "disabled" : ""}> <span><b>${esc(s)}</b><br><span class="m">${esc(SCOPE_LABELS[s] ?? s)}</span></span></label>`,
  ).join("");
  page(res, error ? 401 : 200, `<h1>ربط Claude بـ Spread AI</h1>
<p><b>${esc(client.client_name)}</b> عايز يوصل لخادم الجودة والمراقبة. هيرجع على <code>${esc(host)}</code>.</p>
${error ? `<p class="e">${esc(error)}</p>` : ""}
<form method="post" action="/oauth/authorize" autocomplete="off">
<input type="hidden" name="ticket" value="${esc(signTicket(t))}">
<input type="hidden" name="scope" value="${esc(SCOPES.read)}">
${scopes}
<p class="m">مفيش أي صلاحية بتسمح بالحذف أو بلمس الكريدت أو الدفع أو الاشتراكات أو الحسابات.</p>
<label for="pw"><b>باسورد صاحب الموقع</b></label>
<input id="pw" type="password" name="password" required autofocus>
<div class="b"><button class="ok" name="action" value="approve">موافق</button><button class="no" name="action" value="deny" formnovalidate>رفض</button></div>
</form>`, t.redirect_uri);
}

/* ═══════════ المسارات ═══════════ */

function tokenError(res: Response, error: string, description: string, status = 400): void {
  res.status(status).set("Cache-Control", "no-store").json({ error, error_description: description });
}

export function mountOAuth(app: Express): void {
  const oauthMetadata = {
    issuer,
    authorization_endpoint: `${issuer}/oauth/authorize`,
    token_endpoint: `${issuer}/oauth/token`,
    revocation_endpoint: `${issuer}/oauth/revoke`,
    ...(config.allowDcr ? { registration_endpoint: `${issuer}/oauth/register` } : {}),
    response_types_supported: ["code"],
    grant_types_supported: ["authorization_code", "refresh_token"],
    code_challenge_methods_supported: ["S256"],
    token_endpoint_auth_methods_supported: ["none", "client_secret_post", "client_secret_basic"],
    revocation_endpoint_auth_methods_supported: ["none", "client_secret_post", "client_secret_basic"],
    scopes_supported: ALL_SCOPES,
    authorization_response_iss_parameter_supported: true,
  };
  app.use(mcpAuthMetadataRouter({
    oauthMetadata,
    resourceServerUrl: config.mcpUrl,
    scopesSupported: ALL_SCOPES,
    resourceName: "Spread AI — الجودة والمراقبة",
    dangerouslyAllowInsecureIssuerUrl: config.publicUrl.protocol === "http:",
  }));

  const form = express.urlencoded({ extended: false, limit: "20kb" });

  // ── تسجيل عميل (DCR) ──
  app.post(["/oauth/register", "/register"], (req, res) => {
    if (!config.allowDcr) return tokenError(res, "access_denied", "Dynamic client registration is disabled", 403);
    if (!hit(`dcr:${req.ip}`, 10, 3600_000)) return tokenError(res, "slow_down", "Too many registrations — try again later", 429);
    if (store.clientCount() >= 500) return tokenError(res, "access_denied", "Client limit reached", 403);
    const b = (req.body ?? {}) as Record<string, unknown>;
    const uris = Array.isArray(b.redirect_uris) ? b.redirect_uris.map(String) : [];
    if (!uris.length || uris.length > 10) return tokenError(res, "invalid_redirect_uri", "redirect_uris is required (1–10)");
    const bad = uris.find(u => !redirectAllowed(u));
    if (bad) return tokenError(res, "invalid_redirect_uri", `Redirect URI not allowed: ${bad} (allowed hosts: ${config.allowedRedirectHosts.join(", ")})`);
    const method = (b.token_endpoint_auth_method as string) || "client_secret_basic";
    if (!["none", "client_secret_post", "client_secret_basic"].includes(method)) return tokenError(res, "invalid_client_metadata", "Unsupported token_endpoint_auth_method");
    const grants = Array.isArray(b.grant_types) ? b.grant_types.map(String) : ["authorization_code", "refresh_token"];
    if (grants.some(g => !["authorization_code", "refresh_token"].includes(g))) return tokenError(res, "invalid_client_metadata", "Only authorization_code and refresh_token are supported");
    const types = Array.isArray(b.response_types) ? b.response_types.map(String) : ["code"];
    if (types.some(t => t !== "code")) return tokenError(res, "invalid_client_metadata", "Only response_type=code is supported");
    const secret = method === "none" ? undefined : randomToken(32);
    const c: store.Client = {
      client_id: `spr_${randomToken(16)}`,
      client_secret_hash: secret ? sha256hex(secret) : undefined,
      client_name: String(b.client_name ?? "MCP client").slice(0, 80),
      redirect_uris: uris,
      token_endpoint_auth_method: method as store.Client["token_endpoint_auth_method"],
      created_at: now(),
    };
    store.addClient(c);
    console.error(`[oauth] client registered: ${c.client_id} (${c.client_name}) → ${uris.map(u => new URL(u).host).join(", ")}`);
    res.status(201).set("Cache-Control", "no-store").json({
      client_id: c.client_id,
      client_id_issued_at: c.created_at,
      ...(secret ? { client_secret: secret, client_secret_expires_at: 0 } : {}),
      client_name: c.client_name,
      redirect_uris: uris,
      grant_types: grants,
      response_types: types,
      token_endpoint_auth_method: method,
      scope: ALL_SCOPES.join(" "),
    });
  });

  // ── صفحة الموافقة ──
  app.get(["/oauth/authorize", "/authorize"], (req, res) => {
    const q = req.query as Record<string, string | undefined>;
    const client = findClient(q.client_id);
    if (!client) return errorPage(res, "العميل ده مش متسجّل (client_id غلط) — شيل الموصّل وضيفه تاني.");
    if (!q.redirect_uri || !redirectMatches(client, q.redirect_uri)) return errorPage(res, "عنوان الرجوع (redirect_uri) مش مسموح للعميل ده.");
    const back = (error: string, description: string) => redirectWith(res, q.redirect_uri!, { error, error_description: description, state: q.state, iss: issuer });
    if (q.response_type !== "code") return back("unsupported_response_type", "Only response_type=code is supported");
    if (!q.code_challenge || q.code_challenge_method !== "S256" || !/^[A-Za-z0-9\-_]{43}$/.test(q.code_challenge)) return back("invalid_request", "PKCE with code_challenge_method=S256 is required");
    if (!sameResource(q.resource)) return back("invalid_target", "Unknown resource");
    const scope = parseScopes(q.scope);
    if (!scope.includes(SCOPES.read)) scope.unshift(SCOPES.read);
    consentPage(res, { client_id: client.client_id, redirect_uri: q.redirect_uri, code_challenge: q.code_challenge, scope, state: q.state, iat: now() }, client);
  });

  app.post(["/oauth/authorize", "/authorize"], form, (req, res) => {
    const t = readTicket(req.body?.ticket);
    if (!t) return errorPage(res, "الصفحة دي قديمة (أكتر من 10 دقايق) — ابدأ الربط تاني من Claude.");
    const client = findClient(t.client_id);
    if (!client || !redirectMatches(client, t.redirect_uri)) return errorPage(res, "العميل ده مش متسجّل.");
    if (req.body?.action === "deny") return redirectWith(res, t.redirect_uri, { error: "access_denied", error_description: "The owner denied access", state: t.state, iss: issuer });
    if (!config.ownerPasswordHash) return errorPage(res, "الدخول بالباسورد مش متفعّل على الخادم ده (MCP_OWNER_PASSWORD_HASH).", 503);
    if (count(`login:${req.ip}`) >= 5 || count("login:all") >= 20) {
      return errorPage(res, "محاولات غلط كتير — الدخول مقفول مؤقتًا. جرّب بعد ربع ساعة.", 429);
    }
    const pw = String(req.body?.password ?? "");
    if (!pw || !verifyPassword(pw, config.ownerPasswordHash)) {
      hit(`login:${req.ip}`, 5, 15 * 60_000);
      hit("login:all", 20, 3600_000);
      console.error(`[oauth] wrong owner password from ${req.ip}`);
      return void setTimeout(() => consentPage(res, { ...t, iat: now() }, client, "الباسورد غلط."), 600);
    }
    const picked = parseScopes(req.body?.scope);
    const scope = t.scope.filter(s => picked.includes(s) || s === SCOPES.read);
    const code = randomToken(32);
    codes.set(sha256hex(code), { ...t, scope, exp: now() + 300 });
    console.error(`[oauth] access approved for ${client.client_id} (${scope.join(" ")})`);
    redirectWith(res, t.redirect_uri, { code, state: t.state, iss: issuer });
  });

  // ── التوكن ──
  app.post(["/oauth/token", "/token"], form, (req, res) => {
    if (!hit(`token:${req.ip}`, 60, 60_000)) return tokenError(res, "slow_down", "Too many requests", 429);
    const client = authClient(req);
    if (!client) return tokenError(res, "invalid_client", "Unknown client or wrong client secret", 401);
    const b = (req.body ?? {}) as Record<string, string | undefined>;

    if (b.grant_type === "authorization_code") {
      const key = sha256hex(String(b.code ?? ""));
      const c = codes.get(key);
      codes.delete(key); // مرة واحدة بس
      if (!c || c.exp < now()) return tokenError(res, "invalid_grant", "Invalid or expired authorization code");
      if (c.client_id !== client.client_id) return tokenError(res, "invalid_grant", "Code was issued to another client");
      if (b.redirect_uri && b.redirect_uri !== c.redirect_uri) return tokenError(res, "invalid_grant", "redirect_uri mismatch");
      if (!pkceOk(String(b.code_verifier ?? ""), c.code_challenge)) return tokenError(res, "invalid_grant", "PKCE verification failed");
      if (!sameResource(b.resource)) return tokenError(res, "invalid_target", "Unknown resource");
      return void res.set("Cache-Control", "no-store").set("Pragma", "no-cache").json(issueTokens(client.client_id, c.scope));
    }

    if (b.grant_type === "refresh_token") {
      const key = sha256hex(String(b.refresh_token ?? ""));
      const r = store.getRefresh(key);
      if (!r || r.exp < now() || r.client_id !== client.client_id) return tokenError(res, "invalid_grant", "Invalid or expired refresh token");
      if (r.used) {
        store.revokeFamily(r.family);
        console.error(`[oauth] refresh token reuse for ${client.client_id} — family revoked`);
        return tokenError(res, "invalid_grant", "Refresh token already used — reconnect the connector");
      }
      store.markUsed(key);
      const narrowed = b.scope ? parseScopes(b.scope).filter(s => r.scope.includes(s)) : r.scope;
      return void res.set("Cache-Control", "no-store").set("Pragma", "no-cache").json(issueTokens(client.client_id, narrowed.length ? narrowed : r.scope, r.family));
    }

    tokenError(res, "unsupported_grant_type", "Use authorization_code or refresh_token");
  });

  // ── إلغاء (RFC 7009) ──
  app.post(["/oauth/revoke", "/revoke"], form, (req, res) => {
    const client = authClient(req);
    const r = store.getRefresh(sha256hex(String(req.body?.token ?? "")));
    if (client && r && r.client_id === client.client_id) store.revokeFamily(r.family);
    res.status(200).set("Cache-Control", "no-store").end();
  });
}

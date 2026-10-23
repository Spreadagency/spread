/**
 * اختبار خادم الـ MCP من بره زي Claude بالظبط (بعميل الـ SDK الرسمي).
 *
 *   MCP_URL=https://mcp.ai.spreadagency.net MCP_TOKEN=<static token> npm run smoke
 *   + OWNER_PASSWORD=…   ← يجرّب OAuth كامل (تسجيل ← موافقة ← توكن ← refresh ← سرقة refresh)
 *   + SMOKE_WRITE=1      ← يجرّب أدوات الكتابة (بيكتب تقييم وتقرير تجريبي بتاريخ 2000-01-01 في قاعدة الـ AI)
 *
 * بيخرج بكود 1 لو أي اختبار فشل.
 */
import { randomBytes } from "node:crypto";
import {
  Client,
  discoverOAuthServerInfo,
  exchangeAuthorization,
  refreshAuthorization,
  registerClient,
  startAuthorization,
  StreamableHTTPClientTransport,
} from "@modelcontextprotocol/client";

const BASE = (process.env.MCP_URL ?? "http://localhost:3000").replace(/\/+$/, "");
const MCP = new URL(`${BASE}/mcp`);
const TOKEN = process.env.MCP_TOKEN ?? "";
const PW = process.env.OWNER_PASSWORD ?? "";
const WRITE = process.env.SMOKE_WRITE === "1";

let pass = 0, fail = 0;
function check(name: string, ok: boolean, info = ""): void {
  ok ? pass++ : fail++;
  console.log(`${ok ? "  ✓" : "  ✗"} ${name}${info ? `  — ${info}` : ""}`);
}

type CallResult = { isError?: boolean; content: { type: string; text?: string; mimeType?: string; data?: string }[]; structuredContent?: Record<string, unknown> };

async function connect(token: string): Promise<Client> {
  const client = new Client({ name: "spread-smoke", version: "1.0.0" });
  await client.connect(new StreamableHTTPClientTransport(MCP, { requestInit: { headers: { Authorization: `Bearer ${token}` } } }));
  return client;
}

async function call(c: Client, name: string, args: Record<string, unknown> = {}): Promise<CallResult> {
  return (await c.callTool({ name, arguments: args })) as unknown as CallResult;
}

const textOf = (r: CallResult) => r.content.filter(x => x.type === "text").map(x => x.text).join("\n");
const json = (r: CallResult) => (r.structuredContent ?? JSON.parse(r.content[0]?.text ?? "{}")) as Record<string, any>;

console.log(`MCP: ${MCP.href}\n\n— البوابة`);
{
  const r = await fetch(MCP, { method: "POST", headers: { "content-type": "application/json", accept: "application/json, text/event-stream" }, body: "{}" });
  check("من غير توكن ← 401 + resource_metadata", r.status === 401 && (r.headers.get("www-authenticate") ?? "").includes("resource_metadata"));
  const r2 = await fetch(MCP, { method: "POST", headers: { authorization: "Bearer wrong-token-wrong-token-wrong-token", "content-type": "application/json" }, body: "{}" });
  check("توكن غلط ← 401", r2.status === 401);
  const m = await fetch(`${BASE}/.well-known/oauth-protected-resource/mcp`);
  const mj = (await m.json()) as { resource?: string };
  check("Protected Resource Metadata", m.ok && mj.resource === MCP.href, mj.resource);
}

if (!TOKEN) {
  console.log("\n(MCP_TOKEN مش متحدد — اختبارات الأدوات اتشالت)");
} else {
  const c = await connect(TOKEN);
  console.log("\n— الأدوات والموارد والبرومبتات");
  const { tools } = await c.listTools();
  const names = tools.map(t => t.name);
  check("الأدوات اتسجلت", names.length >= 22, `${names.length} أداة`);
  check("كل أداة ليها title و annotations", tools.every(t => t.annotations && (t.title || t.annotations.title)));
  check("مفيش أداة حذف أو كريدت أو دفع", !names.some(n => /delete|remove|credit|payment|subscription|password|affiliate|wallet/i.test(n)));
  const { resources } = await c.listResources();
  check("rubric://content-quality", resources.some(r => r.uri === "rubric://content-quality"));
  const rub = await c.readResource({ uri: "rubric://content-quality" });
  check("المعايير فيها الـ 14 مفتاح", ["hook", "platform_fit", "arabic_text_correctness", "watermark_placement"].every(k => JSON.stringify(rub.contents).includes(k)));
  const { prompts } = await c.listPrompts();
  check("برومبت daily_review", prompts.some(p => p.name === "daily_review"));
  const dr = await c.getPrompt({ name: "daily_review", arguments: { date: "2026-01-01" } });
  check("daily_review بيتولّد", JSON.stringify(dr.messages).includes("spread_get_metrics"));

  console.log("\n— القراءة");
  const h = await call(c, "spread_health");
  check("spread_health", !h.isError && json(h).service === "spread-internal-api", `secure_mode=${json(h).secure_mode}`);
  for (const [t, a] of [["spread_get_metrics", {}], ["spread_list_prompt_templates", {}], ["spread_list_style_presets", {}], ["spread_list_issues", { limit: 3 }],
    ["spread_get_ai_errors", {}], ["spread_list_change_requests", { limit: 3 }], ["spread_list_reports", { limit: 2 }], ["spread_get_security_logs", {}],
    ["spread_list_security_findings", { source: "config" }], ["spread_list_security_findings", { source: "dependency" }], ["spread_get_e2e_results", {}]] as const) {
    const r = await call(c, t, a);
    check(`${t}${"source" in a ? `(${a.source})` : ""}`, !r.isError, r.isError ? textOf(r).slice(0, 160) : "");
  }
  const posts = await call(c, "spread_list_posts", { limit: 2 });
  const items = (json(posts).items ?? []) as { id: number; brand_id: number }[];
  check("spread_list_posts", !posts.isError && Array.isArray(items), `${items.length} منشور · next_cursor=${json(posts).next_cursor}`);
  const bad = await call(c, "spread_list_posts", { limit: 500 });
  check("limit أكبر من 50 ← خطأ واضح", bad.isError === true, textOf(bad).slice(0, 100));
  const miss = await call(c, "spread_get_post", { post_id: 99999999, include_images: false });
  check("منشور مش موجود ← رسالة بالحل", miss.isError === true && textOf(miss).includes("posts"), textOf(miss).slice(0, 100));

  let postId = 0;
  // منشور فيه تصميم (علشان نجرّب الصور) — وإلا أول منشور
  const withDesign = (json(await call(c, "spread_list_posts", { limit: 50 })).items ?? []).find((x: { designs: number }) => Number(x.designs) > 0);
  if (withDesign) items.unshift(withDesign);
  if (items[0]) {
    postId = items[0].id;
    const p = await call(c, "spread_get_post", { post_id: postId, max_images: 1 });
    const imgs = p.content.filter(x => x.type === "image");
    check(`spread_get_post #${postId}`, !p.isError && !!json(p).post, `${imgs.length} صورة`);
    if (imgs[0]) check("الصورة image content حقيقية", /^image\//.test(imgs[0].mimeType ?? "") && (imgs[0].data?.length ?? 0) > 100);
    const designs = (json(p).designs ?? []) as { image?: string }[];
    if (designs[0]?.image) {
      const g = await call(c, "spread_get_image", { path: designs[0].image });
      check("spread_get_image", !g.isError && g.content[0]?.type === "image");
    }
    const b = await call(c, "spread_get_brand", { brand_id: items[0].brand_id });
    const flat = textOf(b);
    check(`spread_get_brand #${items[0].brand_id} من غير أرقام أو إيميلات كاملة`, !b.isError && !/(?<!\d)01[0125]\d{8}(?!\d)/.test(flat) && !/[A-Za-z0-9._%+-]{2,}@[A-Za-z0-9.-]+\.[a-z]{2,}/.test(flat));
  }
  const g2 = await call(c, "spread_get_image", { path: "../includes/config.php" });
  check("صورة برّه uploads ← مرفوض", g2.isError === true);

  if (WRITE) {
    console.log("\n— الكتابة (قاعدة الـ AI بس)");
    if (postId) {
      const cs = Object.fromEntries(["hook", "clarity", "brand_voice_match", "relevance_to_services", "cta", "dialect_correctness", "originality", "platform_fit"].map(k => [k, 8]));
      const ds = Object.fromEntries(["brand_colors_match", "text_legibility", "arabic_text_correctness", "composition", "idea_match", "watermark_placement"].map(k => [k, 6]));
      const e = await call(c, "spread_save_evaluation", { post_id: postId, content_scores: cs, design_scores: ds, strengths: "smoke test", weaknesses: "smoke test", fix_suggestion: "smoke test" });
      check("spread_save_evaluation", !e.isError && json(e).overall === 7.2, `overall=${json(e).overall}`);
      const e2 = await call(c, "spread_save_evaluation", { post_id: postId, content_scores: { hook: 15 }, strengths: "x", weaknesses: "x", fix_suggestion: "x" });
      check("تقييم ناقص/برّه 1–10 ← مرفوض قبل ما يوصل للموقع", e2.isError === true);
    }
    const rep = await call(c, "spread_save_report", { type: "daily", report_date: "2000-01-01", summary: "تقرير اختبار من smoke test — يتجاهل", payload: { test: true } });
    check("spread_save_report", !rep.isError && json(rep).saved === true);
    if (names.includes("spread_propose_change")) {
      const d = `smoke-${Date.now()}`;
      const a = { type: "config", target: "smoke_test", diff: d, reason: "اختبار تلقائي — يتجاهل", evidence: { n: 1 } };
      const p1 = await call(c, "spread_propose_change", a);
      const p2 = await call(c, "spread_propose_change", a);
      check("spread_propose_change + مايتكررش", !p1.isError && json(p2).duplicate === true);
    }
  }
  await c.close();
}

if (PW) {
  console.log("\n— OAuth 2.1 (زي موصّل Claude)");
  const redirect = "http://localhost:53682/callback";
  const info = await discoverOAuthServerInfo(MCP);
  check("اكتشاف الـ Authorization Server", !!info.authorizationServerMetadata?.token_endpoint, info.authorizationServerUrl);
  const meta = info.authorizationServerMetadata!;
  const reg = await fetch(meta.registration_endpoint!, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ redirect_uris: ["https://evil.example.com/cb"], client_name: "evil" }) });
  check("تسجيل redirect على دومين مش مسموح ← مرفوض", reg.status === 400);
  const client = await registerClient(info.authorizationServerUrl, { metadata: meta, clientMetadata: { redirect_uris: [redirect], client_name: "Spread smoke", token_endpoint_auth_method: "none", grant_types: ["authorization_code", "refresh_token"], response_types: ["code"] } });
  check("تسجيل عميل (DCR)", !!client.client_id, client.client_id);
  const { authorizationUrl, codeVerifier } = await startAuthorization(info.authorizationServerUrl, { metadata: meta, clientInformation: client, redirectUrl: redirect, scope: "spread:read spread:evaluate", resource: MCP, state: "st123" });
  const pageRes = await fetch(authorizationUrl);
  const html = await pageRes.text();
  const ticket = /name="ticket" value="([^"]+)"/.exec(html)?.[1] ?? "";
  check("صفحة الموافقة", pageRes.ok && !!ticket && (pageRes.headers.get("content-security-policy") ?? "").includes("frame-ancestors 'none'"));
  const post = (fields: Record<string, string | string[]>) => {
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(fields)) for (const x of [v].flat()) body.append(k, x);
    return fetch(`${BASE}/oauth/authorize`, { method: "POST", body, redirect: "manual", headers: { origin: BASE } });
  };
  const wrong = await post({ ticket, password: "wrong-password", action: "approve", scope: ["spread:read", "spread:evaluate"] });
  check("باسورد غلط ← مرفوض", wrong.status === 401);
  // صاحب الموقع شال صلاحية التقييم ← التوكن يبقى قراءة بس
  const ok = await post({ ticket, password: PW, action: "approve", scope: ["spread:read"] });
  const loc = new URL(ok.headers.get("location") ?? "http://x/");
  const code = loc.searchParams.get("code") ?? "";
  check("موافقة ← رجوع بـ code + state + iss", ok.status === 302 && !!code && loc.searchParams.get("state") === "st123" && loc.searchParams.get("iss") === info.authorizationServerUrl.replace(/\/$/, ""));
  const badPkce = await fetch(meta.token_endpoint, { method: "POST", body: new URLSearchParams({ grant_type: "authorization_code", code, code_verifier: randomBytes(32).toString("base64url"), client_id: client.client_id, redirect_uri: redirect }) });
  check("PKCE غلط ← مرفوض (والكود اتحرق)", badPkce.status === 400);
  const ticket2 = /name="ticket" value="([^"]+)"/.exec(await (await fetch(authorizationUrl)).text())?.[1] ?? "";
  const ok2 = await post({ ticket: ticket2, password: PW, action: "approve", scope: ["spread:read"] });
  const loc2 = new URL(ok2.headers.get("location") ?? "http://x/");
  const code2 = loc2.searchParams.get("code") ?? "";
  const tokens = await exchangeAuthorization(info.authorizationServerUrl, { metadata: meta, clientInformation: client, authorizationCode: code2, iss: loc2.searchParams.get("iss") ?? undefined, codeVerifier, redirectUri: redirect, resource: MCP });
  check("تبديل الكود بتوكن (PKCE)", !!tokens.access_token && !!tokens.refresh_token, `scope=${tokens.scope}`);
  const reuse = await fetch(meta.token_endpoint, { method: "POST", body: new URLSearchParams({ grant_type: "authorization_code", code: code2, code_verifier: codeVerifier, client_id: client.client_id, redirect_uri: redirect }) });
  check("نفس الكود مرة تانية ← مرفوض", reuse.status === 400);
  const oc = await connect(tokens.access_token);
  const t2 = (await oc.listTools()).tools.map(t => t.name);
  check("توكن قراءة بس ← أدوات القراءة بس", t2.includes("spread_get_post") && !t2.some(n => n.startsWith("spread_save_") || n === "spread_analyze_issue"), `${t2.length} أداة`);
  const h2 = await call(oc, "spread_health");
  check("أداة بتوكن OAuth", !h2.isError);
  await oc.close();
  const fresh = await refreshAuthorization(info.authorizationServerUrl, { metadata: meta, clientInformation: client, refreshToken: tokens.refresh_token!, resource: MCP });
  check("refresh ← توكن جديد", !!fresh.access_token && fresh.refresh_token !== tokens.refresh_token);
  const stolen = await fetch(meta.token_endpoint, { method: "POST", body: new URLSearchParams({ grant_type: "refresh_token", refresh_token: tokens.refresh_token!, client_id: client.client_id }) });
  const after = await fetch(meta.token_endpoint, { method: "POST", body: new URLSearchParams({ grant_type: "refresh_token", refresh_token: fresh.refresh_token!, client_id: client.client_id }) });
  check("refresh قديم اتعاد ← مرفوض والعيلة كلها اتلغت", stolen.status === 400 && after.status === 400);
  const tamper = tokens.access_token.slice(0, -4) + (tokens.access_token.endsWith("AAAA") ? "BBBB" : "AAAA");
  const tr = await fetch(MCP, { method: "POST", headers: { authorization: `Bearer ${tamper}`, "content-type": "application/json" }, body: "{}" });
  check("توكن متلاعب فيه ← 401", tr.status === 401);
}

console.log(`\nالنتيجة: ${pass} نجح · ${fail} فشل`);
process.exit(fail ? 1 : 0);

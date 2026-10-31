/**
 * أدوات القراءة (spread:read) — كلها بتقرا بس، ومفيهاش أي بيانات شخصية كاملة (الـ API بيخفيها).
 */
import type { McpServer } from "@modelcontextprotocol/server";
import * as z from "zod/v4";
import { iapi, iapiMedia, mediaPath, MAX_IMAGE_BYTES } from "../api.js";
import { fit, jsonResult } from "../format.js";
import { Cursor, Day, Id, Limit, READ, type ToolEnv } from "./common.js";

export function registerReadTools(server: McpServer, env: ToolEnv): void {
  const get = (route: string, query: Record<string, unknown>, signal: AbortSignal) =>
    iapi(route, "read", { query: query as Record<string, string | number | boolean | undefined>, client: env.client, signal });

  server.registerTool(
    "spread_health",
    {
      title: "Health check",
      description: "Check that the Spread internal API is reachable and configured (secure mode, separate AI database, AI tables present). Call this first if any other tool fails.",
      annotations: READ,
    },
    async ctx => jsonResult(await get("health", {}, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_get_metrics",
    {
      title: "Usage & quality metrics",
      description: `Daily (or date-range) metrics for the whole platform, compared with the previous period of the same length.

Returns: the funnel (signups → brand completed → ideas generated → ideas selected → posts → designs → scheduled → published), regenerations, manual edits, post and design ratings (👍/👎 and score), credits used, AI calls per provider (count, failures, avg latency), and "anomalies" = every metric that moved more than 20%.
Defaults to yesterday. Use this as the starting point of a daily review.`,
      inputSchema: z.strictObject({
        date_from: Day.optional().describe("YYYY-MM-DD (default: yesterday)"),
        date_to: Day.optional().describe("YYYY-MM-DD (default: same as date_from)"),
      }),
      annotations: READ,
    },
    async (a, ctx) => jsonResult(await get("metrics", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_list_posts",
    {
      title: "List generated posts",
      description: `List generated posts (newest first) with filters. Each item: id, brand_id, format, rating (1 / -1 / null), prompt_version_id, provider, model, excerpt, regenerations, manual_edits, designs count, evaluated, overall (Claude score if evaluated).

Examples:
  - posts rated 👎 yesterday → rating="down", date_from=date_to=yesterday
  - posts not yet evaluated → unevaluated_only=true
  - posts regenerated 3+ times → min_regenerations=3
Paginate with cursor (= next_cursor of the previous page). Use spread_get_post for the full post.`,
      inputSchema: z.strictObject({
        date_from: Day.optional(),
        date_to: Day.optional(),
        rating: z.enum(["up", "down", "none"]).optional().describe("up = 👍 · down = 👎 · none = not rated"),
        min_regenerations: z.number().int().min(0).max(100).optional(),
        prompt_version_id: Id.optional().describe("prompts.id — from spread_list_prompt_templates"),
        provider: z.string().max(60).optional().describe("AI provider name, e.g. openai, gemini, anthropic"),
        brand_id: Id.optional(),
        format: z.string().max(20).optional().describe("post format, e.g. single, carousel, reel"),
        unevaluated_only: z.boolean().optional().describe("only posts Claude has not evaluated yet"),
        limit: Limit(20),
        cursor: Cursor,
      }),
      annotations: READ,
    },
    async (a, ctx) => jsonResult(await get("posts", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_get_post",
    {
      title: "Get one post (full detail + design images)",
      description: `Everything about one post, to evaluate it: text, hashtags, CTA, slides, design direction, the style chosen, the brand identity snapshot at generation time, the idea and its brief, the exact prompt version used, all versions and manual edits (before → after), user ratings and reasons, previous Claude evaluations, and the brand's last 20 posts (to judge originality/repetition).

With include_images=true (default) the design images are attached so you can judge them visually (up to max_images, ${MAX_IMAGE_BYTES / 1e6}MB each).
Next step: score it with the rubric (resource rubric://content-quality) and save with spread_save_evaluation.`,
      inputSchema: z.strictObject({
        post_id: Id,
        include_images: z.boolean().default(true),
        max_images: z.number().int().min(0).max(6).default(3),
      }),
      annotations: READ,
    },
    async ({ post_id, include_images, max_images }, ctx) => {
      const data = await get("post", { post_id }, ctx.mcpReq.signal);
      const r = fit(data);
      const content: ({ type: "text"; text: string } | { type: "image"; data: string; mimeType: string })[] = [{ type: "text", text: r.text }];
      if (include_images && max_images > 0) {
        const designs = ((data.designs as { id: number; image?: string | null; slide_no?: number | null }[]) ?? []).filter(d => d.image);
        for (const d of designs.slice(0, max_images)) {
          try {
            const img = await iapiMedia(mediaPath(String(d.image)), env.client, ctx.mcpReq.signal);
            content.push({ type: "text", text: `صورة التصميم #${d.id}${d.slide_no ? ` (سلايد ${d.slide_no})` : ""}:` });
            content.push({ type: "image", data: img.data, mimeType: img.mimeType });
          } catch (e) {
            content.push({ type: "text", text: `صورة التصميم #${d.id} ماتجابتش: ${e instanceof Error ? e.message : String(e)}` });
          }
        }
        if (designs.length > max_images) content.push({ type: "text", text: `فيه ${designs.length - max_images} تصميم كمان — زوّد max_images أو استخدم spread_get_image.` });
      }
      return { content, structuredContent: r.data };
    },
  );

  server.registerTool(
    "spread_get_image",
    {
      title: "Get an image (design or issue screenshot)",
      description: "Fetch one image the API referenced as \"media?path=uploads/...\" (a design image in spread_get_post, or an issue screenshot in spread_list_issues). Only files under uploads/ are allowed.",
      inputSchema: z.strictObject({
        path: z.string().min(8).max(300).describe('The value of "image" / "screenshot", e.g. "media?path=uploads/designs/abc.png" or "uploads/designs/abc.png"'),
      }),
      annotations: READ,
    },
    async ({ path }, ctx) => {
      const img = await iapiMedia(mediaPath(path), env.client, ctx.mcpReq.signal);
      return { content: [{ type: "image" as const, data: img.data, mimeType: img.mimeType }] };
    },
  );

  server.registerTool(
    "spread_get_brand",
    {
      title: "Get a brand's identity",
      description: "Full brand identity for a brand_id (from spread_list_posts / spread_get_post): business, industry, tone, dialect, colors, design rules, visual identity, services, topics, personas (ideal customers), characters, formats, documents (summaries), learned brand memory and client feedback. Phone numbers and emails are masked.",
      inputSchema: z.strictObject({ brand_id: Id }),
      annotations: READ,
    },
    async (a, ctx) => jsonResult(await get("brand", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_list_prompt_templates",
    {
      title: "List prompt templates",
      description: "All prompt types with their live version and any testing (A/B) versions, each with stats: posts, 👍/👎, avg regenerations, designs, and Claude's average overall score. Use spread_get_prompt_version for a version's full text.",
      annotations: READ,
    },
    async ctx => jsonResult(await get("prompt_templates", {}, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_get_prompt_version",
    {
      title: "Get a prompt version",
      description: "Full text and stats of one prompt version (prompts.id — from spread_list_prompt_templates or prompt_version in spread_get_post).",
      inputSchema: z.strictObject({ id: Id }),
      annotations: READ,
    },
    async (a, ctx) => jsonResult(await get("prompt_version", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_get_ab_results",
    {
      title: "A/B test results",
      description: "Live vs testing versions of one prompt type (e.g. content, campaign_content): posts, 👍/👎, rating score, regenerations, Claude score, and sample_ok (enough posts to decide, min_sample default 30). Never recommend promoting a version whose sample_ok is false.",
      inputSchema: z.strictObject({ template_key: z.string().min(2).max(100).describe("prompt type, e.g. content") }),
      annotations: READ,
    },
    async (a, ctx) => jsonResult(await get("ab_results", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_list_style_presets",
    {
      title: "List style presets",
      description: "Design style presets used for generation (design styles, palettes, tones, visuals, character styles).",
      inputSchema: z.strictObject({ kind: z.enum(["design_style", "palette", "tone", "visual", "character_style"]).optional() }),
      annotations: READ,
    },
    async (a, ctx) => jsonResult(await get("style_presets", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_list_issues",
    {
      title: "List user-reported issues",
      description: `Issues users reported with "⚑ بلّغ عن مشكلة": category, description, page, auto context (browser, last 10 events, prompt version/provider of the post or design), screenshot ref, status, and Claude's analysis if any.
Open a screenshot with spread_get_image. Save your analysis with spread_analyze_issue. Paginate with cursor.`,
      inputSchema: z.strictObject({
        status: z.enum(["new", "analyzing", "analyzed", "in_progress", "resolved", "wont_fix"]).optional().describe("new = not handled yet"),
        limit: Limit(20),
        cursor: Cursor,
      }),
      annotations: READ,
    },
    async (a, ctx) => jsonResult(await get("issues", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_get_ai_errors",
    {
      title: "AI generation errors",
      description: "Failed AI calls for one day grouped by provider, kind and message, with total calls and failures. Default: yesterday.",
      inputSchema: z.strictObject({ date: Day.optional(), provider: z.string().max(60).optional() }),
      annotations: READ,
    },
    async (a, ctx) => jsonResult(await get("ai_errors", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_list_change_requests",
    {
      title: "List change requests",
      description: "Improvement proposals and their status, including rejected ones with the admin's note. Check this before proposing anything: a proposal rejected before must not be repeated without new evidence.",
      inputSchema: z.strictObject({
        status: z.enum(["pending", "approved", "rejected", "applied", "reverted", "postponed"]).optional(),
        limit: Limit(30),
        cursor: Cursor,
      }),
      annotations: READ,
    },
    async (a, ctx) => jsonResult(await get("change_requests", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_list_reports",
    {
      title: "List saved reports",
      description: "Reports saved before with spread_save_report (daily / weekly / security), newest first — use the last one to compare and avoid repeating the same recommendations.",
      inputSchema: z.strictObject({ type: z.enum(["daily", "weekly", "security"]).optional(), limit: Limit(10), cursor: Cursor }),
      annotations: READ,
    },
    async (a, ctx) => jsonResult(await get("reports", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_get_security_logs",
    {
      title: "Security signals for a day",
      description: "Failed logins by IP (user, admin and internal API), rate-limit activity per action, abnormal credit usage per user (vs the daily average), and new PHP errors grouped. Default: yesterday.",
      inputSchema: z.strictObject({ date: Day.optional() }),
      annotations: READ,
    },
    async (a, ctx) => jsonResult(await get("security_logs", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_list_security_findings",
    {
      title: "Security findings",
      description: "Recorded security findings for one source (zap scan, dependency audit, code review, config, logs), most severe first, with status (open / in_progress / fixed / accepted_risk / false_positive).",
      inputSchema: z.strictObject({ source: z.enum(["zap", "dependency", "code_review", "config", "logs"]).default("zap") }),
      annotations: READ,
    },
    async ({ source }, ctx) => jsonResult(await get(source === "dependency" ? "dependency_audit" : "security_scan", source === "dependency" ? {} : { source }, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_get_e2e_results",
    {
      title: "End-to-end test results",
      description: "Results of the automated end-to-end flows (signup, brand, ideas, post, design, schedule…) on staging: status, duration, error. Empty until phase 8 runs them.",
      inputSchema: z.strictObject({ date: Day.optional() }),
      annotations: READ,
    },
    async (a, ctx) => jsonResult(await get("e2e_results", a, ctx.mcpReq.signal)),
  );
}


/**
 * أدوات التقييم والتسجيل (spread:evaluate) — بتكتب في قاعدة الـ AI بس (مستخدم MySQL من غير DELETE ومن غير أي صلاحية على قاعدة المنصة).
 */
import type { McpServer } from "@modelcontextprotocol/server";
import * as z from "zod/v4";
import { iapi } from "../api.js";
import { jsonResult } from "../format.js";
import { CONTENT_KEYS, DESIGN_KEYS } from "../rubric.js";
import { Day, Id, WRITE_APPEND, WRITE_UPSERT, type ToolEnv } from "./common.js";

const Score = z.number().min(1).max(10);
const scoresOf = (keys: readonly string[]) => z.strictObject(Object.fromEntries(keys.map(k => [k, Score])) as Record<string, typeof Score>);

export function registerEvaluateTools(server: McpServer, env: ToolEnv): void {
  const post = (route: string, body: Record<string, unknown>, signal: AbortSignal) => iapi(route, "ai_write", { body, client: env.client, signal });

  server.registerTool(
    "spread_save_evaluation",
    {
      title: "Save a post evaluation",
      description: `Save Claude's evaluation of one post (after reading it with spread_get_post and scoring it with rubric://content-quality).
content_scores: all 8 criteria, 1–10. design_scores: all 6 criteria, 1–10 (omit only if the post has no design).
The server computes content_total, design_total and overall (default 0.6 content + 0.4 design) and returns them.
strengths / weaknesses / fix_suggestion: short, concrete, in Egyptian Arabic.`,
      inputSchema: z.strictObject({
        post_id: Id,
        design_id: Id.optional().describe("the design you scored (from designs[].id) when the post has several"),
        content_scores: scoresOf(CONTENT_KEYS).describe(CONTENT_KEYS.join(", ")),
        design_scores: scoresOf(DESIGN_KEYS).optional().describe(DESIGN_KEYS.join(", ")),
        strengths: z.string().min(3).max(3000),
        weaknesses: z.string().min(3).max(3000),
        fix_suggestion: z.string().min(3).max(3000).describe("what exactly to change in the prompt, style or brand data to fix the weaknesses"),
      }),
      annotations: WRITE_APPEND,
    },
    async (a, ctx) => jsonResult(await post("save_evaluation", { ...a, evaluator: "claude" }, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_analyze_issue",
    {
      title: "Save an issue analysis",
      description: "Classify a user-reported issue (from spread_list_issues): category, severity, likely root cause, proposed fix and related issue ids. Saving again overwrites the previous analysis. The issue status itself is changed only by the admin.",
      inputSchema: z.strictObject({
        issue_id: Id,
        ai_category: z.enum(["bug", "content_quality", "design_quality", "ux", "feature_request", "security"]),
        ai_severity: z.enum(["low", "medium", "high", "critical"]),
        root_cause: z.string().min(5).max(4000),
        fix: z.string().min(5).max(4000),
        related_issue_ids: z.array(Id).max(50).default([]),
      }),
      annotations: WRITE_UPSERT,
    },
    async (a, ctx) => jsonResult(await post("analyze_issue", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_save_report",
    {
      title: "Save a report",
      description: "Save the daily / weekly / security report. One report per type per day: saving again the same day updates it. summary = the readable report (Egyptian Arabic, markdown ok); payload = the key numbers as JSON so later reports can compare.",
      inputSchema: z.strictObject({
        type: z.enum(["daily", "weekly", "security"]),
        report_date: Day.optional().describe("the day the report is about (default: today)"),
        summary: z.string().min(20).max(8000),
        payload: z.record(z.string(), z.unknown()).optional(),
      }),
      annotations: WRITE_UPSERT,
    },
    async (a, ctx) => jsonResult(await post("save_report", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_save_security_finding",
    {
      title: "Record a security finding",
      description: "Record a security finding. Same source + location = same finding (updates last_seen instead of duplicating; a finding marked fixed re-opens if it appears again).",
      inputSchema: z.strictObject({
        source: z.enum(["code_review", "zap", "config", "logs", "dependency"]),
        severity: z.enum(["info", "low", "medium", "high", "critical"]),
        title: z.string().min(3).max(255),
        location: z.string().min(1).max(500).describe("file:line, URL, setting name, or package@version"),
        description: z.string().max(4000).optional(),
        recommendation: z.string().max(4000).optional(),
      }),
      annotations: WRITE_UPSERT,
    },
    async (a, ctx) => jsonResult(await post("save_security_finding", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_save_integrity_issue",
    {
      title: "Record a data-integrity issue",
      description: "Record the result of a data-integrity check (e.g. posts without a brand, designs whose file is missing). Same check_key updates the existing row; count=0 marks it fixed.",
      inputSchema: z.strictObject({
        check_key: z.string().regex(/^[a-z0-9_]+$/).max(80),
        entity_type: z.string().min(2).max(40),
        entity_ids: z.array(Id).max(200).default([]),
        count: z.number().int().min(0).optional(),
        description: z.string().max(2000).optional(),
        suggested_fix: z.string().max(2000).optional(),
      }),
      annotations: WRITE_UPSERT,
    },
    async (a, ctx) => jsonResult(await post("save_integrity_issue", a, ctx.mcpReq.signal)),
  );
}


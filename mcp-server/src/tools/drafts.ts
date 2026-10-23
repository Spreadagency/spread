/**
 * أدوات المسودات (spread:draft) — كل حاجة هنا مسودة مستنية موافقة الأدمن. مفيش ولا أداة بتطبّق تعديل على المنصة.
 */
import type { McpServer } from "@modelcontextprotocol/server";
import * as z from "zod/v4";
import { iapi } from "../api.js";
import { jsonResult } from "../format.js";
import { Id, WRITE_APPEND, type ToolEnv } from "./common.js";

export function registerDraftTools(server: McpServer, env: ToolEnv): void {
  const post = (route: string, body: Record<string, unknown>, signal: AbortSignal) => iapi(route, "draft", { body, client: env.client, signal });

  server.registerTool(
    "spread_propose_change",
    {
      title: "Propose a change (needs admin approval)",
      description: `Propose an improvement backed by evidence. It is saved as "pending" and does nothing until the admin approves it.
Before proposing, check spread_list_change_requests: a proposal rejected before with the same evidence is refused (already_rejected) — bring new evidence or drop it. An identical pending proposal is not duplicated.`,
      inputSchema: z.strictObject({
        type: z.enum(["prompt", "style", "ux", "code", "config", "content_rule"]),
        target: z.string().min(2).max(255).describe("what to change: prompt type, preset id, page, file, setting name…"),
        diff: z.string().max(60000).optional().describe("the exact change (unified diff or before → after)"),
        reason: z.string().min(10).max(4000),
        evidence: z.record(z.string(), z.unknown()).describe("the numbers and post/issue ids that justify it"),
        expected_impact: z.string().max(2000).optional(),
        priority: z.enum(["low", "medium", "high", "critical"]).default("medium"),
      }),
      annotations: WRITE_APPEND,
    },
    async (a, ctx) => jsonResult(await post("propose_change", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_draft_prompt",
    {
      title: "Draft a new prompt version",
      description: "Draft a full new version of a prompt type (from spread_list_prompt_templates). After admin approval it becomes a testing (A/B) version with a traffic percentage, or live.",
      inputSchema: z.strictObject({
        template_key: z.string().min(2).max(100),
        body: z.string().min(50).max(60000).describe("the complete prompt text, keeping the {variables} the current version uses"),
        reason: z.string().min(10).max(4000),
      }),
      annotations: WRITE_APPEND,
    },
    async (a, ctx) => jsonResult(await post("draft_prompt", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_draft_style",
    {
      title: "Draft a style preset change",
      description: "Draft a change to a style preset (or a new one without preset_id). payload has the same shape as data in spread_list_style_presets.",
      inputSchema: z.strictObject({
        preset_id: Id.optional(),
        payload: z.record(z.string(), z.unknown()),
        reason: z.string().min(10).max(4000),
      }),
      annotations: WRITE_APPEND,
    },
    async (a, ctx) => jsonResult(await post("draft_style", a, ctx.mcpReq.signal)),
  );

  server.registerTool(
    "spread_draft_reply",
    {
      title: "Draft a reply to a user's issue",
      description: "Draft a reply (Egyptian Arabic, friendly, concrete) to the user who reported an issue. It is sent only after the admin approves it. Never put security vulnerability details in a reply.",
      inputSchema: z.strictObject({
        issue_id: Id,
        body: z.string().min(10).max(3000),
        channel: z.enum(["inbox", "whatsapp"]).default("inbox"),
      }),
      annotations: WRITE_APPEND,
    },
    async (a, ctx) => jsonResult(await post("draft_reply", a, ctx.mcpReq.signal)),
  );
}

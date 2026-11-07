/**
 * مصنع الخادم: نسخة جديدة لكل طلب HTTP (أو لكل اتصال stdio). الأدوات اللي بتظهر = الصلاحيات اللي اتدّت للتوكن.
 */
import { McpServer, type AuthInfo } from "@modelcontextprotocol/server";
import { ALL_SCOPES, config, SCOPES } from "./config.js";
import { registerPrompts } from "./prompts.js";
import { toolHidden } from "./remote-config.js";
import { rubricMarkdown } from "./rubric.js";
import { registerDraftTools } from "./tools/drafts.js";
import { registerEvaluateTools } from "./tools/evaluate.js";
import { registerReadTools } from "./tools/read.js";

export const SERVER_INFO = { name: "spread-mcp-server", version: "1.0.0" };

const INSTRUCTIONS = `Spread AI quality, monitoring and security server (Egyptian Arabic content platform).
- Everything is read-only on the platform: you can read data, save evaluations/reports/analysis in the separate AI database, and (if enabled) create drafts that wait for admin approval. Nothing can be deleted, and credits, payments, subscriptions, accounts and affiliate balances are out of reach.
- Before scoring a post read rubric://content-quality. For the daily report use the daily_review prompt.
- Answer the user in Egyptian Arabic. Never print phone numbers or emails.`;

export function createServer(authInfo?: AuthInfo, off: Set<string> = new Set()): McpServer {
  const scopes = new Set(authInfo ? authInfo.scopes : ALL_SCOPES);
  const env = { client: authInfo ? `mcp:${authInfo.clientId}` : "mcp:stdio" };
  const server = new McpServer(SERVER_INFO, { instructions: INSTRUCTIONS });
  // الأدوات اللي الأدمن قفلها من مركز الجودة مابتتسجلش أصلًا (والموقع بيرفضها كمان)
  const register = server.registerTool.bind(server);
  server.registerTool = ((name: string, ...rest: unknown[]) =>
    toolHidden(name, off) ? undefined : (register as (...a: unknown[]) => unknown)(name, ...rest)) as typeof server.registerTool;

  const canRead = scopes.has(SCOPES.read);
  const canWrite = scopes.has(SCOPES.evaluate);
  const canDraft = scopes.has(SCOPES.draft) && config.enableDraftTools;

  if (canRead) registerReadTools(server, env);
  if (canWrite) registerEvaluateTools(server, env);
  if (canDraft) registerDraftTools(server, env);

  server.registerResource(
    "content-quality-rubric",
    "rubric://content-quality",
    { title: "معايير التقييم", description: "8 معايير للمحتوى + 6 للتصميم (1–10) وطريقة حساب الإجمالي", mimeType: "text/markdown" },
    async uri => ({ contents: [{ uri: uri.href, mimeType: "text/markdown", text: rubricMarkdown() }] }),
  );

  if (canRead) registerPrompts(server, { canWrite, canDraft });
  return server;
}

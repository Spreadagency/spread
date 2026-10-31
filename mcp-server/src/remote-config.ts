/**
 * إعدادات من الموقع نفسه (مركز الجودة ← الإعدادات): الأدوات اللي الأدمن قفلها.
 * بتتجاب من health كل دقيقة بالكتير — ولو الموقع مارّدش بنكمّل بآخر قيمة (والموقع نفسه بيرفض المقفول في كل الأحوال).
 */
import { iapi } from "./api.js";

let cache: { at: number; off: Set<string> } = { at: 0, off: new Set() };
let inflight: Promise<Set<string>> | null = null;

export async function disabledRoutes(): Promise<Set<string>> {
  if (Date.now() - cache.at < 60_000) return cache.off;
  inflight ??= iapi<{ disabled_routes?: unknown }>("health", "read", { client: "mcp:config" })
    .then(h => {
      const list = Array.isArray(h.disabled_routes) ? h.disabled_routes.filter((x): x is string => typeof x === "string") : [];
      cache = { at: Date.now(), off: new Set(list) };
      return cache.off;
    })
    .catch(e => {
      console.error(`[config] health: ${e instanceof Error ? e.message : e}`);
      cache = { ...cache, at: Date.now() - 45_000 }; // جرّب تاني بعد ربع دقيقة
      return cache.off;
    })
    .finally(() => { inflight = null; });
  return inflight;
}

/** أداة ← المسارات اللي بتستخدمها (بتستخبى لو كلهم مقفولين) */
export const TOOL_ROUTES: Record<string, string[]> = {
  spread_get_metrics: ["metrics"],
  spread_list_posts: ["posts"],
  spread_get_post: ["post"],
  spread_get_image: ["media"],
  spread_get_brand: ["brand"],
  spread_list_prompt_templates: ["prompt_templates"],
  spread_get_prompt_version: ["prompt_version"],
  spread_get_ab_results: ["ab_results"],
  spread_list_style_presets: ["style_presets"],
  spread_list_issues: ["issues"],
  spread_get_ai_errors: ["ai_errors"],
  spread_list_change_requests: ["change_requests"],
  spread_list_reports: ["reports"],
  spread_get_security_logs: ["security_logs"],
  spread_list_security_findings: ["security_scan", "dependency_audit"],
  spread_get_e2e_results: ["e2e_results"],
  spread_save_evaluation: ["save_evaluation"],
  spread_analyze_issue: ["analyze_issue"],
  spread_save_report: ["save_report"],
  spread_save_security_finding: ["save_security_finding"],
  spread_save_integrity_issue: ["save_integrity_issue"],
  spread_propose_change: ["propose_change"],
  spread_draft_prompt: ["draft_prompt"],
  spread_draft_style: ["draft_style"],
  spread_draft_reply: ["draft_reply"],
};

export function toolHidden(name: string, off: Set<string>): boolean {
  const r = TOOL_ROUTES[name];
  return !!r && r.length > 0 && r.every(x => off.has(x));
}

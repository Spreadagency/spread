/**
 * عميل الـ Internal API (PHP). كل طلب: Bearer + X-Scope (read / ai_write / draft) + X-Client.
 * الحماية الحقيقية في الـ PHP وفي MySQL نفسه — هنا بس بنبعت الصلاحية الصح لكل مسار.
 */
import { config } from "./config.js";

export type IapiScope = "read" | "ai_write" | "draft";
type Query = Record<string, string | number | boolean | undefined | null>;

export class ApiError extends Error {
  constructor(message: string, readonly status: number, readonly code: string) {
    super(message);
  }
}

function statusMessage(status: number): string {
  switch (status) {
    case 401: return "الـ Internal API رفض التوكن — اتأكد إن INTERNAL_API_TOKEN في .env هو نفسه اللي في includes/config.php.";
    case 403: return "الـ Internal API رفض الطلب (IP الخادم مش في INTERNAL_API_IPS أو الصلاحية مش مسموحة).";
    case 404: return "مش موجود.";
    case 429: return "طلبات كتير على الـ Internal API — استنى دقيقة وجرّب تاني.";
    case 501: return "الميزة دي لسه ماتعملتش في المرحلة دي.";
    default: return `الـ Internal API رجّع HTTP ${status} — جرّب تاني بعد شوية.`;
  }
}

function buildUrl(route: string, query: Query = {}): URL {
  const url = new URL(config.internalApiUrl + "/index.php");
  url.searchParams.set("r", route);
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null || v === "") continue;
    url.searchParams.set(k, typeof v === "boolean" ? (v ? "1" : "0") : String(v));
  }
  return url;
}

async function send(url: URL, scope: IapiScope, client: string, signal: AbortSignal | undefined, body?: unknown): Promise<Response> {
  const headers: Record<string, string> = {
    Authorization: `Bearer ${config.internalApiToken}`,
    "X-Internal-Token": config.internalApiToken,
    "X-Scope": scope,
    "X-Client": client.slice(0, 40),
    Accept: "application/json",
    "User-Agent": "spread-mcp-server/1.0",
  };
  if (body !== undefined) headers["Content-Type"] = "application/json";
  const timeout = AbortSignal.timeout(config.internalApiTimeoutMs);
  try {
    return await fetch(url, {
      method: body !== undefined ? "POST" : "GET",
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
      redirect: "error",
    });
  } catch (e) {
    if (e instanceof DOMException && e.name === "TimeoutError") {
      throw new ApiError(`الموقع ماردّش خلال ${Math.round(config.internalApiTimeoutMs / 1000)} ثانية — جرّب تاني أو ضيّق الفترة.`, 504, "timeout");
    }
    if (e instanceof DOMException && e.name === "AbortError") throw e;
    throw new ApiError("تعذّر الوصول للـ Internal API — اتأكد إن INTERNAL_API_URL صح وإن الموقع شغال.", 502, "unreachable");
  }
}

/** طلب JSON — بيرجع الرد كله (من غير ok) أو بيرمي ApiError برسالة الـ API نفسها (عربي وفيها الحل) */
export async function iapi<T = Record<string, unknown>>(
  route: string,
  scope: IapiScope,
  opts: { query?: Query; body?: unknown; client: string; signal?: AbortSignal },
): Promise<T> {
  const res = await send(buildUrl(route, opts.query), scope, opts.client, opts.signal, opts.body);
  const type = res.headers.get("content-type") ?? "";
  if (!type.includes("json")) throw new ApiError(statusMessage(res.status), res.status, "bad_response");
  const data = (await res.json()) as { ok?: boolean; error?: string; code?: string } & Record<string, unknown>;
  if (!res.ok || data.ok !== true) {
    throw new ApiError(data.error ? `${data.error}` : statusMessage(res.status), res.status, data.code ?? "error");
  }
  delete data.ok;
  return data as T;
}

export const MAX_IMAGE_BYTES = 4_500_000;

/** صورة (تصميم أو صورة بلاغ) من مسار media — بالتوكن، مش لينك عام */
export async function iapiMedia(path: string, client: string, signal?: AbortSignal): Promise<{ mimeType: string; data: string; bytes: number }> {
  const res = await send(buildUrl("media", { path }), "read", client, signal);
  const type = (res.headers.get("content-type") ?? "").split(";")[0].trim();
  if (!res.ok || !type.startsWith("image/")) {
    let msg = statusMessage(res.status);
    if (type.includes("json")) {
      const j = (await res.json()) as { error?: string };
      if (j.error) msg = j.error;
    }
    throw new ApiError(msg, res.status, "media");
  }
  const len = Number(res.headers.get("content-length") ?? 0);
  if (len > MAX_IMAGE_BYTES) {
    await res.body?.cancel();
    throw new ApiError(`الصورة كبيرة (${(len / 1e6).toFixed(1)}MB) — أكبر من الحد (${MAX_IMAGE_BYTES / 1e6}MB)`, 413, "too_large");
  }
  const buf = Buffer.from(await res.arrayBuffer());
  if (buf.length > MAX_IMAGE_BYTES) throw new ApiError("الصورة أكبر من الحد المسموح", 413, "too_large");
  return { mimeType: type, data: buf.toString("base64"), bytes: buf.length };
}

/** «media?path=uploads/…» أو «uploads/…» ← uploads/… */
export function mediaPath(ref: string): string {
  const s = ref.trim();
  const i = s.indexOf("path=");
  return i >= 0 ? decodeURIComponent(s.slice(i + 5).split("&")[0]) : s;
}

import * as Device from 'expo-device';

import { API_V1, APP_VERSION, PLATFORM, TIMEOUTS } from './config';
import { utf8ToBase64 } from './encoding';

/** Every backend reply: {ok:true,…} or {ok:false,error,code} (+ extra keys such as paywall) */
export type ApiPayload = { ok: boolean; error?: string; code?: string; [k: string]: unknown };

export class ApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly payload: ApiPayload | null;

  constructor(message: string, code: string, status: number, payload: ApiPayload | null = null) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.payload = payload;
  }

  get isNetwork() {
    return this.code === 'network' || this.code === 'timeout';
  }
  get isAuth() {
    return this.status === 401 || this.code === 'auth';
  }
  /** design/publishing needs an active plan */
  get isPaywall() {
    return this.code === 'subscription';
  }
  get isQuotaOrCredits() {
    return this.code === 'quota' || this.code === 'credits' || this.status === 402;
  }
}

export type FileField = { uri: string; name: string; type: string };
export type FormValue = string | number | boolean | null | undefined | FileField;

export type RequestOptions = {
  method?: 'GET' | 'POST';
  query?: Record<string, string | number | boolean | null | undefined>;
  /** JSON body — sent base64-encoded (X-Payload: b64) */
  json?: Record<string, unknown>;
  /** form-urlencoded / multipart body for the site's form endpoints */
  form?: Record<string, FormValue>;
  /** form fields to send base64-encoded (server decodes fields listed in _b64) */
  b64Fields?: string[];
  /** same key for every retry of ONE user action → the server never charges twice */
  idempotencyKey?: string;
  timeoutMs?: number;
  auth?: boolean;
  /** explicit token (e.g. the short-lived 2FA challenge) instead of the stored session token */
  token?: string;
};

let tokenGetter: () => string | null = () => null;
let unauthorizedHandler: (() => void) | null = null;

export function configureApi(opts: { getToken: () => string | null; onUnauthorized: () => void }) {
  tokenGetter = opts.getToken;
  unauthorizedHandler = opts.onUnauthorized;
}

const DEVICE_NAME = [Device.manufacturer, Device.modelName].filter(Boolean).join(' ').slice(0, 100) || 'Spread AI App';

function buildUrl(path: string, query?: RequestOptions['query']) {
  const qs = Object.entries(query ?? {})
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
    .join('&');
  return `${API_V1}/${path}${qs ? (path.includes('?') ? '&' : '?') + qs : ''}`;
}

function isFile(v: FormValue): v is FileField {
  return typeof v === 'object' && v !== null && 'uri' in v;
}

export async function apiRequest<T = ApiPayload>(path: string, opts: RequestOptions = {}): Promise<T & ApiPayload> {
  const method = opts.method ?? (opts.json || opts.form ? 'POST' : 'GET');
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-App-Platform': PLATFORM,
    'X-App-Version': APP_VERSION,
    'X-Device-Name': DEVICE_NAME,
  };
  const token = opts.token ?? (opts.auth === false ? null : tokenGetter());
  if (token) {
    headers.Authorization = `Bearer ${token}`;
    headers['X-Spread-Token'] = token; // some Apache/CGI setups drop Authorization
  }
  if (opts.idempotencyKey) headers['Idempotency-Key'] = opts.idempotencyKey;

  let body: string | FormData | undefined;
  if (opts.json) {
    headers['Content-Type'] = 'text/plain;charset=UTF-8';
    headers['X-Payload'] = 'b64';
    body = utf8ToBase64(JSON.stringify(opts.json));
  } else if (opts.form) {
    const entries = Object.entries(opts.form).filter(([, v]) => v !== undefined && v !== null);
    const b64 = new Set(opts.b64Fields ?? []);
    const hasFile = entries.some(([, v]) => isFile(v));
    const enc = (k: string, v: string) => (b64.has(k) ? utf8ToBase64(v) : v);
    const b64Used = entries.filter(([k, v]) => b64.has(k) && !isFile(v)).map(([k]) => k);
    if (hasFile) {
      const fd = new FormData();
      for (const [k, v] of entries) {
        if (isFile(v)) fd.append(k, v as unknown as Blob);
        else fd.append(k, enc(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v)));
      }
      if (b64Used.length) fd.append('_b64', b64Used.join(','));
      body = fd; // fetch sets the multipart boundary itself
    } else {
      headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
      const parts = entries.map(([k, v]) => {
        const s = typeof v === 'boolean' ? (v ? '1' : '0') : String(v);
        return `${encodeURIComponent(k)}=${encodeURIComponent(enc(k, s))}`;
      });
      if (b64Used.length) parts.push(`_b64=${encodeURIComponent(b64Used.join(','))}`);
      body = parts.join('&');
    }
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), opts.timeoutMs ?? TIMEOUTS.default);
  let res: Response;
  try {
    res = await fetch(buildUrl(path, opts.query), { method, headers, body, signal: controller.signal, credentials: 'omit' });
  } catch (e) {
    clearTimeout(timeout);
    const aborted = (e as { name?: string })?.name === 'AbortError';
    throw new ApiError(
      aborted ? 'الطلب أخد وقت أطول من المتوقع — اتأكد من النت وجرّب تاني' : 'مفيش اتصال بالإنترنت — اتأكد من النت وجرّب تاني',
      aborted ? 'timeout' : 'network',
      0,
    );
  }
  clearTimeout(timeout);

  let payload: ApiPayload | null = null;
  const text = await res.text();
  try {
    payload = text ? (JSON.parse(text) as ApiPayload) : null;
  } catch {
    payload = null;
  }
  if (!payload || typeof payload !== 'object') {
    throw new ApiError('السيرفر رجّع رد غير متوقع — جرّب تاني بعد شوية', res.status >= 500 ? 'server_error' : 'bad_response', res.status);
  }
  if (!res.ok || payload.ok === false) {
    const code = String(payload.code ?? (res.status === 401 ? 'auth' : 'error'));
    const err = new ApiError(String(payload.error ?? 'حصلت مشكلة — جرّب تاني'), code, res.status, payload);
    if (err.isAuth && token && !opts.token && unauthorizedHandler) unauthorizedHandler();
    throw err;
  }
  return payload as T & ApiPayload;
}

/* ── Shorthands for the three v1 entry points ── */

export const api = {
  auth: <T = ApiPayload>(action: string, opts: RequestOptions = {}) =>
    apiRequest<T>('auth.php', { ...opts, query: { action, ...(opts.query ?? {}) } }),
  app: <T = ApiPayload>(action: string, opts: RequestOptions = {}) =>
    apiRequest<T>('app.php', { ...opts, query: { action, ...(opts.query ?? {}) } }),
  /** proxy to an existing site endpoint (see backend public/api/v1/call.php allow-list) */
  call: <T = ApiPayload>(ep: string, opts: RequestOptions = {}) =>
    apiRequest<T>('call.php', { ...opts, query: { ep, ...(opts.query ?? {}) } }),
};

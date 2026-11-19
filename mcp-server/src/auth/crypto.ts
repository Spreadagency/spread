import { createHash, createHmac, randomBytes, scryptSync, timingSafeEqual } from "node:crypto";

export const b64url = (b: Buffer | string) => Buffer.from(b).toString("base64url");
export const sha256 = (s: string) => createHash("sha256").update(s).digest();
export const sha256hex = (s: string) => createHash("sha256").update(s).digest("hex");
export const randomToken = (bytes = 32) => randomBytes(bytes).toString("base64url");

/** مقارنة ثابتة الوقت لنصّين بأي طول */
export function safeEqual(a: string, b: string): boolean {
  return timingSafeEqual(sha256(a), sha256(b));
}

export function hmac(secret: string, data: string): string {
  return createHmac("sha256", secret).update(data).digest("base64url");
}

/** JWT HS256 */
export function signJwt(payload: Record<string, unknown>, secret: string): string {
  const head = b64url(JSON.stringify({ alg: "HS256", typ: "at+jwt" }));
  const body = b64url(JSON.stringify(payload));
  return `${head}.${body}.${hmac(secret, `${head}.${body}`)}`;
}

export function verifyJwt(token: string, secret: string): Record<string, unknown> | null {
  const parts = token.split(".");
  if (parts.length !== 3) return null;
  const [head, body, sig] = parts;
  if (!safeEqual(sig, hmac(secret, `${head}.${body}`))) return null;
  try {
    const h = JSON.parse(Buffer.from(head, "base64url").toString()) as { alg?: string };
    if (h.alg !== "HS256") return null;
    return JSON.parse(Buffer.from(body, "base64url").toString()) as Record<string, unknown>;
  } catch {
    return null;
  }
}

/** باسورد صاحب الموقع: scrypt:N:r:p:salt:hash (بيتعمل بـ npm run hash-password) */
export function verifyPassword(password: string, stored: string): boolean {
  const p = stored.split(":");
  if (p.length !== 6 || p[0] !== "scrypt") return false;
  const [N, r, pp] = [Number(p[1]), Number(p[2]), Number(p[3])];
  const salt = Buffer.from(p[4], "base64");
  const want = Buffer.from(p[5], "base64");
  const got = scryptSync(password.normalize("NFKC"), salt, want.length, { N, r, p: pp, maxmem: 256 * 1024 * 1024 });
  return got.length === want.length && timingSafeEqual(got, want);
}

/** PKCE S256 */
export function pkceOk(verifier: string, challenge: string): boolean {
  if (!/^[A-Za-z0-9\-._~]{43,128}$/.test(verifier)) return false;
  return safeEqual(b64url(sha256(verifier)), challenge);
}

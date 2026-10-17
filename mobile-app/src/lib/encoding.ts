/**
 * UTF-8 ⇄ base64 without relying on platform TextEncoder/btoa (Hermes, web and Node all behave the same).
 * The backend accepts base64 bodies (header X-Payload: b64) and base64 form fields (_b64=…) so that
 * long Arabic text is not blocked by mod_security on the shared host — same trick as the web client.
 */
const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

export function utf8Bytes(input: string): number[] {
  const out: number[] = [];
  for (let i = 0; i < input.length; i++) {
    let cp = input.charCodeAt(i);
    if (cp >= 0xd800 && cp <= 0xdbff && i + 1 < input.length) {
      const lo = input.charCodeAt(i + 1);
      if (lo >= 0xdc00 && lo <= 0xdfff) {
        cp = 0x10000 + ((cp - 0xd800) << 10) + (lo - 0xdc00);
        i++;
      }
    }
    if (cp < 0x80) out.push(cp);
    else if (cp < 0x800) out.push(0xc0 | (cp >> 6), 0x80 | (cp & 63));
    else if (cp < 0x10000) out.push(0xe0 | (cp >> 12), 0x80 | ((cp >> 6) & 63), 0x80 | (cp & 63));
    else out.push(0xf0 | (cp >> 18), 0x80 | ((cp >> 12) & 63), 0x80 | ((cp >> 6) & 63), 0x80 | (cp & 63));
  }
  return out;
}

export function base64FromBytes(bytes: number[]): string {
  let s = '';
  for (let i = 0; i < bytes.length; i += 3) {
    const a = bytes[i];
    const b = i + 1 < bytes.length ? bytes[i + 1] : 0;
    const c = i + 2 < bytes.length ? bytes[i + 2] : 0;
    const n = (a << 16) | (b << 8) | c;
    s += ALPHABET[(n >> 18) & 63] + ALPHABET[(n >> 12) & 63];
    s += i + 1 < bytes.length ? ALPHABET[(n >> 6) & 63] : '=';
    s += i + 2 < bytes.length ? ALPHABET[n & 63] : '=';
  }
  return s;
}

export function utf8ToBase64(input: string): string {
  return base64FromBytes(utf8Bytes(input));
}

/** RFC 4122 v4 id for Idempotency-Key (Math.random is fine: uniqueness, not secrecy) */
export function uuid(): string {
  const h = '0123456789abcdef';
  let s = '';
  for (let i = 0; i < 36; i++) {
    if (i === 8 || i === 13 || i === 18 || i === 23) s += '-';
    else if (i === 14) s += '4';
    else if (i === 19) s += h[(Math.random() * 4) | 8];
    else s += h[(Math.random() * 16) | 0];
  }
  return s;
}

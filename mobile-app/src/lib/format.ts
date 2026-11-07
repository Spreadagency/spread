/** Small, pure formatting helpers (unit-tested in __tests__/format.test.ts) */

const AR_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

/** The platform UI uses Western digits — keep them, only group thousands */
export function fmtNumber(n: number | null | undefined): string {
  if (n === null || n === undefined || Number.isNaN(n)) return '—';
  return Math.round(n)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

export function toArabicDigits(s: string): string {
  return s.replace(/\d/g, (d) => AR_DIGITS[Number(d)]);
}

const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

/** "2026-10-10 09:03:16" (server, Africa/Cairo) → "10 أكتوبر 2026" */
export function fmtDate(s: string | null | undefined, withTime = false): string {
  if (!s) return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(s);
  if (!m) return s;
  const [, y, mo, d, hh, mm] = m;
  let out = `${Number(d)} ${MONTHS[Number(mo) - 1] ?? mo} ${y}`;
  if (withTime && hh !== undefined) {
    const h = Number(hh);
    out += ` · ${((h + 11) % 12) + 1}:${mm} ${h < 12 ? 'ص' : 'م'}`;
  }
  return out;
}

/** "منذ 5 دقايق" style relative time; `now` injectable for tests */
export function timeAgo(s: string | null | undefined, now: Date = new Date()): string {
  if (!s) return '';
  const t = new Date(s.replace(' ', 'T') + (s.includes('+') || s.endsWith('Z') ? '' : '+03:00'));
  if (Number.isNaN(t.getTime())) return s;
  const sec = Math.max(0, Math.round((now.getTime() - t.getTime()) / 1000));
  if (sec < 60) return 'دلوقتي';
  const min = Math.round(sec / 60);
  if (min < 60) return min === 1 ? 'من دقيقة' : min === 2 ? 'من دقيقتين' : `من ${min} دقيقة`;
  const hr = Math.round(min / 60);
  if (hr < 24) return hr === 1 ? 'من ساعة' : hr === 2 ? 'من ساعتين' : `من ${hr} ساعة`;
  const day = Math.round(hr / 24);
  if (day === 1) return 'امبارح';
  if (day < 30) return `من ${day} يوم`;
  return fmtDate(s);
}

/** "4:5" → 0.8 (width / height) — used to size design previews */
export function ratioToAspect(r: string | null | undefined): number {
  const m = /^(\d+(?:\.\d+)?)\s*[:x×]\s*(\d+(?:\.\d+)?)$/.exec(String(r ?? '').trim());
  if (!m) return 1;
  const w = Number(m[1]);
  const h = Number(m[2]);
  return w > 0 && h > 0 ? w / h : 1;
}

/** semver-ish compare: -1 | 0 | 1 */
export function compareVersions(a: string, b: string): number {
  const pa = a.split('.').map((x) => parseInt(x, 10) || 0);
  const pb = b.split('.').map((x) => parseInt(x, 10) || 0);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] ?? 0) - (pb[i] ?? 0);
    if (d !== 0) return d > 0 ? 1 : -1;
  }
  return 0;
}

export function isValidEmail(s: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(s.trim());
}

/** Egyptian mobile numbers start with 01 and have 11 digits; international numbers are accepted with + */
export function isValidPhone(s: string): boolean {
  const d = s.replace(/[^\d+]/g, '');
  if (/^01\d{9}$/.test(d)) return true;
  return /^\+?\d{8,15}$/.test(d);
}

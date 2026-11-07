/** حد طلبات بسيط في الذاكرة (نافذة ثابتة) — كفاية لخادم واحد */
const buckets = new Map<string, { n: number; reset: number }>();

export function hit(key: string, max: number, windowMs: number): boolean {
  const now = Date.now();
  const b = buckets.get(key);
  if (!b || b.reset <= now) {
    buckets.set(key, { n: 1, reset: now + windowMs });
    return true;
  }
  b.n++;
  return b.n <= max;
}

export function count(key: string): number {
  const b = buckets.get(key);
  return b && b.reset > Date.now() ? b.n : 0;
}

setInterval(() => {
  const now = Date.now();
  for (const [k, b] of buckets) if (b.reset <= now) buckets.delete(k);
}, 60_000).unref();

/**
 * شكل ردود الأدوات: JSON (نص + structuredContent) — وتحت حد الحروف بتقليل العناصر، مش بقص النص.
 */
export const CHARACTER_LIMIT = 25_000;

type Json = unknown;

function longestArray(v: Json, path: (string | number)[] = []): { path: (string | number)[]; len: number; size: number } | null {
  let best: { path: (string | number)[]; len: number; size: number } | null = null;
  if (Array.isArray(v)) {
    const size = JSON.stringify(v).length;
    if (v.length > 1) best = { path, len: v.length, size };
    v.forEach((x, i) => {
      const c = longestArray(x, [...path, i]);
      if (c && (!best || c.size > best.size)) best = c;
    });
  } else if (v && typeof v === "object") {
    for (const [k, x] of Object.entries(v as Record<string, Json>)) {
      const c = longestArray(x, [...path, k]);
      if (c && (!best || c.size > best.size)) best = c;
    }
  }
  return best;
}

function getAt(v: Json, path: (string | number)[]): Json {
  return path.reduce<Json>((o, k) => (o as Record<string | number, Json>)[k], v);
}

/** بيقلّل أطول قايمة للنص لحد ما الرد يدخل في الحد — وبيكتب إيه اللي اتشال */
export function fit(data: Record<string, Json>): { data: Record<string, Json>; text: string } {
  const copy = structuredClone(data);
  let text = JSON.stringify(copy, null, 1);
  const trimmed: string[] = [];
  for (let guard = 0; text.length > CHARACTER_LIMIT && guard < 40; guard++) {
    const t = longestArray(copy);
    if (!t || t.len < 2) break;
    const arr = getAt(copy, t.path) as Json[];
    const keep = Math.ceil(arr.length / 2);
    arr.splice(keep);
    const label = t.path.join(".");
    if (!trimmed.includes(label)) trimmed.push(label);
    copy._truncated = { lists: trimmed, note: "الرد اتقلّل علشان الحجم — استخدم limit/cursor أو اسأل عن عنصر واحد بالتفصيل" };
    text = JSON.stringify(copy, null, 1);
  }
  return { data: copy, text };
}

export function jsonResult(data: Record<string, Json>) {
  const r = fit(data);
  return { content: [{ type: "text" as const, text: r.text }], structuredContent: r.data };
}

export function textResult(text: string) {
  return { content: [{ type: "text" as const, text }] };
}

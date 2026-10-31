import * as z from "zod/v4";

/** بيانات العميل اللي فتح الاتصال (بتتبعت للـ Internal API في X-Client وبتظهر في السجل) */
export interface ToolEnv {
  client: string;
}

export const Day = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "التاريخ بالشكل YYYY-MM-DD");
export const Id = z.number().int().min(1);
export const Limit = (def: number) => z.number().int().min(1).max(50).default(def).describe("عدد العناصر في الصفحة (1–50)");
export const Cursor = z.number().int().min(0).default(0).describe("بداية الصفحة — خده من next_cursor في الرد اللي فات");

export const READ = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false } as const;
export const WRITE_UPSERT = { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false } as const;
export const WRITE_APPEND = { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false } as const;

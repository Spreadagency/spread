/**
 * تخزين OAuth: العملاء المسجلين + refresh tokens (متخزنين hash بس) في data/oauth.json (صلاحيات 600).
 * الأكواد المؤقتة (5 دقايق) في الذاكرة بس.
 */
import { chmodSync, existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { config } from "../config.js";

export interface Client {
  client_id: string;
  client_secret_hash?: string;
  client_name: string;
  redirect_uris: string[];
  token_endpoint_auth_method: "none" | "client_secret_post" | "client_secret_basic";
  created_at: number;
  /** العميل الثابت من .env بيقبل أي redirect على الدومينات المسموحة */
  static?: boolean;
}

export interface RefreshEntry {
  client_id: string;
  scope: string[];
  exp: number;
  family: string;
  used: boolean;
}

interface Data {
  /** بيزيد مع «إلغاء كل الدخول» — أي access token برقم أقدم بيترفض */
  gen: number;
  clients: Record<string, Client>;
  refresh: Record<string, RefreshEntry>;
}

const file = join(config.dataDir, "oauth.json");
let data: Data = { gen: 1, clients: {}, refresh: {} };

export function load(): void {
  if (!existsSync(config.dataDir)) mkdirSync(config.dataDir, { recursive: true, mode: 0o700 });
  if (existsSync(file)) {
    try {
      data = { gen: 1, clients: {}, refresh: {}, ...(JSON.parse(readFileSync(file, "utf8")) as Partial<Data>) };
    } catch (e) {
      console.error(`[oauth] data/oauth.json بايظ — بيتعمل من جديد (${e instanceof Error ? e.message : e})`);
    }
  }
  cleanup();
}

function save(): void {
  const tmp = `${file}.tmp`;
  writeFileSync(tmp, JSON.stringify(data), { mode: 0o600 });
  renameSync(tmp, file);
  try { chmodSync(file, 0o600); } catch { /* windows */ }
}

export function cleanup(): void {
  const now = Math.floor(Date.now() / 1000);
  let changed = false;
  for (const [k, r] of Object.entries(data.refresh)) {
    if (r.exp < now) { delete data.refresh[k]; changed = true; }
  }
  if (changed) save();
}

export const gen = () => data.gen;
export const getClient = (id: string): Client | undefined => data.clients[id];
export const clientCount = () => Object.keys(data.clients).length;

export function addClient(c: Client): void {
  data.clients[c.client_id] = c;
  save();
}

export function addRefresh(hash: string, r: RefreshEntry): void {
  data.refresh[hash] = r;
  save();
}

export function getRefresh(hash: string): RefreshEntry | undefined {
  return data.refresh[hash];
}

export function markUsed(hash: string): void {
  const r = data.refresh[hash];
  if (r) { r.used = true; save(); }
}

/** refresh token اتستخدم مرتين = اتسرق ← بنلغي العيلة كلها */
export function revokeFamily(family: string): void {
  for (const [k, r] of Object.entries(data.refresh)) if (r.family === family) delete data.refresh[k];
  save();
}

export function revokeAll(): number {
  const n = Object.keys(data.refresh).length;
  data.refresh = {};
  data.gen++;
  save();
  return n;
}

setInterval(cleanup, 3600_000).unref();

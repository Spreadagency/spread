#!/usr/bin/env node
// بيلغي كل دخول Claude (access + refresh tokens). وقّف الخادم الأول:
//   sudo systemctl stop spread-mcp && npm run revoke-all && sudo systemctl start spread-mcp
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";

const file = join(resolve(process.env.MCP_DATA_DIR ?? "data"), "oauth.json");
if (!existsSync(file)) { console.log("مفيش دخول متسجّل أصلًا"); process.exit(0); }
const d = JSON.parse(readFileSync(file, "utf8"));
const n = Object.keys(d.refresh ?? {}).length;
d.refresh = {};
d.gen = (d.gen ?? 1) + 1;
writeFileSync(file, JSON.stringify(d), { mode: 0o600 });
console.log(`اتلغى ${n} دخول — Claude هيطلب الربط تاني. (العملاء المسجلين زي ما هم)`);

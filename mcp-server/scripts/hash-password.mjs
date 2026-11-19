#!/usr/bin/env node
// بيعمل MCP_OWNER_PASSWORD_HASH من باسورد بتكتبه (مابيظهرش على الشاشة ومابيتحفظش في أي حتة)
import { randomBytes, scryptSync } from "node:crypto";
import { createInterface } from "node:readline";

const N = 2 ** 17, r = 8, p = 1;
const rl = createInterface({ input: process.stdin, output: process.stderr, terminal: true });
rl._writeToOutput = s => { if (s.includes("الباسورد")) process.stderr.write(s); };
rl.question("الباسورد (12 حرف على الأقل): ", pw => {
  rl.close();
  process.stderr.write("\n");
  if (pw.length < 12) { console.error("قصير — 12 حرف على الأقل"); process.exit(1); }
  const salt = randomBytes(16);
  const hash = scryptSync(pw.normalize("NFKC"), salt, 32, { N, r, p, maxmem: 256 * 1024 * 1024 });
  console.log(`MCP_OWNER_PASSWORD_HASH=scrypt:${N}:${r}:${p}:${salt.toString("base64")}:${hash.toString("base64")}`);
});

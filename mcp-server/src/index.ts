#!/usr/bin/env node
/**
 * Spread AI — خادم الـ MCP (الجودة والمراقبة والأمان)
 *
 *   TRANSPORT=http  (الافتراضي — على الـ VPS ورا nginx)   →  https://<MCP_PUBLIC_URL>/mcp
 *   TRANSPORT=stdio (تجربة محلية / Claude Desktop)
 *
 * الخادم ده مابيكلمش MySQL أبدًا — كل حاجة عن طريق الـ Internal API بتاع الموقع.
 */
import type { NextFunction, Request, Response } from "express";
import { createMcpExpressApp, getOAuthProtectedResourceMetadataUrl, requireBearerAuth } from "@modelcontextprotocol/express";
import { toNodeHandler } from "@modelcontextprotocol/node";
import { createMcpHandler } from "@modelcontextprotocol/server";
import { serveStdio } from "@modelcontextprotocol/server/stdio";
import { config } from "./config.js";
import { mountOAuth, verifier } from "./auth/oauth.js";
import * as store from "./auth/store.js";
import { hit } from "./ratelimit.js";
import { createServer, SERVER_INFO } from "./server.js";

if (config.transport === "stdio") {
  serveStdio(() => createServer());
  console.error(`${SERVER_INFO.name} running on stdio`);
} else {
  store.load();
  const app = createMcpExpressApp({
    host: config.host,
    allowedHosts: [config.publicUrl.hostname, "localhost", "127.0.0.1"],
    allowedOrigins: [config.publicUrl.hostname, "localhost", "127.0.0.1"],
    jsonLimit: "1mb",
  });
  app.set("trust proxy", config.trustProxy);
  app.disable("x-powered-by");
  app.use((_req: Request, res: Response, next: NextFunction) => {
    res.set("X-Content-Type-Options", "nosniff").set("Referrer-Policy", "no-referrer").set("X-Robots-Tag", "noindex");
    next();
  });

  app.get("/", (_req, res) => void res.type("text").send("spread-mcp-server — MCP endpoint: /mcp"));
  app.get("/healthz", (_req, res) => void res.json({ ok: true, name: SERVER_INFO.name, version: SERVER_INFO.version }));

  mountOAuth(app);

  const handler = createMcpHandler(({ authInfo }) => createServer(authInfo), {
    onerror: e => console.error(`[mcp] ${e.message}`),
  });
  const nodeHandler = toNodeHandler(handler);

  const ipLimit = (req: Request, res: Response, next: NextFunction) =>
    hit(`ip:${req.ip}`, config.requestsPerMinute * 2, 60_000) ? next() : void res.status(429).json({ error: "rate_limited", error_description: "Too many requests — wait a minute" });
  const clientLimit = (req: Request, res: Response, next: NextFunction) =>
    hit(`client:${req.auth?.clientId}`, config.requestsPerMinute, 60_000) ? next() : void res.status(429).json({ error: "rate_limited", error_description: "Too many requests — wait a minute" });
  const log = (req: Request, res: Response, next: NextFunction) => {
    const t0 = Date.now();
    const b = req.body as { method?: string; params?: { name?: string; uri?: string } } | undefined;
    res.on("finish", () => {
      const what = b?.method ? `${b.method}${b.params?.name ? ` ${b.params.name}` : b.params?.uri ? ` ${b.params.uri}` : ""}` : req.method;
      console.error(`[mcp] ${new Date().toISOString()} ${req.auth?.clientId ?? "-"} ${what} → ${res.statusCode} ${Date.now() - t0}ms`);
    });
    next();
  };

  app.all(
    "/mcp",
    ipLimit,
    requireBearerAuth({ verifier, expectedResource: config.mcpUrl, resourceMetadataUrl: getOAuthProtectedResourceMetadataUrl(config.mcpUrl) }),
    clientLimit,
    log,
    (req: Request, res: Response) => void nodeHandler(req, res, req.body),
  );

  app.use((_req: Request, res: Response) => void res.status(404).json({ error: "not_found" }));
  app.use((err: Error, _req: Request, res: Response, _next: NextFunction) => {
    console.error(`[http] ${err.message}`);
    if (!res.headersSent) res.status(500).json({ error: "server_error" });
  });

  app.listen(config.port, config.host, () => {
    console.error(`${SERVER_INFO.name} listening on http://${config.host}:${config.port}/mcp — public: ${config.mcpUrl.href}`);
    console.error(`  OAuth: ${config.ownerPasswordHash ? "on" : "off"} · static tokens: ${config.staticTokens.length} · DCR: ${config.allowDcr ? "on" : "off"} · draft tools: ${config.enableDraftTools ? "on" : "off"}`);
  });
}

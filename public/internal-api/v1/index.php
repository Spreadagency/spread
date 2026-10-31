<?php
/**
 * Spread AI — Internal API v1 (لخادم الـ MCP بس)
 *   GET/POST /internal-api/v1/index.php?r=<route>
 *   Authorization: Bearer <INTERNAL_API_TOKEN> · X-Scope: read|ai_write|draft · X-Client: claude|n8n|test
 * المسارات والصلاحيات: includes/internal-api-routes.php · الدليل: docs/INTERNAL_API.md
 */
require_once __DIR__ . '/../../../includes/internal-api-routes.php';

$__routes = iapi_routes();
[$__route] = iapi_boot($__routes);
call_user_func($__routes[$__route][1]);
iapi_fail('المسار مارجّعش رد', 'server_error', 500);

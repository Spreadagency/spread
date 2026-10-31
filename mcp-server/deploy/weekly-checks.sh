#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
# Spread AI — الفحوصات الأسبوعية من الـ VPS (MCP المرحلة 8)
#   1) npm audit لمكتبات خادم الـ MCP
#   2) OWASP ZAP baseline (فحص سلبي — مابيهاجمش) على الدومين اللي في ZAP_TARGET
#   3) اختبار الموقع كامل (Playwright) لو بيانات حساب الاختبار موجودة
# وبعدين بيبعت النتايج للموقع ← «مركز الجودة ← الأمان» + Claude.
#
# بيشتغل لوحده كل أسبوع (spread-mcp-checks.timer) — أو يدوي:  sudo bash weekly-checks.sh
# الإعدادات: /opt/spread-mcp/checks.env  (chmod 600 — اتعمل بـ: sudo bash install.sh checks)
# ═══════════════════════════════════════════════════════════════════════════
set -uo pipefail

APP_ROOT=/opt/spread-mcp
APP=$APP_ROOT/app
TOOLS=$APP_ROOT/tools
OUT=$APP_ROOT/checks-out
USER_NAME=spreadmcp
CONF=$APP_ROOT/checks.env

[ "$(id -u)" -eq 0 ] || { echo "شغّله بـ sudo"; exit 1; }
[ -f "$CONF" ] || { echo "مفيش $CONF — شغّل: sudo bash $APP/deploy/install.sh checks"; exit 1; }
set -a; . "$CONF"; set +a

mkdir -p "$OUT"; chown "$USER_NAME:$USER_NAME" "$OUT"; chmod 700 "$OUT"
cd "$APP"
push() { chown "$USER_NAME:$USER_NAME" "$2"; sudo -u "$USER_NAME" node scripts/push-checks.mjs "$1" "$2"; }
STATUS=0

echo "▶ npm audit"
sudo -u "$USER_NAME" npm audit --json --omit=dev > "$OUT/npm-audit.json" 2>/dev/null || true
if [ -s "$OUT/npm-audit.json" ]; then push npm "$OUT/npm-audit.json" || STATUS=1; fi

if [ -n "${ZAP_TARGET:-}" ] && command -v docker >/dev/null; then
  echo "▶ OWASP ZAP baseline ← $ZAP_TARGET"
  rm -f "$OUT/zap-report.json"
  # -I: التحذيرات مابتعتبرش فشل · -m 3: دقايق الـ spider · فحص سلبي بس
  docker run --rm -v "$OUT":/zap/wrk:rw ghcr.io/zaproxy/zaproxy:stable \
    zap-baseline.py -t "$ZAP_TARGET" -J zap-report.json -m 3 -I >"$OUT/zap.log" 2>&1 || true
  if [ -s "$OUT/zap-report.json" ]; then push zap "$OUT/zap-report.json" || STATUS=1
  else echo "⚠ ZAP ماطلّعش تقرير — شوف $OUT/zap.log"; STATUS=1; fi
fi

if [ -n "${E2E_BASE:-}" ] && [ -d "$TOOLS/node_modules/playwright" ]; then
  echo "▶ اختبار الموقع ← $E2E_BASE"
  E2E_OUT="$OUT/e2e-results.json" PLAYWRIGHT_NODE_PATH="$TOOLS/node_modules" PLAYWRIGHT_BROWSERS_PATH="$TOOLS/browsers" \
    timeout 1200 node checks/site-e2e.mjs >"$OUT/e2e.log" 2>&1 || true
  if [ -s "$OUT/e2e-results.json" ]; then push e2e "$OUT/e2e-results.json" || STATUS=1
  else echo "⚠ الاختبار ماطلّعش نتيجة — شوف $OUT/e2e.log"; STATUS=1; fi
fi

echo "✓ خلصت ($(date '+%F %T'))"
exit $STATUS

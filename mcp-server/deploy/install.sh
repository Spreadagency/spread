#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
# Spread AI — تركيب خادم الـ MCP على VPS بأمر واحد (Ubuntu 22.04 / 24.04)
#
#   تركيب أول مرة:   sudo bash install.sh install mcp.ai.spreadagency.net you@email.com ai.spreadagency.net
#   تحديث الكود:      sudo bash install.sh update
#   الحالة:           sudo bash install.sh status
#   الفحوصات الأسبوعية (اختياري): sudo bash install.sh checks <دومين الموقع أو الـ staging>
#
# بيعمل: الحزم (nginx · certbot · Node 22) ← الجدار الناري ← مستخدم spreadmcp ← فك الكود ← npm ci + build
#        ← .env (بيسألك على توكن الموقع وباسورد صاحب الموقع، والباقي بيتولّد لوحده) ← systemd ← nginx ← HTTPS
# لازم الملف spread-mcp-server.zip يكون جنب السكريبت (أو حدد مكانه: ZIP=/path/file.zip).
# التحديث مابيلمسش .env ولا data/ (تسجيلات Claude).
# ═══════════════════════════════════════════════════════════════════════════
set -euo pipefail

APP_ROOT=/opt/spread-mcp
APP=$APP_ROOT/app
USER_NAME=spreadmcp
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ZIP="${ZIP:-$HERE/spread-mcp-server.zip}"
[ -f "$ZIP" ] || ZIP="$(pwd)/spread-mcp-server.zip"

say()  { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m⚠ %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m✕ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "شغّله بـ sudo"
MODE="${1:-}"

unpack_build() {
  [ -f "$ZIP" ] || die "مش لاقي spread-mcp-server.zip — ارفعه جنب السكريبت أو حدد ZIP=/path"
  say "فك الكود في $APP"
  mkdir -p "$APP"
  local tmp; tmp="$(mktemp -d)"
  unzip -oq "$ZIP" -d "$tmp"
  # .env و data/ مابيتلمسوش
  rsync -a --delete --exclude '.env' --exclude 'data/' --exclude 'node_modules/' "$tmp"/ "$APP"/ 2>/dev/null || cp -a "$tmp"/. "$APP"/
  rm -rf "$tmp"
  mkdir -p "$APP/data"
  chown -R "$USER_NAME:$USER_NAME" "$APP_ROOT"
  say "تنزيل المكتبات والبناء (دقيقة أو اتنين)"
  cd "$APP"
  sudo -u "$USER_NAME" npm ci --no-audit --no-fund --loglevel=error
  sudo -u "$USER_NAME" npm run build --silent
  ok "الكود جاهز"
}

case "$MODE" in
install)
  DOMAIN="${2:-}"; EMAIL="${3:-}"; SITE="${4:-}"
  [ -n "$DOMAIN" ] && [ -n "$EMAIL" ] && [ -n "$SITE" ] || die "الاستخدام: sudo bash install.sh install <دومين الـ MCP> <إيميلك> <دومين الموقع>"
  grep -qiE 'ubuntu' /etc/os-release || warn "السكريبت متجرّب على Ubuntu بس"

  say "تحديث السيرفر وتركيب الحزم"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq nginx certbot python3-certbot-nginx ufw unzip curl rsync ca-certificates dnsutils >/dev/null
  if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null
    apt-get install -y -qq nodejs >/dev/null
  fi
  ok "Node $(node -v) · nginx · certbot"

  say "الجدار الناري: SSH و HTTP/HTTPS بس"
  ufw allow OpenSSH >/dev/null; ufw allow 'Nginx Full' >/dev/null; ufw --force enable >/dev/null
  ok "ufw شغال"

  id "$USER_NAME" >/dev/null 2>&1 || adduser --system --group --home "$APP_ROOT" "$USER_NAME" >/dev/null
  unpack_build

  if [ ! -f "$APP/.env" ]; then
    say "الإعدادات (.env)"
    cp "$APP/.env.example" "$APP/.env"
    read -rsp "INTERNAL_API_TOKEN (نفس اللي في includes/config.php على الموقع): " IAPI; echo
    [ ${#IAPI} -ge 32 ] || die "التوكن قصير — انسخه كامل من config.php"
    echo "باسورد صاحب الموقع (بتكتبه لما توافق على ربط Claude — 12 حرف على الأقل):"
    HASHLINE="$(cd "$APP" && sudo -u "$USER_NAME" node scripts/hash-password.mjs)"
    JWT="$(openssl rand -hex 32)"; STATIC="$(openssl rand -hex 32)"
    sed -i \
      -e "s#^MCP_PUBLIC_URL=.*#MCP_PUBLIC_URL=https://$DOMAIN#" \
      -e "s#^INTERNAL_API_URL=.*#INTERNAL_API_URL=https://$SITE/internal-api/v1#" \
      -e "s#^INTERNAL_API_TOKEN=.*#INTERNAL_API_TOKEN=$IAPI#" \
      -e "s#^MCP_OWNER_PASSWORD_HASH=.*#$HASHLINE#" \
      -e "s#^MCP_JWT_SECRET=.*#MCP_JWT_SECRET=$JWT#" \
      -e "s#^MCP_STATIC_TOKENS=.*#MCP_STATIC_TOKENS=$STATIC#" \
      -e "s#^MCP_ENABLE_DRAFT_TOOLS=.*#MCP_ENABLE_DRAFT_TOOLS=true#" \
      "$APP/.env"
    chown "$USER_NAME:$USER_NAME" "$APP/.env"; chmod 600 "$APP/.env"
    ok ".env اتعمل (chmod 600)"
  else
    ok ".env موجود — مالمستوش"
  fi

  say "تشغيل الخادم (systemd)"
  cp "$APP/deploy/spread-mcp.service" /etc/systemd/system/spread-mcp.service
  systemctl daemon-reload
  systemctl enable --now spread-mcp >/dev/null 2>&1
  systemctl restart spread-mcp
  sleep 3
  curl -fsS http://127.0.0.1:3000/healthz >/dev/null && ok "الخادم شغال على 127.0.0.1:3000" || die "الخادم ماقامش — شوف: journalctl -u spread-mcp -n 50"

  say "nginx"
  sed "s#mcp.ai.spreadagency.net#$DOMAIN#g" "$APP/deploy/nginx-mcp.conf" > /etc/nginx/sites-available/spread-mcp
  ln -sf /etc/nginx/sites-available/spread-mcp /etc/nginx/sites-enabled/spread-mcp
  rm -f /etc/nginx/sites-enabled/default
  nginx -t -q && systemctl reload nginx
  ok "nginx جاهز"

  say "HTTPS (Let's Encrypt)"
  MYIP="$(curl -fsS https://api.ipify.org || true)"
  DNSIP="$(dig +short "$DOMAIN" | tail -1)"
  if [ -n "$MYIP" ] && [ "$MYIP" = "$DNSIP" ]; then
    certbot --nginx -d "$DOMAIN" --redirect -m "$EMAIL" --agree-tos -n -q && ok "الشهادة اتعملت وبتتجدد لوحدها"
  else
    warn "الدومين $DOMAIN لسه مش شاور على IP السيرفر ($MYIP ← DNS: ${DNSIP:-مفيش})"
    warn "لما الـ DNS يشتغل شغّل: sudo certbot --nginx -d $DOMAIN --redirect -m $EMAIL --agree-tos -n"
  fi

  STATIC_NOW="$(grep '^MCP_STATIC_TOKENS=' "$APP/.env" | cut -d= -f2 | cut -d, -f1)"
  cat <<EOF

═════════════════════════════════════════════════════════════
 ✓ خادم الـ MCP اتركّب

 1) على الموقع (includes/config.php):
      define('INTERNAL_API_IPS', '$MYIP');
 2) اختبار (من هنا):
      cd $APP && sudo -u $USER_NAME env MCP_URL=https://$DOMAIN MCP_TOKEN=$STATIC_NOW npm run smoke
 3) في Claude: Settings ← Connectors ← Add custom connector
      الاسم: Spread AI   ·   العنوان: https://$DOMAIN/mcp
 4) التوكن الثابت (لـ Claude Code والاختبار) — احفظه في مكان آمن:
      $STATIC_NOW
═════════════════════════════════════════════════════════════
EOF
  ;;

update)
  id "$USER_NAME" >/dev/null 2>&1 || die "مفيش تركيب قبل كده — استخدم install"
  unpack_build
  cp "$APP/deploy/spread-mcp.service" /etc/systemd/system/spread-mcp.service
  [ -f /etc/systemd/system/spread-mcp-checks.timer ] && cp "$APP/deploy/spread-mcp-checks.service" "$APP/deploy/spread-mcp-checks.timer" /etc/systemd/system/
  systemctl daemon-reload
  systemctl restart spread-mcp
  sleep 3
  curl -fsS http://127.0.0.1:3000/healthz >/dev/null && ok "اتحدّث وشغال" || die "ماقامش — journalctl -u spread-mcp -n 50"
  ;;

checks)
  # npm audit + OWASP ZAP (Docker) + اختبار الموقع (Playwright) كل أسبوع ← النتايج في «الأمان»
  TARGET="${2:-}"
  [ -n "$TARGET" ] || die "الاستخدام: sudo bash install.sh checks <دومين الموقع أو الـ staging>  (مثال: staging.ai.spreadagency.net)"
  [ -f "$APP/.env" ] || die "ركّب الخادم الأول: sudo bash install.sh install …"
  TARGET="${TARGET#https://}"; TARGET="${TARGET%%/*}"
  export DEBIAN_FRONTEND=noninteractive
  say "Docker (علشان OWASP ZAP)"
  command -v docker >/dev/null || { apt-get update -qq; apt-get install -y -qq docker.io >/dev/null; systemctl enable --now docker >/dev/null 2>&1; }
  docker pull -q ghcr.io/zaproxy/zaproxy:stable >/dev/null && ok "ZAP جاهز"
  say "Playwright + Chromium (علشان اختبار الموقع) — دقايق"
  mkdir -p "$APP_ROOT/tools"; cd "$APP_ROOT/tools"
  [ -f package.json ] || echo '{"name":"spread-checks-tools","private":true}' > package.json
  npm i --no-audit --no-fund --loglevel=error playwright@1 >/dev/null
  PLAYWRIGHT_BROWSERS_PATH="$APP_ROOT/tools/browsers" npx -y playwright install --with-deps chromium >/dev/null
  ok "Playwright جاهز"
  CONF="$APP_ROOT/checks.env"
  if [ ! -f "$CONF" ]; then
    cat > "$CONF" <<CONF
# الفحوصات الأسبوعية — الملف ده فيه باسوردات حساب الاختبار: chmod 600 ومايتنقلش لأي حتة.
# الهدف: يفضّل نسخة staging (docs/STAGING.md). فحص ZAP baseline سلبي (بيقرا بس) فآمن على الموقع الأساسي كمان.
ZAP_TARGET=https://$TARGET
E2E_BASE=https://$TARGET
# حساب عميل اختبار (مش حساب عميل حقيقي) — سيبهم فاضيين لو مش عايز اختبار صفحات العميل
E2E_USER=
E2E_PASS=
# حساب أدمن اختبار بصلاحية «مشاهدة» — سيبهم فاضيين لو مش عايز اختبار صفحات الأدمن
E2E_ADMIN=
E2E_ADMIN_PASS=
CONF
    chmod 600 "$CONF"
    ok "اتعمل $CONF — افتحه (sudo nano $CONF) وحط حسابات الاختبار لو عايز"
  else ok "$CONF موجود — مالمستوش"; fi
  cp "$APP/deploy/spread-mcp-checks.service" "$APP/deploy/spread-mcp-checks.timer" /etc/systemd/system/
  systemctl daemon-reload
  systemctl enable --now spread-mcp-checks.timer >/dev/null 2>&1
  ok "اتجدولت كل جمعة الفجر — تشغيل دلوقتي: sudo systemctl start spread-mcp-checks  ·  اللوج: journalctl -u spread-mcp-checks -n 50"
  ;;

status)
  systemctl --no-pager --lines=0 status spread-mcp || true
  systemctl list-timers spread-mcp-checks.timer --no-pager 2>/dev/null | head -3 || true
  curl -fsS http://127.0.0.1:3000/healthz && echo
  journalctl -u spread-mcp -n 15 --no-pager || true
  ;;

*)
  sed -n '3,13p' "$0"
  exit 1
  ;;
esac

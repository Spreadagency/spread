#!/usr/bin/env bash
# Spread AI — تشغيل البيئة المحلية (للتطوير بس): MariaDB + الموقع على 8080 + الـ AI الوهمي على 8090
#   bash tests/dev-up.sh
# أول مرة: انسخ includes/config.sample.php ← includes/config.php (APP_DEBUG true وقاعدة spread_app) وبعدين: php tests/dev-setup.php
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG="$ROOT/storage/logs"
mkdir -p "$LOG" /run/mysqld
if ! mariadb -uroot -e 'SELECT 1' >/dev/null 2>&1; then
  chown mysql:mysql /run/mysqld 2>/dev/null
  (setsid mysqld_safe --user=mysql >/dev/null 2>&1 &)
  for i in $(seq 1 20); do mariadb -uroot -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
fi
curl -s -o /dev/null http://127.0.0.1:8080/public/login.php || (setsid nohup php -S 127.0.0.1:8080 -t "$ROOT" "$ROOT/tests/dev-router.php" >"$LOG/php-server.log" 2>&1 </dev/null &)
curl -s -o /dev/null http://127.0.0.1:8090/v1/models || (cd "$ROOT" && setsid nohup php -S 127.0.0.1:8090 tests/mock-ai.php >"$LOG/mock-ai-server.log" 2>&1 </dev/null &)
sleep 1
printf 'db: %s · site: %s · mock-ai: %s\n' \
  "$(mariadb -uroot -N -e 'SELECT 1' 2>/dev/null || echo down)" \
  "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/public/login.php)" \
  "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8090/v1/models)"

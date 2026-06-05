#!/bin/sh
set -e

CONFIG=/var/www/html/config.php

# config.php aus Umgebungsvariablen erzeugen, falls noch nicht vorhanden und
# DB-Variablen gesetzt sind. Sonst kann der Web-Installer (install.php) genutzt
# werden. APP_KEY wird einmalig generiert und sollte als ENV persistiert werden.
if [ ! -f "$CONFIG" ] && [ -n "$DB_HOST" ] && [ -n "$DB_NAME" ]; then
  if [ -z "$APP_KEY" ]; then
    APP_KEY=$(php -r 'echo base64_encode(random_bytes(32));')
    echo "[entrypoint] WARN: kein APP_KEY gesetzt – generiere einmaligen Schluessel."
    echo "[entrypoint]       Fuer dauerhaften Betrieb APP_KEY als ENV setzen: $APP_KEY"
  fi
  cat > "$CONFIG" <<PHP
<?php
define('DB_HOST', getenv('DB_HOST') ?: '${DB_HOST}');
define('DB_NAME', getenv('DB_NAME') ?: '${DB_NAME}');
define('DB_USER', getenv('DB_USER') ?: '${DB_USER}');
define('DB_PASS', getenv('DB_PASS') ?: '${DB_PASS}');
define('APP_KEY', getenv('APP_KEY') ?: '${APP_KEY}');
define('SESSION_LIFETIME', (int)(getenv('SESSION_LIFETIME') ?: 3600));
date_default_timezone_set(getenv('TZ') ?: 'Europe/Berlin');
PHP
  chown www-data:www-data "$CONFIG"
  echo "[entrypoint] config.php aus ENV erzeugt."
fi

exec "$@"

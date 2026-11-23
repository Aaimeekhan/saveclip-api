#!/bin/sh
# Render gives the port in $PORT; update yt-dlp on every boot (sites change often)
sed -ri "s/^Listen .*/Listen ${PORT:-10000}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf
echo "PassEnv ALLOWED_ORIGINS RATE_LIMIT MAX_DURATION COOKIES_FILE YTDLP_BIN" > /etc/apache2/conf-enabled/saveclip-env.conf
/usr/local/bin/yt-dlp -U >/dev/null 2>&1 || true
exec apache2-foreground

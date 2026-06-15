#!/bin/sh
set -e
php /var/www/html/artisan --version || echo "Artisan failed"
# If Railway provides a PORT variable, inject it into the default Nginx config template
if [ -n "$PORT" ]; then
  echo "Injecting Railway assigned port ($PORT) into Nginx configuration..."
  envsubst '${PORT}' < /etc/nginx/sites-available/default > /etc/nginx/sites-available/default.tmp
  mv /etc/nginx/sites-available/default.tmp /etc/nginx/sites-available/default
else
  echo "No dynamic PORT variable found. Defaulting to 80."
  export PORT=80
  envsubst '${PORT}' < /etc/nginx/sites-available/default > /etc/nginx/sites-available/default.tmp
  mv /etc/nginx/sites-available/default.tmp /etc/nginx/sites-available/default
fi

# Hand over execution to supervisor to manage PHP-FPM and Nginx
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf

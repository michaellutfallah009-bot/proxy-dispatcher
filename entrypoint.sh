#!/bin/sh
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

php /var/www/html/artisan migrate --force || echo "Migration warning (non-fatal)"
php /var/www/html/artisan config:cache
php /var/www/html/artisan route:cache
php /var/www/html/artisan db:show --no-ansi 2>&1 || echo "DB CONNECTION FAILED"
# Hand over execution to supervisor to manage PHP-FPM and Nginx
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf

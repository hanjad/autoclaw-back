#!/bin/sh
# Runs every time the container starts: prepare Laravel, migrate, then start Apache.
set -e
cd /var/www/html

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set. Generate one locally with: php artisan key:generate --show" >&2
  exit 1
fi

# Render provides the public URL of the service; use it if APP_URL wasn't set
if [ -z "$APP_URL" ] && [ -n "$RENDER_EXTERNAL_URL" ]; then
  export APP_URL="$RENDER_EXTERNAL_URL"
fi

# A mounted disk starts empty, so make sure the folders Laravel needs exist
mkdir -p storage/app/public storage/app/private \
         storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# public/storage -> storage/app/public (product images and logos)
php artisan storage:link --force >/dev/null 2>&1 || true

# Cache config, routes and views from the environment variables this container was started with
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force
fi

# First admin on an empty database (free plans have no shell). Does nothing if the admin exists.
# Remove INITIAL_ADMIN_* from the environment after the first successful start.
if [ -n "$INITIAL_ADMIN_EMAIL" ] && [ -n "$INITIAL_ADMIN_PASSWORD" ]; then
  php artisan app:create-admin || true
fi

# Files created above belong to root; Apache runs as www-data
chown -R www-data:www-data storage bootstrap/cache

exec "$@"

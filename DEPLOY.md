# Deploying Autoclaw

## Backend (Laravel)

### Server needs
PHP 8.3 (extensions: mbstring, xml, curl, zip, gd, bcmath, intl, pdo_mysql, fileinfo), Composer, MySQL 8 or PostgreSQL,
Nginx, Supervisor, and a TLS certificate (Let's Encrypt). Point the domain's document root at `public/`.

### First deploy
    git clone <repo> && cd autoclaw-back
    composer install --no-dev --optimize-autoloader
    cp .env.production.example .env      # then fill it in (APP_DEBUG=false!)
    php artisan key:generate
    php artisan migrate --force
    php artisan storage:link
    php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
    chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rwX storage bootstrap/cache

Create the first admin with `php artisan tinker` (role = admin, email_verified_at = now()); after that, invite admins from /admin/invites.

### Every update
    git pull
    composer install --no-dev --optimize-autoloader
    php artisan migrate --force
    php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
    php artisan queue:restart

Config is cached, so changes to `.env` only apply after `php artisan config:cache`.

### Nginx (inside the server block)
    root /var/www/autoclaw-back/public;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
    location ~ /\.(?!well-known).* { deny all; }
    client_max_body_size 5M;      # product/logo uploads are capped at 2 MB

### Queue worker (once emails/jobs are queued) - Supervisor
    [program:autoclaw-queue]
    command=php /var/www/autoclaw-back/artisan queue:work --sleep=3 --tries=3 --max-time=3600
    user=www-data
    autostart=true
    autorestart=true

### Scheduler (cron)
    * * * * * cd /var/www/autoclaw-back && php artisan schedule:run >> /dev/null 2>&1

### Backups
Nightly `mysqldump` copied off the server. Also back up `storage/app/public` (product images, logos) and
`storage/app/private` (shop verification documents: ID, selfie, proof of address, CAC certificate).
The private folder must never be served by Nginx; only the admin-only API endpoint reads it.
Test a restore at least once.

### After deploying, check
- `https://api.yourdomain.com/up` returns 200.
- A bad request returns JSON with no stack trace (APP_DEBUG is false).
- From the real frontend domain, login works; from another origin the browser blocks the call (CORS).
- Six wrong codes in a minute on /api/verify returns 429.

### Not production-ready yet
Orders and payments, queued emails (mail currently sends during the request), image storage on S3 or Cloudinary
(images are on the server disk; they use the "public" disk in 3 places), automated tests, error monitoring (Sentry).

## Frontend (React + Vite)
    npm ci
    VITE_API_URL=https://api.yourdomain.com npm run build    # the URL is baked in at build time
    # upload dist/ to Netlify, Vercel, Cloudflare Pages or Nginx

Single-page app: unknown paths must serve index.html (`public/_redirects` handles Netlify/Cloudflare Pages;
on Nginx use `try_files $uri /index.html;`). Never commit `.env` files.

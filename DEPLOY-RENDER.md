# Hosting the API on Render

The repo root already has everything Render needs: `Dockerfile`, `docker/`, `render.yaml` and `.dockerignore`.

## 0. What each file is for

Only the first group is needed to run on Render. The rest are guides.

**The "box" that holds your app (all of these are copied into the image)**

| File | What it does |
|---|---|
| `Dockerfile` | The recipe for the box: PHP 8.3 + Apache, then your code, then your Composer packages |
| `.dockerignore` | What to leave **out** of the box: `.env` secrets, `vendor`, `.git`, logs |
| `docker/vhost.conf` | Tells Apache to serve Laravel's `public/` folder and send logs to Render's Logs tab |
| `docker/ports.conf` | Tells Apache which port to listen on (Render's `$PORT`) |
| `docker/apache-extra.conf` | Small clean-up: hides the Apache version number |
| `docker/php.ini` | PHP limits: uploads up to 6 MB per file, 26 MB per request (a verification has 4 files), errors hidden from visitors |
| `docker/entrypoint.sh` | Runs every time the box starts: checks `APP_KEY`, caches config, runs migrations, creates the first admin if you set `INITIAL_ADMIN_*`, then starts Apache |

**Telling Render what to create**

| File | What it does |
|---|---|
| `render.yaml` | Optional "Blueprint": one click creates the web service and the database and fills in the database settings. Without it you can create both by hand in the dashboard |
| `.gitattributes` | Keeps Linux line endings on scripts, so `entrypoint.sh` doesn't break when checked out on Windows |

**App code added for hosting**

| File | What it does |
|---|---|
| `app/Console/Commands/CreateAdmin.php` | `php artisan app:create-admin` makes your first admin (the entrypoint calls it) |
| `config/marketplace.php`, `app/Support/Media.php` | Settings for where uploads are stored, so you can move to cloud storage later without touching code |

**Guides (not used by the server)**: `DEPLOY-RENDER.md` (this file), `DEPLOY.md` (an older guide for a plain VPS, ignore it for Render),
`.env.production.example` (a list of the variables to set; Render reads them from its dashboard, not from this file).

## Step by step on Render

1. **On your computer:** apply the updates (`APPLY-UPDATES.md`), test them, and merge to `main`. Render deploys from the branch you pick.
2. **Push to GitHub.** `Dockerfile` and `render.yaml` must be in the repo root (the same folder as `artisan`).
3. **Make your app key:** run `php artisan key:generate --show`, copy the whole `base64:...` line and keep it somewhere safe. Never change it later.
4. **Render:** sign up, choose **New**, then **Blueprint**, connect GitHub and pick `autoclaw-back`.
5. **Fill the three boxes Render shows:** `APP_KEY` (step 3), `FRONTEND_URL` and `CORS_ALLOWED_ORIGINS` (your frontend's address; use `http://localhost:5173` for a first test). Click **Apply**.
6. **Wait 5 to 10 minutes** for the first build. Open the service's **Logs** tab. You should see the migrations run, then Apache start.
7. **Add your first admin:** in the service's **Environment** tab add `INITIAL_ADMIN_EMAIL` and `INITIAL_ADMIN_PASSWORD` (12+ characters), save (this redeploys), and look for "Admin created" in Logs. Then delete both variables.
8. **Check it:** open `https://<your-service>.onrender.com/up` (should load) and `.../api/products` (should show JSON).
9. **Host the frontend** (Vercel, Netlify or Cloudflare Pages) with the environment variable `VITE_API_URL` set to your Render address, build command `npm run build`, output folder `dist`.
10. **Come back to Render** and set `FRONTEND_URL` and `CORS_ALLOWED_ORIGINS` to the frontend's real address. Saving redeploys. Log in from the live site.

## 1. Pick your plan first

| | Free (testing) | Paid (real users) |
|---|---|---|
| Web service | Spins down after 15 min idle; the next visit takes about a minute | Always on, from about $7/month |
| Uploaded files | **Lost on every restart or deploy** | Kept if you attach a persistent disk |
| Database | Free Postgres **expires 30 days after creation** (14 days grace) | Paid Postgres plans, no expiry |
| Outbound email (SMTP ports 25, 465, 587) | **Blocked** | 465 and 587 work (25 stays blocked) |
| Shell and one-off jobs | Not available | Available |

Use the free plan to prove the container works. Before real vendors upload ID documents, move the web service and the
database to paid plans and attach the disk (section 5). Pricing changes, so check render.com/pricing.

## 2. Deploy

1. Push the repo to GitHub with these files in the root.
2. On your computer run `php artisan key:generate --show` and keep the result (`base64:...`). **Never change it later**:
   bank account numbers are stored encrypted with it and become unreadable if it changes.
3. Render, **New, Blueprint**, choose the repo. Render reads `render.yaml` and asks for the values marked `sync: false`:
   - `APP_KEY`: the value from step 2
   - `FRONTEND_URL`: your site, e.g. `https://autoclaw.example.com` (no trailing slash; use your Vercel/Netlify URL for now)
   - `CORS_ALLOWED_ORIGINS`: the same value
4. Optional but needed on an empty database: add `INITIAL_ADMIN_EMAIL` and `INITIAL_ADMIN_PASSWORD` (12+ characters)
   in the service's Environment tab before the first deploy. The container creates that admin on startup if it doesn't
   exist. **Delete both variables after your first successful login.**
5. Click Apply and wait. The container caches the config, runs `php artisan migrate --force`, then starts Apache.
6. Open `https://<your-service>.onrender.com/up`. It should return 200.

Don't run `ProductSeeder` in production: it creates demo vendors and products.

## 3. Connect the frontend

Build the React app with `VITE_API_URL=https://<your-service>.onrender.com` (this value is baked into the build), host it
(Vercel, Netlify, Cloudflare Pages), then set `FRONTEND_URL` and `CORS_ALLOWED_ORIGINS` on the API to that site's exact
origin. Saving environment variables redeploys the service.

## 4. Email (the free plan can't use SMTP)

Pick one:

- **Testing:** leave `MAIL_MAILER=log`. Verification codes and invite links appear in Render's **Logs** tab.
- **Resend over HTTPS (works on free):** run `composer require resend/resend-php` locally and commit `composer.json` and
  `composer.lock`. Verify your sending domain in Resend, then set `MAIL_MAILER=resend`, `RESEND_API_KEY` and
  `MAIL_FROM_ADDRESS` (an address on that domain).
- **Your own SMTP server** (e.g. mail.6bytes.site): needs a paid web service. Set `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtps`,
  `MAIL_HOST`, `MAIL_PORT=465`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`.

Mail is sent while the request waits. If your mail provider is slow, registration is slow too; a queue is the fix later.

## 5. Keeping uploads (paid plan)

Product images, shop logos and **verification documents** live in `storage/app`. In `render.yaml` uncomment the `disk:`
block (mount path `/var/www/html/storage/app`) and redeploy. A service with a disk runs as a single instance, which is
fine for now. Later you can move files to object storage: set `PUBLIC_DISK` and `PRIVATE_DISK` to disks defined in
`config/filesystems.php`. Use two separate buckets, because verification documents must never be public.

## 6. Environment variables

| Variable | Where | Notes |
|---|---|---|
| `APP_KEY` | you | `php artisan key:generate --show`. Keep it forever |
| `APP_ENV`, `APP_DEBUG` | render.yaml | `production` and `false`. Never `true` online |
| `APP_URL` | optional | Defaults to Render's `RENDER_EXTERNAL_URL`. Set it when you add a custom domain |
| `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS` | you | Exact frontend origin |
| `DB_*` | render.yaml | Filled from the Render database automatically |
| `LOG_CHANNEL=stderr` | render.yaml | Logs go to the Logs tab |
| `MAIL_*`, `RESEND_API_KEY` | dashboard | See section 4 |
| `PAYSTACK_SECRET_KEY` | dashboard | Optional. Looks up bank account names |
| `MARKETPLACE_REQUIRE_VERIFIED` | render.yaml | `true` shows only verified shops to buyers |
| `SANCTUM_EXPIRATION` | render.yaml | Login lifetime in minutes (10080 = 7 days) |
| `RUN_MIGRATIONS` | render.yaml | `true` migrates on every start (fine for one instance) |
| `INITIAL_ADMIN_EMAIL`, `INITIAL_ADMIN_PASSWORD` | dashboard | First boot only, then delete |

## 7. Try the image on your computer (optional, needs Docker Desktop)

    docker build -t autoclaw-api .
    docker run --rm -p 10000:10000 -e APP_KEY=base64:YOUR_KEY -e APP_ENV=production -e APP_DEBUG=false -e LOG_CHANNEL=stderr -e DB_CONNECTION=sqlite -e DB_DATABASE=/tmp/app.sqlite autoclaw-api

Then open http://localhost:10000/up.

## 8. If something goes wrong

| Symptom | Likely cause |
|---|---|
| Deploy exits with "APP_KEY is not set" | Add `APP_KEY` in Environment |
| `could not translate host name` | Web service and database are in different regions. Use the Blueprint (both in frankfurt) |
| Browser shows a CORS error | `CORS_ALLOWED_ORIGINS` must match the frontend origin exactly: https, no trailing slash |
| Images or documents vanish after a restart | Free plan, no disk (section 5) |
| No emails arrive | Section 4. SMTP is blocked on the free plan |
| First request is very slow | The free service was asleep |
| `exec format error` or `\r` errors on start | A script got Windows line endings. `.gitattributes` prevents this; re-checkout the files |

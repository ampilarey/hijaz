# Hijaz

The Laravel application behind **https://hijaz.rihla.mv**, a subdomain of
[rihla.mv](https://rihla.mv).

The website itself is not written yet. What is here is the foundation it will
be built on: a Laravel 13 skeleton, CI, and the same auto-deploy pipeline that
runs `test.rihla.mv`.

| Doc | What it covers |
|---|---|
| [docs/SERVER_SETUP.md](docs/SERVER_SETUP.md) | One-time cPanel setup: database, document root, SSL, first install, GitHub secret |
| [docs/AUTO_DEPLOY.md](docs/AUTO_DEPLOY.md) | How a merge to `main` reaches the site, and what to check when it does not |

## Local development

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run dev        # or: npm run build
php artisan serve
```

## Before you push

```bash
vendor/bin/pint          # formatting
php artisan test         # tests
npm run build            # public/build is committed; CI fails if it is stale
```

## How it deploys

Merge to `main` → CI runs → if green, GitHub Actions calls the site's deploy
webhook → the server pulls `main`, installs, migrates, caches → the workflow
verifies the site reports the new commit. Nothing is deployed from a red CI
run. Details in [docs/AUTO_DEPLOY.md](docs/AUTO_DEPLOY.md).

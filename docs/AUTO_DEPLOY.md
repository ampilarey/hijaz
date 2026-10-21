# Auto-deploy

`hijaz.rihla.mv` pulls `main` automatically, after CI passes. This is the same
mechanism as `test.rihla.mv` in the `ampilarey/rihla` repository.

## Fast path (default)

Workflow **Deploy hijaz.rihla.mv** (`.github/workflows/deploy.yml`) runs when the
**CI** workflow completes on `main`:

1. If CI failed, it deploys nothing and annotates the run (`Deploy skipped`).
2. If CI passed, it `POST`s `https://hijaz.rihla.mv/api/deploy/pull` with the
   commit CI verified and the shared secret as a bearer token.
3. The server answers `202` and runs `scripts/pull-deploy.sh` in the
   background: `git fetch` → `merge --ff-only` → `composer install` (only when
   the lockfile changed, else `dump-autoload`) → `storage:link` →
   `migrate --force` → `config:cache` → `route:cache` → `view:clear`.
4. The workflow polls `GET /api/health` with the secret until the site reports
   that commit, then fetches the homepage. Seven minutes without a match fails
   the run.

Typical delay after a merge: CI (about two minutes) plus under a minute.

## Fallback: cron

`scripts/self-update.sh` runs every minute from cron. When `origin/main` is
ahead of the checkout, it asks GitHub's API whether every CI check named in
the script is green on that commit, and only then runs the same pull script.
It covers a webhook that never arrived (DNS, SSL, GitHub outage); it never
deploys a red commit either.

## Pieces

| Piece | File |
|---|---|
| GitHub workflow | `.github/workflows/deploy.yml` |
| Webhook endpoint | `app/Http/Controllers/Api/DeployWebhookController.php` → `POST /api/deploy/pull` |
| Health endpoint | `app/Http/Controllers/Api/HealthController.php` → `GET /api/health` |
| Secret handling | `app/Support/DeploySecret.php` (constant-time compare, 16+ chars or treated as unset) |
| Running commit | `app/Support/DeployedCommit.php` (reads `.git/HEAD`, no shell needed) |
| Background spawn | `app/Services/Deploy/DeployTrigger.php` (`nohup … &` via `proc_open`) |
| Pull script | `scripts/pull-deploy.sh` |
| Cron fallback | `scripts/self-update.sh`, installed by `scripts/install-self-update-cron.sh` |
| Config | `config/deploy.php`: `DEPLOY_WEBHOOK_SECRET`, `DEPLOY_ALLOWED_HOSTS`, `DEPLOY_LOG` |

## Safety properties

- **Nothing deploys from a red CI run**, by either path.
- **Only `main` SHAs deploy.** The pull script waits for `origin/main` to equal
  the requested SHA and aborts otherwise.
- **The endpoint is invisible without the secret**: `404` when
  `DEPLOY_WEBHOOK_SECRET` is unset, `404` on any host not in
  `DEPLOY_ALLOWED_HOSTS`, `401` on a wrong secret.
- **A deploy that only started is not reported as done.** The workflow checks
  which commit is live, not whether the homepage answers.
- **One deploy at a time.** A lock directory in the home folder serialises the
  webhook and the cron.

## When a deploy goes wrong

| Symptom | Where to look |
|---|---|
| Workflow step "Trigger deploy webhook" is red with `404` | Secret missing from server `.env`, or `APP_URL`/`DEPLOY_ALLOWED_HOSTS` do not name `hijaz.rihla.mv`. Run `php artisan config:cache` after fixing `.env`. |
| `401` | Secret on GitHub (environment `hijaz`) differs from the server `.env`. |
| `503 Unable to spawn` | `proc_open` disabled for PHP on the host. The cron fallback still deploys within a minute; the workflow's verify step will then pass on a re-run. |
| Verify step times out | `tail -50 ~/hijaz-deploy.log` on the server. Usual causes: fast-forward refused (someone committed on the server), `composer install` failure, a migration error. |
| Site is 500 after a deploy | `tail -50 /home/rihla/hijaz.rihla.mv/storage/logs/laravel-$(date +%F).log` |

Deploy by hand, if ever needed:

```bash
bash /home/rihla/hijaz.rihla.mv/scripts/pull-deploy.sh
```

## Turning it off

Remove `DEPLOY_WEBHOOK_SECRET` from the server `.env`, run
`php artisan config:cache`, and `crontab -l | grep -v self-update.sh | crontab -`.

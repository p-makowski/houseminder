---
project: house-minder
researched_at: 2026-05-31
recommended_platform: Fly.io
runner_up: Railway
context_type: mvp
tech_stack:
  language: php
  framework: laravel
  runtime: php-8.3
  database: postgres
---

## Recommendation

**Deploy on Fly.io.**

Fly.io is the cheapest viable option for a PHP 8.3 / Laravel 11 / Livewire 3 stack at MVP scale (~$6-7/month with self-managed Postgres), has a dedicated Laravel docs section and `fly-apps/fly-laravel` reference on GitHub, and runs persistent microVM containers that match Laravel's request/session model. The user's cost-sensitivity (interview Q2) and single-region requirement (Q4) make Fly.io's edge-network irrelevant while its low base cost decisive. The bootstrapper's `deployment_target: fly` hint was validated by the research.

## Platform Comparison

| Platform | CLI-first | Managed | Agent-readable docs | Stable deploy API | MCP/Integration | Score |
|---|---|---|---|---|---|---|
| Fly.io | Pass | Partial | Pass | Pass | Partial (experimental) | 8.5 (cost bonus) |
| Railway | Partial | Pass | Pass | Pass | Partial (beta) | 8.0 |
| Render | Partial | Partial | Pass (best) | Pass | Pass (GA) | 7.5 (cost penalty) |
| Cloudflare Workers | — | — | — | — | — | Dropped (no PHP runtime) |
| Vercel | — | — | — | — | — | Dropped (no official PHP, no storage layer) |
| Netlify | — | — | — | — | — | Dropped (no PHP runtime) |

Cloudflare, Vercel, and Netlify were eliminated before scoring: none can run a production PHP/Laravel/Livewire stack without experimental workarounds or significant structural limitations.

### Shortlisted Platforms

#### 1. Fly.io (Recommended)

Persistent microVMs (Firecracker), full Docker container support, PHP 8.3 confirmed GA, dedicated Laravel docs and `fly-apps/fly-laravel` reference repo on GitHub. CLI (`flyctl`) is open-source, stable, and scriptable (`--json` output, standard exit codes). At ~$6-7/month with self-managed Postgres it is the cheapest viable option. The `fly mcp server` exists but is experimental. Single region is fully supported.

#### 2. Railway

Most managed of the three: Railpack auto-detects Laravel from `artisan`, runs FrankenPHP, and auto-migrates on deploy. No Dockerfile required. Co-located Postgres and Redis with one-click setup. First-class Claude Code integration page and a beta MCP server. Cost is $10-15/month on the Hobby plan — affordable but ~2x Fly.io's minimal cost. CLI rollback is not available (dashboard or API only), which is the main criterion gap.

#### 3. Render

Best agent-readable docs in the shortlist: `render.com/llms.txt`, `render.com/llms-full.txt`, and individual pages available as markdown via `.md` URL suffix. GA MCP server with 20+ structured tools (August 2025). Always-on paid services with co-located Postgres and Redis. Requires a Dockerfile (no native PHP buildpack). At ~$26/month (Starter $7 + Basic-1GB Postgres $19), it is the most expensive shortlisted option, which conflicts with the cost-minimize constraint.

## Anti-Bias Cross-Check: Fly.io

### Devil's Advocate — Weaknesses

1. **No free tier; credit card required from day one.** Fly removed its free tier in 2024. A $5 trial credit covers roughly two days of a minimal machine. Solo MVP projects that expect to iterate before committing to a platform have no sandbox period.
2. **Rollback is manual and undocumented as a command.** There is no `fly rollback`. To revert you identify the old image tag via `fly releases --app <app>` then run `fly deploy --image registry.fly.io/<app>:<old-tag>`. If releases were not labeled at deploy time, identifying the correct tag is error-prone under pressure.
3. **Dockerfile maintenance burden.** `fly launch` generates a starter Dockerfile but it may omit Laravel-required extensions (`pdo_pgsql`, `redis`, `gd`), OPcache tuning, or queue worker setup. The developer owns this file indefinitely.
4. **Self-managed Postgres is not a managed service.** `fly postgres` is a Postgres cluster the developer operates — no automatic backups, no auto-failover, no version-upgrade automation. Discovering this after weeks of operation without backups is a known failure mode. Managed Postgres (MPG) is $38/month — a 5x jump.
5. **Volume-machine 1:1 constraint.** A Fly volume mounts to exactly one machine. Apps that write to local storage cannot share a volume across machines; horizontal scaling requires retrofitting external object storage.

### Pre-Mortem — How This Could Fail

The developer deployed House Minder on Fly.io in week one — `fly launch` generated a Dockerfile, the app was running that evening, costs sat at $8/month. The first serious problem appeared in week three: a migration added a NOT NULL column without a default, and CI ran `php artisan migrate --force` before the new container finished rolling out. The old container — still handling traffic — threw 500s for four minutes because it couldn't read the partially-migrated table. Recovery required finding the last known-good image tag from `fly releases` output, which the developer hadn't labeled. It took three hours. The second problem came two months in: Fly Postgres surfaced a minor-version upgrade notification. The developer, unfamiliar with Postgres HA cluster management, deferred it. An auto-upgrade window ran during a demo, causing 45 seconds of downtime. By month three, a second machine was added for redundancy. Livewire began producing random 419 session-expired errors: the session driver was `file`, not `redis`, so sessions written on machine A were invisible to machine B. Retrofitting Redis sessions required an afternoon and a Fly Upstash extension. The total bill stayed low, but the operational surprises accumulated faster than features were shipped.

### Unknown Unknowns

- **`fly postgres` ≠ managed Postgres.** The affordable Postgres option is a self-managed cluster with no automatic backups or failover. Managed Postgres (MPG) with auto-backup and point-in-time recovery starts at $38/month — a completely separate product.
- **Livewire session nonce fails across multiple machines without shared Redis.** Livewire 3 stores per-page CSRF nonces in the session. With `SESSION_DRIVER=file` and two machines, requests hitting a different machine see an invalid nonce and return 419. This is a silent day-one misconfiguration that only surfaces on scale.
- **Build context size without `.dockerignore`.** `fly deploy` uploads the entire working directory. Without excluding `vendor/`, `node_modules/`, and `storage/`, build uploads can exceed 200 MB and succeed slowly with no clear feedback.
- **Stopped machines still accrue storage costs.** A stopped Fly machine is charged at $0.15/GB/month for its root filesystem snapshot — not free, just cheaper than running.
- **`fly mcp server` is experimental.** The flymcp GitHub repo has minimal commit history and no published releases. If the MCP server is unreliable, the agent falls back to CLI output parsing, which works but requires a pinned `flyctl` version in CI.

## Operational Story

- **Preview deploys**: Fly does not auto-create PR preview environments. Branch deploys can be scripted in CI by deploying to a separate Fly app (e.g., `<app>-pr-<number>`), but this requires manual CI configuration. No protection gate is built in — any preview URL is publicly accessible unless wrapped with Fly's built-in `fly certs` or an external auth proxy.
- **Secrets**: Environment variables and API tokens live in Fly Secrets (`fly secrets set KEY=value`). They are encrypted at rest, never appear in `fly.toml`, and are injected into the container at runtime. Rotation: `fly secrets set KEY=new-value` — the machine restarts automatically. Never commit secrets to `fly.toml` or the repository.
- **Rollback**: No `fly rollback` command. Procedure: `fly releases --app <app>` to list deployments → identify the image tag of the last known-good release → `fly deploy --image registry.fly.io/<app>:<tag>`. Label every release at deploy time: `fly deploy --image-label v<semver>`. Database migrations do not roll back with the image — reversible migrations are mandatory (see Risk Register).
- **Approval**: Human-only actions: deleting the Fly app, dropping the Postgres database, rotating the `APP_KEY` secret (all user sessions are invalidated), and any Postgres schema change that cannot be reversed. Agent-permitted unattended actions: `fly deploy`, `fly secrets set` for non-critical env vars, `fly logs`, `fly status`.
- **Logs**: `fly logs --app <app>` streams live runtime logs. `fly logs --app <app> --json` for structured output. Build logs: visible during `fly deploy`. Historical logs: `fly logs --app <app> -i <instance-id>` for per-instance filtering.

## Risk Register

| Risk | Source | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| No automatic Postgres backups (self-managed) | Devil's advocate | H | H | Run `fly postgres backup create` as a scheduled CI step daily; evaluate MPG if the app goes to production users |
| Bad migration + image rollback = schema/code mismatch | Devil's advocate | M | H | Write all migrations as reversible (up + down); test rollback locally before merging |
| Rollback confusion without labeled releases | Pre-mortem | M | M | Add `--image-label v$(git rev-parse --short HEAD)` to every `fly deploy` command in CI |
| Livewire 419 errors on multi-machine deploy | Unknown unknowns | M | H | Set `SESSION_DRIVER=redis` and `CACHE_STORE=redis` from day one using Upstash Redis (Fly extension) |
| Large build context slows deploys | Unknown unknowns | M | L | Add `.dockerignore` excluding `vendor/`, `node_modules/`, `storage/`, `tests/` |
| `fly mcp server` instability | Unknown unknowns | L | L | Fall back to raw `flyctl` CLI; pin `flyctl` version in CI |
| Stopped machines accrue storage cost | Unknown unknowns | L | L | Accept as known; cost is $0.15/GB/month — negligible at MVP scale |
| Livewire 3 ≤ 3.6.3 security vulnerability | Research finding | L | H | Pin `livewire/livewire: ^3.6.4` or later in `composer.json` |

## Getting Started

1. **Install flyctl**: `brew install flyctl` (macOS) or `curl -L https://fly.io/install.sh | sh`
2. **Authenticate**: `fly auth login`
3. **Launch the app** (from the project root): `fly launch --no-deploy` — review the generated `fly.toml`; set `[env] APP_ENV = "production"` and `LOG_CHANNEL = "stderr"`
4. **Set secrets**: `fly secrets set APP_KEY=$(php artisan key:generate --show) DB_CONNECTION=pgsql SESSION_DRIVER=redis CACHE_STORE=redis`
5. **Provision self-managed Postgres**: `fly postgres create --name house-minder-db --region arn --initial-cluster-size 1 --vm-size shared-cpu-1x --volume-size 1` then `fly postgres attach house-minder-db`
6. **Provision Upstash Redis** (for sessions and cache): `fly ext redis create` — credentials auto-set as `REDIS_URL`
7. **First deploy**: `fly deploy --image-label v0.1.0`
8. **Run migrations**: `fly ssh console -C "php artisan migrate --force"`
9. **Verify**: `fly status` and `fly logs`

## Out of Scope

The following were not evaluated in this research:
- Docker image configuration and Dockerfile authoring
- CI/CD pipeline setup (GitHub Actions deploy workflow)
- Production-scale architecture (multi-region, HA Postgres, DR)

# Deploy Plan: House Minder → Fly.io

## Context

House Minder is a PHP 8.3 / Laravel 13.8 / Livewire 3.6.4 app. Platform: Fly.io, single region (ams = Amsterdam), single machine.

**Production stack:**
- SQLite + Fly Volume (1 GB) — no separate Postgres, ~$0.15/mo for storage
- No Redis — `SESSION_DRIVER=database` + `CACHE_STORE=database` safe on single machine
- Estimated total: ~$2-4/mo (machine + volume + IPv4)

**Trade-offs accepted:**
- SQLite volume is 1-machine-only (add LiteFS to scale horizontally in v2)
- Sessions in SQLite: fine on single machine; needs Redis if second machine is added

---

## Phase 1 — Manual Gates (done before agent proceeds)

- [ ] `brew install flyctl`
- [ ] `fly auth login`
- [ ] Anthropic API key on hand

---

## Phase 2 — Deployment files (created)

| File | Status |
|---|---|
| `Dockerfile` | Created |
| `fly.toml` | Created |
| `.dockerignore` | Created |

---

## Phase 3 — Provision infrastructure

```bash
fly apps create house-minder
fly volumes create house_minder_data --app house-minder --region ams --size 1
```

---

## Phase 4 — Set secrets

```bash
fly secrets set \
  APP_NAME="House Minder" \
  APP_ENV=production \
  APP_DEBUG=false \
  APP_URL=https://house-minder.fly.dev \
  APP_KEY=$(php artisan key:generate --show) \
  DB_CONNECTION=sqlite \
  DB_DATABASE=/var/www/html/storage/app/database.sqlite \
  SESSION_DRIVER=database \
  CACHE_STORE=database \
  LOG_CHANNEL=stderr \
  --app house-minder

# Manual gate: user provides ANTHROPIC_API_KEY
fly secrets set ANTHROPIC_API_KEY=<user provides> --app house-minder
```

---

## Phase 5 — Deploy

```bash
fly deploy --image-label v0.1.0 --app house-minder
```

Triggers: Docker multi-stage build → image push → `release_command` (migrate) → machine start with volume mounted.

---

## Phase 6 — Verify

```bash
fly status --app house-minder
fly logs --app house-minder
fly open --app house-minder
```

Browser checks: home loads, register account, reach dashboard, add appliance + AI suggestions appear.

---

## Rollback procedure

```bash
fly releases --app house-minder                                # list releases
fly deploy --image registry.fly.io/house-minder:v0.1.0        # re-deploy prior image
```

DB migrations do not roll back with the image — run `fly ssh console -C "php artisan migrate:rollback"` if needed.

---

## Cost summary

| Resource | $/month |
|---|---|
| shared-cpu-1x 256 MB (auto-stop) | ~$0–2.02 |
| Fly Volume 1 GB | $0.15 |
| Dedicated IPv4 | $2.00 |
| **Total** | **~$2-4** |

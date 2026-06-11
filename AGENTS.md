# Repository Guidelines

House Minder is a PHP 8.5 / Laravel 13 household appliance maintenance tracker with Livewire 3 reactive UI and Tailwind
CSS.
It stores per-household appliance records, generates AI-based maintenance schedules, and surfaces upcoming/overdue tasks
on a dashboard.

## Hard Rules

- Every PHP file must open with `declare(strict_types=1)`.
- PHPStan must pass at level 6 (`./vendor/bin/phpstan analyse`) — do not suppress errors via baseline inflation.
- All appliance queries must be scoped to the authenticated household; cross-household data access is a critical bug.
- Appliance deletion requires explicit user confirmation; no soft-delete or auto-undo in v1.
- One shared email+password account per household — no per-user auth in v1.

## Project Structure

- `app/Http/Controllers/` — request handlers
- `app/Livewire/` — Livewire/Volt reactive components
- `app/Models/` — Eloquent models
- `app/Services/` — AI suggestion service and other integrations
- `resources/views/` — Blade templates; Livewire views under `livewire/` sub-dir
- `database/migrations/` — schema migrations
- `tests/Feature/` and `tests/Unit/` — test suites
- `context/` — 10x CLI project metadata; do not edit

## Commands

| Command                        | Purpose                                             |
|--------------------------------|-----------------------------------------------------|
| `composer setup`               | First-time install: deps, .env, migrate, Vite build |
| `composer dev`                 | Dev server + queue + logs + Vite (concurrent)       |
| `composer test`                | Config-cache clear then full PHPUnit suite          |
| `php artisan test <path>`      | Run a single test file                              |
| `./vendor/bin/pint`            | Format PHP (laravel preset, strict_types enforced)  |
| `./vendor/bin/phpstan analyse` | Static analysis at level 6                          |

## Coding Style

Rules enforced by @pint.json (Pint) and @phpstan.neon. 4-space indentation, UTF-8, LF line endings — see @.editorconfig.
Controllers must not contain business logic or database queries; delegate to service classes in `app/Services/`.

## Testing

PHPUnit 12 with in-memory SQLite (@phpunit.xml). Feature tests in `tests/Feature/`, unit tests in `tests/Unit/`.
Name test methods `test_<behaviour>` (snake_case). Run `composer test` before pushing.

## Database Migrations

- Every migration must implement both `up()` and `down()` methods — reversible migrations are mandatory.
- Never use `dropColumn`, `dropTable`, or `change()` without a matching `down()` that restores the previous state.
- Test rollback locally before merging: `php artisan migrate:rollback` must complete without errors.
- Adding a NOT NULL column requires a default or a two-phase migration (add nullable → backfill → add constraint) to
  avoid locking issues and deployment failures during rolling restarts on Fly.io.

## Commits

Prefix every commit: `[FEATURE]`, `[CHORE]`, or `[BUGFIX]`. Append a milestone/lesson tag where applicable — e.g.
`[CHORE] M1L4 description`.

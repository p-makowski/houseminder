# Quality-Gates Wiring — Plan Brief

> Full plan: `context/changes/testing-quality-gates-wiring/plan.md`
> Research: `context/changes/testing-quality-gates-wiring/research.md`

## What & Why

Wire PHPStan level 6, Pint (laravel+strict_types), and PHPUnit as gates that no code can bypass. All three tools are installed and passing — what's missing is the scaffolding that makes them mandatory: a CI pipeline and an edit-time agent hook. Standardize the declared PHP version to 8.5 at the same time, resolving a three-way mismatch (8.3/8.4/8.3 across composer.json, Dockerfile, AGENTS.md).

## Starting Point

The codebase already runs all three quality tools locally with zero violations. There is no `.github/` directory (zero CI), `composer pint:check` doesn't exist (only fix mode), and the single PostToolUse hook covers only `composer.json` edits, not PHP file quality gates.

## Desired End State

Every push triggers a GitHub Actions workflow that runs PHPStan → Pint check → PHPUnit in sequence; any failure blocks merge. A PostToolUse hook blocks the agent loop after any PHP file edit that introduces a PHPStan error or Pint violation (file-scoped, fast). All PHP version declarations read 8.5.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| PHP version | 8.5, aligned everywhere | Resolve three-way mismatch; keep in sync with production image | Plan |
| CI PHP matrix | 8.5 single target | Matches the new declared requirement; no matrix needed | Plan |
| Hook blocking behavior | Blocking (non-zero exit) | Mandatory gate must halt the loop, not just print a warning | Plan |
| Pint mode in hook | `--test` (flag, don't fix) | Silent auto-fix hides changes in the diff; agent sees the violation explicitly | Plan |
| PHPStan scope in hook | File-scoped (`--paths=<file>`) | Full scan per edit is slow (~10 s); CI provides full-scope coverage | Plan |
| Deploy gate (Fly.io) | Out of scope | Keeps this change focused; a developer can still `fly deploy` from a failing branch in v1 | Plan |

## Scope

**In scope:**
- `composer.json`: bump `require.php` to `^8.5`, add `pint:check` script
- `Dockerfile`: update base image to `frankenphp:1-php8.5`
- `AGENTS.md`: update PHP version reference to 8.5
- `.claude/settings.local.json`: add `composer pint:check` allowlist entry + PHP-file PostToolUse hook
- `.github/workflows/ci.yml`: full CI workflow (PHP 8.5, Composer cache, PHPStan + Pint check + PHPUnit)

**Out of scope:**
- Fly.io deploy gating
- Pre-commit git hooks
- Full PHPStan scan in the PostToolUse hook (file-scoped only)
- PHPUnit in the PostToolUse hook

## Architecture / Approach

Three independent configuration layers, each committable separately:
1. **Composer + version declarations** — foundation; the CI and hook depend on `pint:check` existing.
2. **GitHub Actions workflow** — push-time enforcement; runs the three composer scripts in sequence.
3. **PostToolUse hook** — edit-time enforcement; calls vendor binaries directly for file-scoped invocation.

The hook and CI complement each other: the hook catches violations immediately (file-scoped, fast), CI catches cross-file regressions and enforces before merge.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. PHP Alignment + Composer Foundation | `pint:check` script, PHP 8.5 everywhere, allowlist entry | `frankenphp:1-php8.5` Docker tag may not exist yet — verify before editing |
| 2. GitHub Actions CI | Green CI on push, all three gates enforced | Workflow YAML syntax errors surface only after pushing to GitHub |
| 3. PostToolUse Hook | Agent loop blocked on PHP quality violations | Hook command reads stdin once — `jq` pattern must match existing hook convention |

**Prerequisites:** Local PHP 8.5 runtime for running tests; GitHub repository with Actions enabled.
**Estimated effort:** ~1 session across 3 phases.

## Open Risks & Assumptions

- `dunglas/frankenphp:1-php8.5` Docker image tag must be verified to exist before Dockerfile edit.
- Local developer environment must have PHP 8.5 installed; bumping `require.php` to `^8.5` breaks `composer install` on 8.3/8.4.
- File-scoped PHPStan in the hook gives weaker guarantees than full-scope — a change in file A that breaks file B is only caught by CI, not the hook.

## Success Criteria (Summary)

- `composer pint:check`, `composer phpstan`, and `composer test` all exit 0 after the changes.
- A GitHub Actions run goes green on the target branch; each gate can be individually failed and caught.
- An intentional PHP error or Pint violation in a `.php` file causes the agent's Edit/Write to be followed by a non-zero hook exit.

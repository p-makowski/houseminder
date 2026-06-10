---
date: 2026-06-10T16:10:00Z
researcher: claude-sonnet-4-6
git_commit: 302723f968b727f6fa21040da36c83498e1b52be
branch: main
repository: houseminder
topic: "Quality-gates wiring — PHPStan, Pint, and PHPUnit as mandatory CI gates"
tags: [research, ci-cd, phpstan, pint, phpunit, github-actions, hooks, quality-gates]
status: complete
last_updated: 2026-06-10
last_updated_by: claude-sonnet-4-6
---

# Research: Quality-gates wiring — PHPStan, Pint, and PHPUnit as mandatory CI gates

**Date**: 2026-06-10T16:10:00Z
**Researcher**: claude-sonnet-4-6
**Git Commit**: 302723f968b727f6fa21040da36c83498e1b52be
**Branch**: main
**Repository**: houseminder

## Research Question

What is the current state of quality-gate enforcement (PHPStan, Pint, PHPUnit) in CI and in the agent loop, and what is needed to make all three mandatory — no code reaches production without passing all three?

## Summary

All three quality tools are **fully configured and passing locally** but enforced by nothing except developer discipline. There is no CI pipeline, no pre-commit hook, and only one unrelated post-edit hook. The Dockerfile and Fly.io deployment path are completely test-blind. Phase 4 needs to wire three things: a GitHub Actions CI workflow, a `composer pint:check` script (Pint check-only mode for CI), and a PostToolUse hook in `.claude/settings.local.json` for the agent loop.

## Detailed Findings

### 1. Quality Tools — Current Configuration

All three tools are installed and configured; none are enforced automatically.

#### PHPStan — `phpstan.neon`

```neon
includes:
    - vendor/larastan/larastan/extension.neon
parameters:
    paths:
        - app
    level: 6
```

- **Version**: phpstan/phpstan v2.2.1 + larastan/larastan v3.10.0
- **Level**: 6 — matches AGENTS.md requirement
- **Scope**: `app/` only — test files are not analysed
- **No baseline, no ignoreErrors** — clean slate
- **Run via**: `composer phpstan` → `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M`
- **Status**: Passing (131 tests green, PHPStan confirmed passing in recent commits)

#### Pint — `pint.json`

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true
    }
}
```

- **Version**: laravel/pint v1.29.1
- **Preset**: Laravel with `declare_strict_types` enforced — matches AGENTS.md
- **Run via**: `./vendor/bin/pint` (fix mode) — **no `composer pint` script exists**
- **CI mode**: `./vendor/bin/pint --test` (check only, non-zero exit on violations, no file modification)

#### PHPUnit — `phpunit.xml`

- **Version**: phpunit/phpunit v12.5.28
- **Suites**: Unit (`tests/Unit/`) + Feature (`tests/Feature/`)
- **Database**: in-memory SQLite (`:memory:`) — fast, isolated
- **Run via**: `composer test` → `php artisan config:clear && php artisan test`
- **Status**: 131 tests, all passing

### 2. CI/CD Pipeline — Does Not Exist

**There is no `.github/` directory.** Zero CI/CD pipeline is configured.

| Tool | Configured | Enforced in CI | Enforced on deploy |
|---|---|---|---|
| PHPStan | ✅ level 6 | ❌ none | ❌ none |
| Pint | ✅ laravel+strict | ❌ none | ❌ none |
| PHPUnit | ✅ in-memory SQLite | ❌ none | ❌ none |

The deploy path:

1. Developer runs `fly deploy` (or pushes to a branch connected to Fly.io)
2. Fly.io builds the Dockerfile
3. **Dockerfile installs `--no-dev` deps, compiles assets, runs `composer dump-autoload`**
4. Startup entrypoint runs `php artisan migrate --force` then starts FrankenPHP

Tests, PHPStan, and Pint are never executed anywhere in this chain.

### 3. Dockerfile — `Dockerfile`

```dockerfile
FROM dunglas/frankenphp:1-php8.4
...
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
...
RUN composer dump-autoload --optimize
RUN php artisan storage:link
```

- No `RUN composer phpstan` or `RUN ./vendor/bin/pint --test`
- Dev dependencies excluded via `--no-dev` (appropriate for production image)
- **Note**: Dockerfile uses PHP 8.4; AGENTS.md states PHP 8.3. GitHub Actions CI should target 8.3 to match the stated requirement, with an optional 8.4 matrix entry.

### 4. Claude Code Hooks — `.claude/settings.local.json`

Current PostToolUse hooks block:

```json
"hooks": {
  "PostToolUse": [
    {
      "matcher": "Write|Edit",
      "hooks": [
        {
          "type": "command",
          "command": "jq -r '.tool_input.file_path' | grep -qE 'composer\\.(json|lock)' && composer validate && composer audit || true"
        }
      ]
    }
  ]
}
```

One hook exists: validates `composer.json`/`composer.lock` on Write|Edit. The pattern for a PHP quality-gate hook is structurally identical — match `\.php$`, run PHPStan + Pint on the file.

**No post-edit quality-gate hook exists.** The test-plan explicitly marks this as "recommended after §3 Phase 4" (test-plan.md:125).

### 5. What `composer pint` Is Missing

`composer.json` scripts section defines `setup`, `dev`, `test`, `phpstan`. No `pint` or `pint:check` script. The permissions allowlist in `settings.local.json` includes `"Bash(./vendor/bin/pint *)"` but not a `composer pint` shorthand. A CI-safe check-only variant needs to be added:

```json
"pint:check": ["./vendor/bin/pint --test"]
```

### 6. Existing Skill: `10x-pint-check`

`.claude/skills/10x-pint-check/` exists as a skill in the project. This suggests a Pint-check invocation pattern has already been designed at the skill layer. The plan should check this skill's content to avoid duplicating its logic in the post-edit hook.

### 7. Permissions Allowlist — Gap

`settings.local.json` allows `Bash(composer phpstan *)` and `Bash(./vendor/bin/pint *)` but does NOT allow `Bash(composer pint:check *)` yet. Adding the `pint:check` composer script will require a matching allowlist entry.

## Code References

- `phpstan.neon:1-9` — PHPStan level 6 config, `app/` scope, Larastan extension
- `pint.json:1-7` — Laravel preset, strict_types enforced
- `phpunit.xml:1-37` — PHPUnit config, in-memory SQLite, Unit + Feature suites
- `composer.json` scripts — `test`, `phpstan` defined; `pint` missing
- `Dockerfile:1-44` — Build pipeline, no quality gates
- `fly.toml:1-21` — Fly.io deploy config, ams region
- `.claude/settings.local.json:53-65` — Single PostToolUse hook (composer.json only)
- `context/foundation/test-plan.md:124-125` — Quality gates table with "TBD" status
- `context/foundation/test-plan.md:392` — "TBD — see §3 Phase 4" note for CI step config

## Architecture Insights

**The gap is entirely structural, not configurational.** All three tools work today; what's missing is the scaffolding that makes them mandatory:

1. A GitHub Actions YAML that runs them on every push and blocks merge on failure
2. A `composer pint:check` script for CI (check mode vs fix mode distinction)
3. A PostToolUse hook that runs them in the agent loop after PHP file edits

**Hook design constraint**: The full PHPStan scan (`app/`) takes ~5-10 seconds locally. Running it on every PHP file edit in the agent loop is acceptable (comparable to the existing test-suite invocation pattern). Scoping it to the edited file path is possible (`--paths=$file`) but gives weaker guarantees since a change in one file can break another. Full-scope is safer and consistent with how `composer phpstan` runs.

**Pint in CI vs local**: `./vendor/bin/pint` (no flags) fixes files in place and exits 0. `./vendor/bin/pint --test` exits non-zero if any file would be changed, without modifying files — the CI-safe mode. The post-edit hook should use `--test` to catch violations; the developer uses the plain command to fix them.

**PHP version mismatch**: AGENTS.md says PHP 8.3; Dockerfile uses 8.4. CI should pin to 8.3 as the declared requirement. Optional 8.4 matrix entry can verify forward compatibility.

## Historical Context

- `context/archive/2026-06-05-testing-authorization-depth/` — Phase 2; established Volt::test integration pattern
- `context/archive/` (2026-06-06-phpstan-green implied by archived changes) — PHPStan was brought to green-on-level-6 before rollout began; that clean state is the baseline for the mandatory gate

## Open Questions

1. **PHP version in CI**: Pin to 8.3 (AGENTS.md) only, or matrix with 8.4 (Dockerfile)?
2. **Post-edit hook exit behavior**: Should a failing PHPStan/Pint hook block Claude's next action (non-zero exit blocks the loop) or just print a warning (always exit 0)? The test-plan says "recommended," suggesting non-blocking is acceptable — but a blocking hook provides stronger enforcement.
3. **Pint in the post-edit hook**: Run `--test` (flag violations) or run the fixer (silently fix in place)? Fixing in place would keep the loop clean but would mean Claude's edits are silently reformatted, which could be surprising. Flagging and letting the developer or a follow-up agent fix is more transparent.
4. **`git push` gate**: Should `git push` be moved from the "ask" list to a hook that requires green gates? This would make the push path the final enforcement point.
5. **Fly.io deploy gate**: Is it worth adding a CI deployment gate (only deploy from green CI) via Fly.io GitHub integration, or is local discipline + GitHub Actions sufficient for v1?

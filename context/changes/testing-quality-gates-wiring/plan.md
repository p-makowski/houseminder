# Quality-Gates Wiring Implementation Plan

## Overview

Wire PHPStan level 6, Pint (laravel+strict_types), and PHPUnit as mandatory gates that no code can bypass: a GitHub Actions CI workflow blocks merge on any failure; a PostToolUse hook in the agent loop blocks forward progress on PHP file edits until gates pass. As part of this change, standardize the declared PHP version to 8.5 across all three places it appears (composer.json, Dockerfile, AGENTS.md).

## Current State Analysis

All three quality tools are installed, configured, and passing locally — enforcement is the gap:

- **PHPStan**: level 6, `app/` scope, Larastan v3.x, run via `composer phpstan`. Zero baseline ignores. Passing.
- **Pint**: laravel preset + `declare_strict_types`. Fix mode only (`./vendor/bin/pint`); no `composer pint:check` script exists. Check-only mode (`--test`) needed for CI and the agent hook.
- **PHPUnit**: 131 tests, in-memory SQLite, run via `composer test`. Passing.
- **CI**: no `.github/` directory — zero pipeline.
- **PostToolUse hook**: one hook exists (triggers on `composer.json|lock` edits only; unrelated to PHP quality gates).
- **PHP version mismatch**: `composer.json` declares `^8.3`; Dockerfile uses `php8.4`; AGENTS.md says "PHP 8.3". All three need aligning to 8.5.

## Desired End State

- Every push to any branch triggers a GitHub Actions workflow that runs PHPStan, Pint check, and PHPUnit in sequence; any failure blocks merge.
- `composer pint:check` exists as a check-only alias (`./vendor/bin/pint --test`) usable in CI and the hook.
- A PostToolUse hook fires after every PHP file Write/Edit, runs file-scoped PHPStan and Pint `--test` on the edited file, and exits non-zero on violations — halting the agent loop until fixed.
- `composer.json`, `Dockerfile`, and `AGENTS.md` all declare PHP 8.5.

### Key Discoveries

- `composer.json:require.php` is currently `^8.3` — bump to `^8.5`.
- `Dockerfile` uses `FROM dunglas/frankenphp:1-php8.4` — update tag to `1-php8.5` (verify tag availability before editing).
- `.claude/settings.local.json` allowlist has `"Bash(composer phpstan *)"` and `"Bash(./vendor/bin/pint *)"` but not `"Bash(composer pint:check *)"` — add it in Phase 1 alongside the script.
- The PostToolUse hook command reads stdin once with `jq`; the existing composer.json hook pattern uses `jq -r '.tool_input.file_path'` — the PHP hook follows the same shape.
- Tests use in-memory SQLite — CI needs no DB service, just `php-sqlite` extension (bundled in most PHP images).
- No GitHub secrets are needed for CI-only (no deploy step in scope).

## What We're NOT Doing

- Fly.io deploy gating (CI must be green before `fly deploy`) — follow-up if needed.
- Pre-commit git hook (husky / git hook scripts) — the PostToolUse agent hook is sufficient for v1.
- PHPStan full-scan in the PostToolUse hook — file-scoped only to keep the inner loop fast; full scan runs in CI.
- Wiring `composer test` into the PostToolUse hook — running the full PHPUnit suite on every file edit is too slow; CI covers it.

## Implementation Approach

Three phases, each independently verifiable and committable:

1. **Foundation** — update all PHP version declarations to 8.5 and add the `pint:check` composer script. Everything else depends on the script existing.
2. **CI workflow** — a single GitHub Actions YAML that runs all three gates on every push, using the composer scripts added in Phase 1.
3. **Agent-loop hook** — add the PostToolUse PHP-file hook to `settings.local.json`; the hook calls vendor binaries directly (not through the composer scripts) for file-scoped invocation.

---

## Phase 1: PHP Version Alignment + Composer Foundation

### Overview

Standardize PHP to 8.5 in all three declaration points, add the `pint:check` composer script, and extend the agent permissions allowlist. No logic changes — pure configuration.

### Changes Required

#### 1. `composer.json` — PHP version + pint:check script

**File**: `composer.json`

**Intent**: Bump the declared PHP floor to 8.5 and add a check-only Pint script that CI and the hook can invoke without modifying files.

**Contract**:
- `require.php`: change `"^8.3"` → `"^8.5"`
- `scripts.pint:check`: add `["./vendor/bin/pint --test"]`

The `--test` flag makes Pint exit non-zero when it would change a file, without writing changes. This is the CI-safe mode.

#### 2. `Dockerfile` — FrankenPHP base image tag

**File**: `Dockerfile`

**Intent**: Update the production image to PHP 8.5 to match the new declared requirement.

**Contract**: Change `FROM dunglas/frankenphp:1-php8.4` → `FROM dunglas/frankenphp:1-php8.5`. Verify the tag exists on Docker Hub before editing.

#### 3. `AGENTS.md` — PHP version reference

**File**: `AGENTS.md`

**Intent**: Update the project description and commands table so documentation matches the new PHP floor.

**Contract**: The first line says "PHP 8.3 / Laravel 13" — update to "PHP 8.5 / Laravel 13". The commands table references `./vendor/bin/phpstan analyse` — no change needed there.

#### 4. `.claude/settings.local.json` — permissions allowlist

**File**: `.claude/settings.local.json`

**Intent**: Allow Claude to invoke `composer pint:check` without a permission prompt, consistent with the existing `composer phpstan` and `composer test` entries.

**Contract**: Add `"Bash(composer pint:check *)"` to the `permissions.allow` array.

### Success Criteria

#### Automated Verification

- `composer validate` exits 0
- `composer pint:check` exits 0 (no style violations in the current codebase — it should pass clean since Pint was already passing)
- `composer test` exits 0 (131 tests still green — ensures the PHP version bump didn't break anything in the test environment)

#### Manual Verification

- `composer.json` `require.php` reads `^8.5`
- `Dockerfile` first `FROM` line for the PHP stage reads `php8.5`
- `AGENTS.md` header line reads "PHP 8.5 / Laravel 13"
- `settings.local.json` allow list contains `"Bash(composer pint:check *)"`

**Implementation Note**: After Phase 1 automated verification passes, pause for manual confirmation before proceeding to Phase 2.

---

## Phase 2: GitHub Actions CI Workflow

### Overview

Create `.github/workflows/ci.yml` — a single workflow that installs PHP 8.5 and Composer deps (with layer caching), then runs all three gates in sequence. Runs on every push and pull request. No secrets required.

### Changes Required

#### 1. `.github/workflows/ci.yml` (new file)

**File**: `.github/workflows/ci.yml`

**Intent**: Run PHPStan level 6, Pint check, and PHPUnit on every push and PR, blocking merge on any failure. Use Composer dependency caching to keep runtime fast.

**Contract**:
- Trigger: `push` (all branches) + `pull_request` (all branches)
- Runner: `ubuntu-latest`
- PHP: `8.5`, extensions `pdo_sqlite mbstring xml ctype fileinfo`, ini `memory_limit=512M`
- Steps in order:
  1. Checkout
  2. Setup PHP (shivammathur/setup-php@v2)
  3. Get Composer cache directory + cache deps (actions/cache@v4, keyed on `composer.lock` hash)
  4. Install deps: `composer install --prefer-dist --no-progress`
  5. Static analysis: `composer phpstan`
  6. Code style check: `composer pint:check`
  7. Test suite: `composer test`
- Each step uses a `name:` that mirrors the composer script it runs (e.g. `name: PHPStan level 6`).
- No `continue-on-error` on any step — first failure stops the run.

### Success Criteria

#### Automated Verification

- `.github/workflows/ci.yml` exists and is valid YAML (`python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"` or `yq` exits 0)
- The workflow file references `shivammathur/setup-php@v2`, PHP `8.5`, and all three composer scripts (`phpstan`, `pint:check`, `test`)

#### Manual Verification

- Push the branch to GitHub and confirm the Actions workflow appears and goes green
- Introduce a deliberate PHPStan error (e.g. add `$x = new \NonExistentClass();` to any `app/` file), push, confirm the CI run fails at the PHPStan step — then revert
- Confirm the workflow name and step names are readable in the GitHub Actions UI

**Implementation Note**: The manual verification step (push to GitHub + deliberate-fail check) is the most important signal that the wiring is real. Don't skip it.

---

## Phase 3: PostToolUse Quality-Gate Hook

### Overview

Add a PostToolUse hook to `.claude/settings.local.json` that fires after every Write or Edit on a `.php` file. For PHP files it runs file-scoped PHPStan and Pint `--test` and exits non-zero on any violation, blocking the agent loop. Non-PHP file edits pass through silently.

### Changes Required

#### 1. `.claude/settings.local.json` — PHP quality-gate hook

**File**: `.claude/settings.local.json`

**Intent**: Block the agent from continuing after a PHP file edit that introduces a PHPStan error or Pint style violation. The hook is the edit-time enforcement layer; CI is the push-time layer.

**Contract**: Add a second entry to the `hooks.PostToolUse` array (alongside the existing composer.json hook). It must:
- Match: `Write|Edit` (same matcher pattern as the existing hook)
- Command shape (single shell string):
  1. Read the edited file path from stdin via `jq -r '.tool_input.file_path'`
  2. If the path does not end in `.php`, exit 0 immediately (non-PHP files are not subject to this gate)
  3. Run `./vendor/bin/phpstan analyse --paths="$FILE" --no-progress --memory-limit=512M` — file-scoped
  4. Run `./vendor/bin/pint --test "$FILE"` — check mode on the single file
  5. The overall exit code is the OR of both commands: non-zero if either fails

The hook calls vendor binaries directly (not via `composer phpstan` / `composer pint:check`) because `--paths=` and single-file arguments cannot be appended to the existing composer scripts without modifying them.

### Success Criteria

#### Automated Verification

- `settings.local.json` parses as valid JSON after the edit
- The new hook entry is present under `hooks.PostToolUse` alongside the existing composer.json hook
- Running `./vendor/bin/pint --test app/Models/Appliance.php` exits 0 (current code is clean — verifies the hook command shape works)

#### Manual Verification

- Introduce a deliberate PHPStan error in any `app/` PHP file (e.g. reference an undefined variable), use the Edit tool to save it, and confirm the hook fires and prints an error — then revert
- Introduce a deliberate Pint violation (e.g. remove `declare(strict_types=1)`), save, confirm the hook catches it — then revert
- Edit a non-PHP file (e.g. `resources/views/layouts/app.blade.php`) and confirm the hook does NOT fire (exits 0 silently)

---

## Testing Strategy

### Automated Gates (all phases)

- `composer phpstan` — PHPStan level 6, `app/` scope
- `composer pint:check` — Pint check-only, laravel preset + strict_types
- `composer test` — 131 PHPUnit tests, in-memory SQLite

### Manual Testing Steps

1. After Phase 1: run `composer pint:check` locally and confirm exit 0
2. After Phase 2: push a branch and watch the CI run go green in GitHub Actions; perform the deliberate-fail check for each gate
3. After Phase 3: trigger the hook deliberately with a bad PHP file, confirm the agent loop blocks; trigger with a non-PHP file, confirm it passes

## References

- Research: `context/changes/testing-quality-gates-wiring/research.md`
- Existing hook pattern: `.claude/settings.local.json` (PostToolUse, composer.json matcher)
- PHPStan config: `phpstan.neon`
- Pint config: `pint.json`
- Quality-gates table: `context/foundation/test-plan.md` (§3, §6.5)

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: PHP Version Alignment + Composer Foundation

#### Automated

- [x] 1.1 `composer validate` exits 0
- [x] 1.2 `composer pint:check` exits 0 (codebase clean after version bump)
- [x] 1.3 `composer test` exits 0 (131 tests green)

#### Manual

- [x] 1.4 `composer.json` require.php reads `^8.5`
- [x] 1.5 Dockerfile PHP stage reads `php8.5`
- [x] 1.6 AGENTS.md header reads "PHP 8.5 / Laravel 13"
- [x] 1.7 settings.local.json allowlist contains `"Bash(composer pint:check *)"`

### Phase 2: GitHub Actions CI Workflow

#### Automated

- [ ] 2.1 `.github/workflows/ci.yml` exists and is valid YAML
- [ ] 2.2 Workflow file references PHP 8.5 and all three composer scripts

#### Manual

- [ ] 2.3 Push branch to GitHub — Actions workflow appears and goes green
- [ ] 2.4 Deliberate-fail check: PHPStan step fails on bad code, CI run blocked

### Phase 3: PostToolUse Quality-Gate Hook

#### Automated

- [ ] 3.1 `settings.local.json` parses as valid JSON
- [ ] 3.2 New hook entry present under `hooks.PostToolUse`
- [ ] 3.3 `./vendor/bin/pint --test app/Models/Appliance.php` exits 0

#### Manual

- [ ] 3.4 Deliberate PHPStan error → Edit → hook fires and prints error
- [ ] 3.5 Deliberate Pint violation → Edit → hook catches it
- [ ] 3.6 Non-PHP file edit → hook exits 0 silently

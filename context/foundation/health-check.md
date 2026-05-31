---
project: houseminder
checked_at: 2026-05-29T20:10:00Z
health_status: needs-attention
context_type: brownfield
language_family: php
stack_assessment_available: false
checks_run:
  - lockfile
  - dependency_audit
  - outdated_deps
  - test_runner
  - ci_cd
  - configuration
audit_findings:
  critical: 0
  high: 0
  moderate: 0
  low: 0
test_runner_detected: true
ci_provider: null
recommended_fixes: 2
---

## Dependency Health

### Lockfile

```
Status:          present (composer.lock + package-lock.json)
Package manager: composer (PHP) / npm (frontend assets)
```

Both lockfiles present. Dependency versions are pinned and builds are reproducible.

### Security Audit

```
Tool (PHP):    composer audit --format json
Tool (JS):     npm audit --json
Summary:       0 CRITICAL, 0 HIGH, 0 MODERATE, 0 LOW
Direct vs transitive: not distinguished by composer audit; npm distinguishes (0 across both)
```

Clean tree across both ecosystems at time of scaffold. Re-run `composer audit` and `npm audit` periodically as you add packages.

### Outdated Dependencies

```
Packages with major version gaps (1 major behind): 4
Packages 2+ major versions behind: 0
```

No packages are urgently behind. The following direct dependencies have one major version available — no action required now, but worth tracking:

- **livewire/livewire**: v3.8.0 → v4.3.0 (Livewire 4 is a significant rewrite; upgrade when ready)
- **phpunit/phpunit**: v12.5.28 → v13.1.13 (stable v12 branch receives patches; no urgency)
- **tailwindcss**: 3.4.19 → 4.3.0 (Tailwind 4 has a new engine; upgrade path documented at tailwindcss.com)
- **concurrently**: 9.2.1 → 10.0.0 (dev tooling; low risk)

---

## Test Suite

```
Test runner:    PHPUnit 12.5.28
Tests found:    26
Test execution: passing (26/26, 77 assertions, ~6s)
```

```
Configuration: phpunit.xml
Framework:     PHPUnit 12.5.28 (with Laravel TestCase via vendor/autoload.php)
```

Test suites:

- **Unit** — `tests/Unit/` (1 test: ExampleTest)
- **Feature** — `tests/Feature/` (25 tests: full auth flow via Breeze — registration, login, logout, email verification, password reset, password update, profile management)

Test environment is configured to use SQLite in-memory (`:memory:`) for fast, isolated runs. All 26 tests pass against the freshly scaffolded project.

---

## CI/CD

```
Provider:      not detected
Configuration: not found
```

```
ℹ No CI/CD configuration detected. You'll set this up in the infrastructure and deployment lesson.
For now, local test runner coverage is sufficient for agent collaboration.
```

| Stage      | Status | Notes                                      |
|------------|--------|--------------------------------------------|
| Lint       | ✗      | not configured in CI (Pint available locally) |
| Test       | ✗      | not configured in CI (PHPUnit passes locally) |
| Build      | ✗      | not configured in CI                       |
| Type check | ✗      | not configured in CI (PHPStan not installed) |
| Security   | ✗      | not configured in CI                       |

---

## Configuration

### High severity

*(none)*

### Medium severity

- **PHPStan (static analysis)** — not installed. PHP's dynamic typing means the agent will generate code without compile-time type guarantees. PHPStan at level 6+ was explicitly called out in the PRD as the compensation strategy for agent-assisted PHP development — without it, type errors and undefined method calls surface at runtime, not before the agent commits.
  Fix: `composer require --dev phpstan/phpstan larastan/larastan` then configure `phpstan.neon` at level 6.

### Low severity

- **`.npmrc`** — present (from scaffold). No action needed.
- **`AGENTS.md`** — not present. Covered in agent onboarding (Category B below).

All other expected configuration files are present:

| File | Status |
|---|---|
| `.editorconfig` | ✓ present |
| `.env.example` | ✓ present |
| `.gitignore` | ✓ present (append-merged with Laravel patterns) |
| `phpunit.xml` | ✓ present |
| Laravel Pint | ✓ installed (`laravel/pint ^1.27`) |
| `CLAUDE.md` | ✓ present |

---

## Stack Assessment Cross-Reference

```
No stack-assessment.md found. Run /10x-stack-assess for quality-gate analysis.
```

The PRD (`context/foundation/prd.md`) notes PHP's gradual typing as a friction point for agent-assisted development and recommends `declare(strict_types=1)` project-wide and PHPStan at level 6+ as compensating controls. Health-check reinforces that PHPStan is the missing piece — the formatter (Pint) is present but the static type enforcer is not.

---

## Recommended Fixes

### Fix before agent work (Category A)

#### 1. Install and configure PHPStan / Larastan

**Impact**: Without static analysis, the agent operates without a compiler-level type safety net. In PHP this means: incorrect method calls, mismatched argument types, and undefined properties on Eloquent models all pass PHP's runtime type-coercion silently. PHPStan + Larastan at level 6 closes this gap — it gives the agent (and CI) a fast feedback loop that catches type errors before they reach tests.

**Severity**: medium  
**Effort**: moderate (15–30 min)  
**Fix**:

```bash
composer require --dev phpstan/phpstan larastan/larastan
```

Then create `phpstan.neon` in the project root:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app

    level: 6
```

Add a `composer.json` script for convenience:

```bash
# In composer.json scripts:
"analyse": "vendor/bin/phpstan analyse --memory-limit=2G"
```

Run `composer analyse` to see the initial report. Address findings before starting agent-assisted feature work.

Note: the project should also adopt `declare(strict_types=1)` at the top of new PHP files — this is a per-file opt-in that pairs with PHPStan to eliminate implicit type coercion in agent-generated code.

---

#### 2. Pin `declare(strict_types=1)` in new files

**Impact**: PHP allows implicit type coercion by default (`"3" + 4 = 7` without error). Agent-generated code may rely on this behavior, producing subtle bugs. Enabling strict types per-file makes PHP raise `TypeError` on type mismatches — failures become explicit and testable.

**Severity**: medium  
**Effort**: quick (< 5 min to configure Pint to enforce it going forward)  
**Fix**:

Add to `pint.json` (create if absent):

```json
{
    "rules": {
        "declare_strict_types": true
    }
}
```

Then run `./vendor/bin/pint` before each commit to auto-insert `declare(strict_types=1)` into new files. Existing scaffold files do not need to be retrofitted immediately — apply going forward.

---

### Addressed in upcoming lessons (Category B)

#### No CI/CD pipeline

**Lesson**: [Sprint Zero z Agentem: infrastruktura, walking skeleton i pierwszy deploy (M1L5)](https://platforma.przeprogramowani.pl/external/10xdevs-3/m1-l5)  
**What you'll do there**: Set up a GitHub Actions workflow that runs `composer analyse`, `php artisan test`, and `npm run build` on every push, then deploys to Fly.io on merge to main — matching the `ci_default_flow: auto-deploy-on-merge` hand-off value.

---

#### Missing AGENTS.md / agent instruction file

**Lesson**: [Agent Onboarding: Agents.md, AI Rules i feedback loops (M1L4)](https://platforma.przeprogramowani.pl/external/10xdevs-3/m1-l4)  
**What you'll do there**: Build `AGENTS.md` with project conventions, PHPStan level, Pint formatting rules, strict-types requirement, and Laravel-specific agent guardrails. Generating a stub now would miss the content that makes it valuable.

---

## Summary

```
Health status: needs-attention
```

houseminder is in solid shape for a freshly scaffolded project: clean dependency tree (0 security advisories across PHP and JS), both lockfiles committed, a full test suite of 26 passing tests covering the entire Breeze auth flow, and Laravel Pint installed for formatting. The single actionable gap before agent-assisted feature work begins is static analysis — PHPStan with the Larastan extension is absent, and the PRD explicitly called it out as the key compensation for PHP's dynamic typing in an agent workflow. Installing it and running an initial analysis (Category A, ~20 minutes) is the recommended next step before starting feature development.

Next step: install PHPStan/Larastan (`composer require --dev phpstan/phpstan larastan/larastan`), configure `phpstan.neon` at level 6, resolve the initial findings, then proceed to agent onboarding (M1L4).

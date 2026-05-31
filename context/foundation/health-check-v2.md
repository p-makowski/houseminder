---
project: houseminder
checked_at: 2026-05-29T20:40:00Z
health_status: healthy
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
recommended_fixes: 0
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
Direct vs transitive: not distinguished by composer audit; npm reports 0 across all
```

Clean tree across both ecosystems.

### Outdated Dependencies

```
Packages with major version gaps (1 major behind): 4
Packages 2+ major versions behind: 0
```

Informational only — no package is 2+ major versions behind:

- **livewire/livewire**: v3.8.0 → v4.3.0
- **phpunit/phpunit**: v12.5.28 → v13.1.13
- **tailwindcss**: 3.4.19 → 4.3.0
- **concurrently**: 9.2.1 → 10.0.0

---

## Test Suite

```
Test runner:    PHPUnit 12.5.28
Tests found:    26
Test execution: passing (26/26, 77 assertions, ~3s)
```

```
Configuration: phpunit.xml
Framework:     PHPUnit 12.5.28 (SQLite in-memory for fast isolated runs)
```

Test suites:

- **Unit** — `tests/Unit/` (1 test)
- **Feature** — `tests/Feature/` (25 tests: full auth flow — registration, login, logout, email verification, password reset, password update, profile management)

All 26 tests pass. The `User` model correctly implements `MustVerifyEmail` (enabled during this session).

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

| Stage      | Status | Notes                                                    |
|------------|--------|----------------------------------------------------------|
| Lint       | ✗      | not configured in CI (Pint available locally via `./vendor/bin/pint`) |
| Test       | ✗      | not configured in CI (PHPUnit passes locally)            |
| Build      | ✗      | not configured in CI                                     |
| Type check | ✗      | not configured in CI (PHPStan passes locally)            |
| Security   | ✗      | not configured in CI                                     |

---

## Configuration

All expected configuration files present. No Category A gaps detected.

| File / Tool        | Status | Notes                                              |
|--------------------|--------|----------------------------------------------------|
| `.editorconfig`    | ✓      | present                                            |
| `.env.example`     | ✓      | present                                            |
| `.gitignore`       | ✓      | append-merged with Laravel patterns                |
| `phpunit.xml`      | ✓      | present, SQLite in-memory test environment         |
| `pint.json`        | ✓      | present, `laravel` preset + `declare_strict_types` |
| Laravel Pint       | ✓      | installed, 0 files need fixes                      |
| `phpstan.neon`     | ✓      | present, level 6, 512M memory limit                |
| PHPStan / Larastan | ✓      | installed, 0 errors                                |
| `declare(strict_types=1)` | ✓ | applied to all 40 PHP files via Pint            |
| `MustVerifyEmail`  | ✓      | enabled on `User` model                            |
| `CLAUDE.md`        | ✓      | present                                            |
| `AGENTS.md`        | —      | Category B (agent onboarding lesson)               |

---

## Stack Assessment Cross-Reference

```
No stack-assessment.md found. Run /10x-stack-assess for quality-gate analysis.
```

The PRD's two PHP compensation strategies are now both in place:

| PRD Requirement                        | Status                                              |
|----------------------------------------|-----------------------------------------------------|
| `declare(strict_types=1)` project-wide | ✓ enforced via `pint.json` `declare_strict_types` rule |
| PHPStan at level 6+                    | ✓ `phpstan.neon` at level 6, 0 current errors       |

---

## Recommended Fixes

### Fix before agent work (Category A)

None. All Category A items from the v1 health check have been resolved.

---

### Addressed in upcoming lessons (Category B)

#### No CI/CD pipeline

**Lesson**: [Sprint Zero z Agentem: infrastruktura, walking skeleton i pierwszy deploy (M1L5)](https://platforma.przeprogramowani.pl/external/10xdevs-3/m1-l5)
**What you'll do there**: Set up a GitHub Actions workflow that runs `./vendor/bin/pint --test`, `./vendor/bin/phpstan analyse`, `php artisan test`, and `npm run build` on every push, then deploys to Fly.io on merge to main.

#### Missing AGENTS.md / agent instruction file

**Lesson**: [Agent Onboarding: Agents.md, AI Rules i feedback loops (M1L4)](https://platforma.przeprogramowani.pl/external/10xdevs-3/m1-l4)
**What you'll do there**: Build `AGENTS.md` with project conventions, PHPStan level, Pint formatting rules, strict-types requirement, and Laravel-specific agent guardrails.

---

## Summary

```
Health status: healthy
```

houseminder is agent-ready. All Category A items from the initial health check have been resolved in this session: PHPStan (Larastan, level 6) is installed and passing, `declare(strict_types=1)` is enforced project-wide via Pint, the `User` model correctly implements `MustVerifyEmail`, and all 26 tests pass. The dependency tree is clean across PHP and JS. The only remaining gaps are Category B items (CI/CD pipeline, AGENTS.md) that will be set up in upcoming lessons.

Next step: proceed to agent onboarding (M1L4) to build `AGENTS.md` and establish AI collaboration rules for the project.

---
bootstrapped_at: 2026-05-29T19:52:00Z
starter_id: laravel
starter_name: Laravel
project_name: house-minder
language_family: php
package_manager: composer
cwd_strategy: subdir-then-move
bootstrapper_confidence: verified
phase_3_status: ok
audit_command: "null"
---

## Hand-off

```yaml
starter_id: laravel
package_manager: composer
project_name: house-minder
hints:
  language_family: php
  team_size: solo
  deployment_target: fly
  ci_provider: github-actions
  ci_default_flow: auto-deploy-on-merge
  bootstrapper_confidence: verified
  path_taken: standard
  quality_override: false
  self_check_answers: null
  has_auth: true
  has_payments: false
  has_realtime: false
  has_ai: true
  has_background_jobs: false
```

### Why this stack

Laravel is the vetted recommended starter for PHP web apps: it ships with Eloquent ORM, migrations, Blade templating, and a service container that will feel immediately familiar to any Magento backend developer. Auth scaffolding via Laravel Breeze covers FR-001/FR-002 out of the box, and Livewire handles the reactive dashboard and AI-suggestion loading states (FR-010, FR-013) without requiring a separate JavaScript framework or build complexity. The 3-week after-hours timeline favours Laravel's batteries-included approach — migrations, seeders, factories, and Artisan CLI eliminate boilerplate so development time goes toward product features. AI-generated maintenance suggestions (FR-010) call the Anthropic or OpenAI API via a lightweight service class; no additional framework package is required. PHP's gradual typing is the one friction point for agent-assisted development — compensate with `declare(strict_types=1)` project-wide and PHPStan at level 6+. Deployment to Fly.io via GitHub Actions on merge gives a simple, low-maintenance CI/CD pipeline appropriate for a solo after-hours project.

## Pre-scaffold verification

| Signal      | Value    | Severity | Notes                                                     |
| ----------- | -------- | -------- | --------------------------------------------------------- |
| npm package | not run  | n/a      | non-JS starter; cmd_template uses composer, not npm       |
| GitHub repo | not run  | n/a      | docs_url is https://laravel.com/docs — not a GitHub URL   |

No recency signal available for the laravel starter. Proceeded without warning.

## Scaffold log

**Resolved invocation**: `composer create-project laravel/laravel .bootstrap-scaffold --no-interaction --prefer-dist`
**Strategy**: scaffold into a temp directory then move files up
**Exit code**: 0
**Files moved**: 22 (9 root files + 10 directories + 3 project files)
**Conflicts (.scaffold siblings)**: none
**.gitignore handling**: append-merged — existing 5-line cwd `.gitignore` preserved; 28 Laravel-specific lines appended after `# from laravel` separator; no duplicate lines
**.bootstrap-scaffold cleanup**: deleted

Files moved: `.editorconfig`, `.env`, `.env.example`, `.gitattributes`, `.npmrc`, `README.md`, `artisan`, `phpunit.xml`, `vite.config.js`, `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, `tests/`, `vendor/`, `composer.json`, `composer.lock`, `package.json`

Note: Composer's built-in post-install security check reported "No security vulnerability advisories found" during the scaffold run (laravel/laravel v13.8.0, laravel/framework v13.12.0, 109 packages installed).

## Post-scaffold audit

**Tool**: skipped — no built-in audit tool for php
**Recommended external tool**: configure [Roave Security Advisories](https://github.com/Roave/SecurityAdvisories) as a Composer `conflict` package, or run [local-php-security-checker](https://github.com/fabpot/local-php-security-checker) as a CI step. Both check against the PHP Security Advisories Database. Composer's built-in `composer audit` command (run automatically post-install) already reported a clean tree for this scaffold.

## Hints recorded but not acted on

| Hint                    | Value              |
| ----------------------- | ------------------ |
| bootstrapper_confidence | verified           |
| quality_override        | false              |
| path_taken              | standard           |
| self_check_answers      | null               |
| team_size               | solo               |
| deployment_target       | fly                |
| ci_provider             | github-actions     |
| ci_default_flow         | auto-deploy-on-merge |
| has_auth                | true               |
| has_payments            | false              |
| has_realtime            | false              |
| has_ai                  | true               |
| has_background_jobs     | false              |

These values were read into the verification record but no automated action was taken in v1. The future M1L4 skill ("Memory Architecture") will act on deployment target, CI provider, and feature flags to generate `CLAUDE.md` / `AGENTS.md` and CI workflow files.

## Next steps

Next: a future skill will set up agent context (CLAUDE.md, AGENTS.md). For now, your project is scaffolded and verified — happy hacking.

Useful manual steps in the meantime:
- `git add -A && git commit -m "scaffold: laravel v13"` to checkpoint the scaffold in your repo history.
- `php artisan breeze:install` to add auth scaffolding (covers `has_auth: true`).
- Review any `.scaffold` siblings the conflict policy created and decide which version to keep (none were created this run).
- Address audit findings per your project's risk tolerance — `composer audit` reported clean at scaffold time; re-run periodically as you add packages.
- For the AI integration (`has_ai: true`): add `openai-php/laravel` or a plain Guzzle service class for Anthropic API calls — no additional framework package is required.

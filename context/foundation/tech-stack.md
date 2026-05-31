---
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
---

## Why this stack

Laravel is the vetted recommended starter for PHP web apps: it ships with Eloquent ORM, migrations, Blade templating, and a service container that will feel immediately familiar to any Magento backend developer. Auth scaffolding via Laravel Breeze covers FR-001/FR-002 out of the box, and Livewire handles the reactive dashboard and AI-suggestion loading states (FR-010, FR-013) without requiring a separate JavaScript framework or build complexity. The 3-week after-hours timeline favours Laravel's batteries-included approach — migrations, seeders, factories, and Artisan CLI eliminate boilerplate so development time goes toward product features. AI-generated maintenance suggestions (FR-010) call the Anthropic or OpenAI API via a lightweight service class; no additional framework package is required. PHP's gradual typing is the one friction point for agent-assisted development — compensate with `declare(strict_types=1)` project-wide and PHPStan at level 6+. Deployment to Fly.io via GitHub Actions on merge gives a simple, low-maintenance CI/CD pipeline appropriate for a solo after-hours project.
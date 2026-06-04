# First Appliance + AI Plan (S-01) — Plan Brief

> Full plan: `context/changes/first-appliance-ai-plan/plan.md`
> Research: `context/changes/first-appliance-ai-plan/research.md`

## What & Why

S-01 is the north-star slice: the smallest end-to-end flow that proves the product's core bet — AI-grounded maintenance suggestions paired with a household-owned schedule in one place. A logged-in user can add an appliance, receive AI-generated maintenance tasks, customise the plan, optionally backdate past services, and confirm. Confirmation persists the plan in the DB and redirects to a new appliance detail page.

## Starting Point

The F-01 foundation is complete: Appliance, ApplianceType, MaintenanceTask, ServiceRecord, and Household models exist with all migrations in place and 13 seeded appliance types. The dashboard is a placeholder ("You're logged in!") with no user-facing flows yet. No AI packages are installed.

## Desired End State

A user navigates to "Add Appliance", fills in appliance details with a typeahead type selector, watches a loading spinner while AI generates 3–6 maintenance tasks, edits the task list (name, interval, add/delete), optionally back-dates a service per task, and confirms. The confirmed appliance and its maintenance plan are then visible at `/appliances/{id}`. The wizard entry point is in the primary navigation.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| AI library | `prism-php/prism` | Only library with Laravel-native structured output + Anthropic + provider-swap for local dev | Research |
| Structured output | `Prism::structured()` + `ObjectSchema` | Built into Prism; no extra dependency; native constrained decoding on Claude Sonnet 4.5+ | Research |
| Livewire API style | Class-based Volt (anonymous class) | Every existing component in the codebase uses this style; `state()`/`rules()` functional API is not present | Research (B) |
| Loading UX | `wire:loading` + Alpine `x-init` trigger | Livewire 3.6.4 (not 4); `data-loading` is Livewire 4 only; `x-init` fires when step-2 div enters the DOM | Research (B) |
| `description` column | Add nullable column via migration | Shows AI-generated "why" in the review step and persists it for later reference | Plan |
| Type selector UX | Combobox: Alpine client-side filter + Enter-to-create | Seeded types should be selectable; custom type on no-match is a roadmap requirement (US-01) | Plan |
| DB writes timing | All in `confirm()` only, wrapped in `DB::transaction()` | No orphan records if user abandons mid-wizard; atomic on failure | Plan |
| AI failure handling | Error message + Retry button; block step advance without tasks | Simpler than a manual-entry fallback; wizard cannot confirm without at least one task | Plan |
| Task editing scope | Name, interval value+unit, delete, add custom task | Users need to correct AI suggestions and add household-specific tasks | Plan |
| Backdate data | Date or metric reading + optional notes | Sufficient to seed `next_due_at`; metric is wired but only relevant for future metric-unit tasks | Plan |
| No-backdate anchor | `today + interval` | Simple mental model; no null `next_due_at` state to handle in S-02 | Plan |
| Post-confirmation | Redirect to `/appliances/{id}` (new detail page) | User needs immediate confirmation of what was saved; detail page is lightweight to build | Plan |
| Testing | Happy path + AI failure + step validation + task editing (all via `Prism::fake()`) | These four scenarios cover the core flow and the main risk surface (AI integration) | Plan |
| AI model | `claude-sonnet-4-5` | Only model confirmed to support native constrained-decoding structured output | Research |
| AI call mode | Synchronous, timeout 30s | Background jobs disabled in v1 stack; Prism retry (2×500ms) handles transient errors | Research |
| Prompt caching | System prompt cached (`ephemeral`, 5 min) | Saves 60–80% prompt token cost on repeated use; resolved in research | Research |

## Scope

**In scope:**
- Install `prism-php/prism` + publish config + add `ANTHROPIC_API_KEY` to `.env.example`
- Migration: add nullable `description` column to `maintenance_tasks`
- `GenerateMaintenancePlan` action class (encapsulates Prism call)
- 4-step Volt wizard (`/appliances/create`)
- Appliance detail page (`/appliances/{id}`, read-only)
- Route registration + primary nav link
- Four model factories (Household, ApplianceType, Appliance, MaintenanceTask)
- Four Livewire test scenarios with `Prism::fake()`

**Out of scope:**
- Appliance edit/delete (S-03)
- Dashboard task listing by status (S-02)
- Metric-unit tasks (hours/km) in the wizard
- Streaming AI output (`wire:stream`)
- Manual task-entry fallback when AI fails
- Per-household email notifications or background scheduling

## Architecture / Approach

A class-based Volt component (`create.blade.php`) holds all wizard state in public properties across 4 steps. The AI call is handled by a dedicated `GenerateMaintenancePlan` action injected via Laravel's service container. The type selector is a combobox built with `@entangle` (deferred Livewire sync) + Alpine client-side filtering — no per-keystroke roundtrips. All DB writes are deferred to `confirm()` and wrapped in a transaction. The detail page (`show.blade.php`) is a minimal read-only Volt component with route model binding + household ownership check.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Foundation | Prism installed, description migration, factories | `config/prism.php` not generated if vendor:publish is skipped |
| 2. AI action | `GenerateMaintenancePlan` action, testable in isolation | Real API call latency / format — verify with Tinker before Phase 3 |
| 3. Wizard component | Full 4-step Volt wizard — the core feature | Alpine `x-init` trigger for AI call; `@entangle` combobox sync; `next_due_at` branching |
| 4. Detail page + routes + nav | `/appliances/{id}`, route registration, nav link | Route ordering (`/create` before `/{appliance}`) |
| 5. Test suite | 4 feature tests, all green, zero real API calls | Volt test helper API (`Livewire\Volt\Volt::test()`) |

**Prerequisites:** F-01 complete (all domain models and migrations in place). `ANTHROPIC_API_KEY` provisioned in `.env` for manual Phase 2 verification.

**Estimated effort:** ~3–4 implementation sessions across 5 phases. Phase 3 (wizard) is the largest and should be split across at least 2 sessions.

## Open Risks & Assumptions

- **AI response quality**: prompt engineering in `GenerateMaintenancePlan` constrains `interval_unit` to calendar values, but real-world task naming and interval accuracy are unknown until tested against a real API key.
- **`Prism::fake()` Volt test API**: the exact test helper for class-based Volt components (`Livewire\Volt\Volt::test()`) should be verified against the installed Livewire 3.6.4 + Volt 1.7.0 versions before writing Phase 5 tests.
- **Single-household assumption**: `Auth::user()->households()->first()` is used throughout; breaks silently if a user belongs to multiple households (not a v1 scenario, but worth noting).

## Success Criteria (Summary)

- `php artisan test --filter Appliance` — all 4 test scenarios pass with zero real API calls
- Full wizard flow completes in browser with a real `ANTHROPIC_API_KEY` — tasks generated, plan confirmed, detail page shows the plan
- `Appliance.is_plan_confirmed = true`, `MaintenanceTask.is_confirmed = true`, `next_due_at` set per task, `ServiceRecord` present for backdated tasks — verified in Tinker

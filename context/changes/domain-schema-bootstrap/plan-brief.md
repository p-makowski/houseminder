# Domain Schema Bootstrap — Plan Brief

> Full plan: `context/changes/domain-schema-bootstrap/plan.md`

## What & Why

Create the complete data model for House Minder: six new migrations, five Eloquent models, 13 seeded appliance types, and an extended registration flow that creates a Household alongside the User. This is the prerequisite foundation for every downstream slice — S-01 (AI plan), S-02 (dashboard), and S-03 (appliance CRUD) all depend on the tables and models created here.

## Starting Point

A fresh Laravel 13.8 + Breeze + Livewire Volt scaffold with only three infrastructure migrations (users, cache, jobs) and a single model (`User`). The registration form creates a User only; no Household concept exists.

## Desired End State

Registering an account creates three records in one DB transaction: a User, a Household (with the household's name), and a pivot row linking them with `role = owner`. Thirteen common appliance types are seeded. All five domain models are available with correct Eloquent relationships. The schema is ready for S-01 to begin building the appliance-add + AI suggestion flow.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| User ≠ Household | Separate entities with a pivot | Designed for future N:N multi-user households; v1 enforces 1:1 via the transaction | Plan |
| Pivot role column | Added now (`owner\|member`, default `owner`) | Cheap to add at pivot creation; retrofitting later requires a back-fill migration | Plan |
| Custom appliance types | `household_id` nullable on `appliance_types` | One table for all types; system types have `household_id = null`, custom types carry the household FK | Plan |
| Metric type location | On `maintenance_tasks.interval_unit`, not on `appliance_types` | Each task has its own interval unit (oil change = hours, air filter = months) | Clarified during planning |
| Dual next-due fields | `next_due_at` (datetime) + `next_due_at_value` (double) | Calendar tasks use a date; metric tasks (hours, km) use a threshold value — single field can't serve both | Clarified during planning |
| Dashboard metric scheduling | Metric-aware (user inputs current reading for hours/km tasks) | Accurate overdue detection for motor-hour or odometer tasks requires current meter input | Plan |
| Anchor date stored in F-01 | `anchor_date` date (nullable) alongside `anchor_type` enum | S-01 needs this column to implement fixed-calendar scheduling; avoids a back-migration | Plan |
| `household_name` in registration | Added as a separate field; `users.name` stays as personal display name | Keeps existing display references working; household name lives in `households.name` | Plan |

## Scope

**In scope:**
- 6 new migrations: households, household_user, appliance_types, appliances, maintenance_tasks, service_records
- 5 new Eloquent models: Household, ApplianceType, Appliance, MaintenanceTask, ServiceRecord
- User model: add `belongsToMany(Household::class)` relationship
- Registration Volt component: add household_name field + DB::transaction wrapping all three record creations
- ApplianceTypeSeeder: 13 system types (household_id = null)

**Out of scope:**
- No changes to existing users migration or users.name column
- No metric_type on appliance_types — metrics are per task, not per type
- No soft deletes — permanent deletion is a S-03 concern
- No new automated tests — business logic tests belong to S-01/S-02/S-03
- No changes to login, password reset, or email verification flows

## Architecture / Approach

`users` ←M:N pivot `household_user`→ `households` ←1:M→ `appliances` ←1:M→ `maintenance_tasks` ←1:M→ `service_records`. `appliance_types` hangs off `households` (nullable FK: null = system, set = custom). Three phases, each independently verifiable: Household scaffold first (root FK), then the type catalogue, then the appliance domain chain.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Household scaffold + registration | households + pivot tables; Household model; registration creates all three records atomically | `household_name` must be stripped from validated array before `User::create()` — easy to miss |
| 2. Appliance types + seeder | appliance_types table; ApplianceType model; 13 seeded system types | SQLite NULL uniqueness quirk — seeder uses `updateOrCreate`, not `upsert` |
| 3. Core domain models | appliances + maintenance_tasks + service_records tables and models | Dual `next_due_at` / `next_due_at_value` fields are unusual — callers must check `interval_unit` to know which to query |

**Prerequisites:** None — F-01 has no upstream change dependencies. `database/database.sqlite` must exist (it does).

**Estimated effort:** ~1 focused session across 3 phases.

## Open Risks & Assumptions

- `DB::transaction` in the Volt component must fire `Registered` event *after* the transaction commits, not inside it — if placed inside, email verification may fire against an uncommitted user row
- `restrictOnDelete()` on `appliances.appliance_type_id` means deleting a seeded type will fail if any appliances reference it — acceptable for v1 (types are seeded once and never deleted)
- `users.name` is kept as the personal display name but the PRD doesn't mention a personal name at registration — this adds a form field the PRD doesn't explicitly call for; accepted as a deliberate v1 choice to avoid breaking navigation/profile references

## Success Criteria (Summary)

- `php artisan migrate && php artisan db:seed` runs cleanly with 13 appliance types in the DB
- Registration creates a user + household + pivot row in one transaction; data visible in DB
- `php artisan test` passes with no regressions in existing Breeze auth tests

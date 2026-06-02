# Domain Schema Bootstrap — Implementation Plan

## Overview

Establish the complete data model for House Minder. This change creates six new migrations and five new Eloquent models, seeds 13 common appliance types, and extends registration to atomically create a Household alongside the User. Every downstream slice (S-01 through S-03) depends on this foundation.

## Current State Analysis

Fresh Laravel 13.8 + Livewire Volt + Tailwind Breeze scaffold. Three infrastructure migrations exist (users, cache, jobs). Only `app/Models/User.php` exists — no domain models. The Volt registration component at `resources/views/livewire/pages/auth/register.blade.php` creates a User only; no Household concept exists. `database/database.sqlite` is present and migrations have already run once.

## Desired End State

- `php artisan migrate` runs all six new migrations cleanly in order
- `php artisan db:seed` populates 13 system appliance types (household_id = null)
- Registering a new account creates User + Household + household_user pivot row (role: owner) in one DB transaction
- All five domain models (Household, ApplianceType, Appliance, MaintenanceTask, ServiceRecord) are available with correct Eloquent relationships
- Registration form has both a personal Name field and a Household Name field

### Key Discoveries

- `app/Models/User.php` uses PHP 8 attribute syntax: `#[Fillable([...])]` / `#[Hidden([...])]` — all new models must follow this pattern
- Every file uses `declare(strict_types=1)` — mandatory on all new files
- Migrations use `return new class extends Migration` anonymous class syntax
- Volt registration component is inline PHP + Blade in one `.blade.php` file — `public string $name` + `validate()` + `User::create()` in one class
- `household_name` is NOT a column on `users` — it must be extracted from the validated array before `User::create()` is called
- `Registered` event must fire **after** the DB transaction commits so the user row exists when email verification is triggered

## What We're NOT Doing

- No changes to the `users` table or existing migrations — `users.name` is the personal display name, no rename
- No `metric_type` on `appliance_types` — metrics are per maintenance task, not per type
- No unique index on `appliance_types.name` — SQLite treats NULLs as always distinct in UNIQUE constraints; idempotency is handled by `updateOrCreate` in the seeder
- No soft deletes on any table in v1 — permanent deletion is handled with a confirmation step in S-03
- No new feature tests — test coverage for business logic belongs to S-01/S-02/S-03. The existing Breeze registration test is updated to pass `household_name` in Phase 1 (maintaining, not adding)
- No changes to any existing auth flows (login, password reset, email verification)

## Implementation Approach

Three phases in strict dependency order:

1. **Household scaffold + registration** — the root; every other table FKs to `households`
2. **Appliance types + seeder** — the type catalogue; independent of Phase 3 but must run before `appliances` migration creates the FK
3. **Core domain models** — appliances, tasks, records; depends on Phases 1 and 2

## Critical Implementation Details

**`household_name` extraction in registration**: The validated array contains `household_name`, but `User::create()` must not receive it (no such column on `users`). Extract it before hashing the password, then use it inside the transaction to create the Household. Order: `extract household_name → hash password → DB::transaction(create User → create Household → attach pivot) → fire Registered event → login`.

**`DB::transaction` scope**: The `Registered` event fires email verification. It must fire *outside* the transaction (after commit) to avoid the event handler querying a user row that hasn't been committed yet. Fire it on the returned `$user` after `DB::transaction()` returns.

---

## Phase 1: Household Scaffold + Registration

### Overview

Creates `households` and `household_user` tables, the `Household` Eloquent model, updates `User` with the households relationship, and rewires the registration Volt component to create all three records atomically.

### Changes Required

#### 1. Households migration

**File:** `database/migrations/2026_06_01_000001_create_households_table.php`

**Intent:** Create the `households` table — the entity that owns appliances and maintenance data.

**Contract:** `up()` creates table `households`: `id()`, `string('name')`, `timestamps()`. `down()` calls `Schema::dropIfExists('households')`.

#### 2. Household-user pivot migration

**File:** `database/migrations/2026_06_01_000002_create_household_user_table.php`

**Intent:** Create the `household_user` pivot joining users and households, with a `role` column enabling future N:N multi-user support.

**Contract:** `up()` creates table `household_user`: `id()`, `foreignId('household_id')->constrained()->cascadeOnDelete()`, `foreignId('user_id')->constrained()->cascadeOnDelete()`, `enum('role', ['owner', 'member'])->default('owner')`, `timestamps()`. `down()` drops the table.

#### 3. Household model

**File:** `app/Models/Household.php`

**Intent:** Eloquent model for Household. Declares relationships to users (via pivot), appliances, and custom appliance types.

**Contract:** `declare(strict_types=1)`. Namespace `App\Models`. `#[Fillable(['name'])]` attribute. Three relationships: `belongsToMany(User::class)->withPivot('role')`, `hasMany(Appliance::class)`, `hasMany(ApplianceType::class)`.

#### 4. User model — households relationship

**File:** `app/Models/User.php`

**Intent:** Add the `households()` relationship so `$user->households()->first()` gives the household for query scoping throughout the app.

**Contract:** Add `public function households(): BelongsToMany` returning `$this->belongsToMany(Household::class)->withPivot('role')`. All existing `#[Fillable]`, `#[Hidden]`, and `casts()` remain unchanged.

#### 5. Registration Volt component

**File:** `resources/views/livewire/pages/auth/register.blade.php`

**Intent:** Add a Household Name field to the registration form and wrap the record-creation logic in a `DB::transaction` that atomically creates the User, the Household, and the pivot row.

**Contract:**
- Add `public string $household_name = ''` property alongside the existing properties
- Add `'household_name' => ['required', 'string', 'max:255']` to the validation array
- In `register()`: extract `$householdName = $validated['household_name']` and remove the key before passing `$validated` to `User::create()`. Wrap User + Household + pivot creation in `DB::transaction(function() use (...) { ... })`. Fire `event(new Registered($user))` and call `Auth::login($user)` after the transaction returns — not inside it
- Blade template: add a `<div class="mt-4">` block for Household Name below the Name field, using the same `<x-input-label>` / `<x-text-input wire:model="household_name">` / `<x-input-error>` pattern as the existing fields

#### 6. Registration test update

**File:** `tests/Feature/Auth/RegistrationTest.php`

**Intent:** Keep the existing Breeze registration test green after Phase 1 adds `household_name` as a required field.

**Contract:** In the test that POSTs to `/register`, add `'household_name' => 'Test Household'` to the request data array. No new test cases — only updating the existing POST payload so validation passes.

### Success Criteria

#### Automated Verification

- `php artisan migrate` completes without errors — two new tables present in the DB
- `php artisan test` passes — existing Breeze auth tests remain green after the `household_name` change

#### Manual Verification

- Visit `/register`, fill Name + Household Name + Email + Password, submit
- Inspect DB: one row each in `users`, `households`, `household_user`; pivot `role` = `owner`; household `name` matches the form input

---

## Phase 2: Appliance Types + Seeder

### Overview

Creates the `appliance_types` table supporting both system-seeded and per-household custom types, the `ApplianceType` model, and a seeder that idempotently populates 13 system types.

### Changes Required

#### 1. Appliance types migration

**File:** `database/migrations/2026_06_01_000003_create_appliance_types_table.php`

**Intent:** Create the `appliance_types` table. System types have `household_id = null`; custom per-household types carry a household FK.

**Contract:** `up()` creates table `appliance_types`: `id()`, `foreignId('household_id')->nullable()->constrained()->nullOnDelete()`, `string('name')`, `timestamps()`. `down()` drops the table.

#### 2. ApplianceType model

**File:** `app/Models/ApplianceType.php`

**Intent:** Eloquent model for appliance types. When querying types for a household, callers filter with `whereNull('household_id')->orWhere('household_id', $householdId)`.

**Contract:** `declare(strict_types=1)`. `#[Fillable(['household_id', 'name'])]`. Relationships: `belongsTo(Household::class)` (nullable — returns null for system types), `hasMany(Appliance::class)`.

#### 3. ApplianceTypeSeeder

**File:** `database/seeders/ApplianceTypeSeeder.php`

**Intent:** Insert the 13 system appliance types with `household_id = null`. Uses `updateOrCreate` per type so the seeder is safe to re-run without creating duplicates.

**Contract:** Seeder class with `use WithoutModelEvents`. `run(): void` calls `ApplianceType::updateOrCreate(['name' => $name, 'household_id' => null], [])` for each of the 13 names: Refrigerator, Washing Machine, Dryer, Dishwasher, HVAC / Air Conditioner, Water Heater, Oven / Range, Microwave, Vacuum Cleaner, Car / Vehicle, Lawn Mower, Generator, Other.

#### 4. DatabaseSeeder — register ApplianceTypeSeeder + seed household for test user

**File:** `database/seeders/DatabaseSeeder.php`

**Intent:** Ensure `php artisan db:seed` runs the appliance type seeder before the user factory, and that the test user gets a Household so local dev state matches what registration produces.

**Contract:** Add `$this->call(ApplianceTypeSeeder::class)` as the first line of `run()`, before the existing `User::factory()->create(...)` call. After the User is created, also create a Household (`Household::create(['name' => 'Test Household'])`) and attach the user to it via `$user->households()->attach($household->id, ['role' => 'owner'])`.

### Success Criteria

#### Automated Verification

- `php artisan migrate` completes without errors
- `php artisan db:seed` completes without errors
- `php artisan tinker --execute="echo App\Models\ApplianceType::whereNull('household_id')->count();"` outputs `13`

#### Manual Verification

- Run `php artisan db:seed` twice — count stays at 13 (no duplicates)
- Tinker: `ApplianceType::create(['household_id' => 1, 'name' => 'Wood Burning Stove'])` persists and `ApplianceType::where('household_id', 1)->first()->name` returns `Wood Burning Stove`

---

## Phase 3: Core Domain Models

### Overview

Creates the three remaining domain tables (appliances, maintenance_tasks, service_records) and their Eloquent models. The maintenance_tasks table has dual next-due fields to support both calendar-based and metric-based scheduling.

### Changes Required

#### 1. Appliances migration

**File:** `database/migrations/2026_06_01_000004_create_appliances_table.php`

**Intent:** Create the `appliances` table — owned by a Household, typed by ApplianceType.

**Contract:** `up()` creates table `appliances`: `id()`, `foreignId('household_id')->constrained()->cascadeOnDelete()`, `foreignId('appliance_type_id')->constrained()->restrictOnDelete()`, `string('name')`, `string('model')`, `date('purchase_date')->nullable()`, `boolean('is_plan_confirmed')->default(false)`, `timestamps()`. `down()` drops the table.

Note on `restrictOnDelete()` for `appliance_type_id`: prevents deleting a type while appliances reference it, protecting data integrity.

#### 2. Maintenance tasks migration

**File:** `database/migrations/2026_06_01_000005_create_maintenance_tasks_table.php`

**Intent:** Create `maintenance_tasks` with flexible interval units and dual next-due fields. `next_due_at` (datetime) is used for time-based intervals; `next_due_at_value` (double) is used for metric-based intervals (hours, km). Both may be null until the plan is confirmed in S-01.

**Contract:** `up()` creates table `maintenance_tasks`:
- `id()`
- `foreignId('appliance_id')->constrained()->cascadeOnDelete()`
- `string('name')`
- `unsignedInteger('interval_value')`
- `enum('interval_unit', ['days', 'weeks', 'months', 'years', 'hours', 'km'])`
- `enum('anchor_type', ['from_last_done', 'fixed_calendar'])->default('from_last_done')`
- `date('anchor_date')->nullable()`
- `dateTime('last_completed_at')->nullable()`
- `double('last_metric_value')->nullable()`
- `dateTime('next_due_at')->nullable()`
- `double('next_due_at_value')->nullable()`
- `boolean('is_confirmed')->default(false)`
- `timestamps()`

`down()` drops the table.

#### 3. Service records migration

**File:** `database/migrations/2026_06_01_000006_create_service_records_table.php`

**Intent:** Create `service_records` — the append-only history of completed maintenance events. `metric_reading` stores the absolute meter value at time of service (e.g., total engine hours, current odometer), from which `next_due_at_value` is derived in S-01/S-02.

**Contract:** `up()` creates table `service_records`: `id()`, `foreignId('maintenance_task_id')->constrained()->cascadeOnDelete()`, `dateTime('completed_at')`, `double('metric_reading')->nullable()`, `text('notes')->nullable()`, `timestamps()`. `down()` drops the table.

#### 4. Appliance model

**File:** `app/Models/Appliance.php`

**Intent:** Eloquent model for appliances. All application queries must scope by `household_id` to enforce the data-isolation NFR.

**Contract:** `declare(strict_types=1)`. `#[Fillable(['household_id', 'appliance_type_id', 'name', 'model', 'purchase_date', 'is_plan_confirmed'])]`. Relationships: `belongsTo(Household::class)`, `belongsTo(ApplianceType::class)`, `hasMany(MaintenanceTask::class)`. `casts()` returns `['purchase_date' => 'date', 'is_plan_confirmed' => 'boolean']`.

#### 5. MaintenanceTask model

**File:** `app/Models/MaintenanceTask.php`

**Intent:** Eloquent model for maintenance tasks. Callers check `interval_unit` to know which next-due field to use: `next_due_at` for calendar units, `next_due_at_value` for metric units.

**Contract:** `declare(strict_types=1)`. `#[Fillable(['appliance_id', 'name', 'interval_value', 'interval_unit', 'anchor_type', 'anchor_date', 'last_completed_at', 'last_metric_value', 'next_due_at', 'next_due_at_value', 'is_confirmed'])]`. Relationships: `belongsTo(Appliance::class)`, `hasMany(ServiceRecord::class)`. `casts()` returns `['anchor_date' => 'date', 'last_completed_at' => 'datetime', 'next_due_at' => 'datetime', 'is_confirmed' => 'boolean']`.

#### 6. ServiceRecord model

**File:** `app/Models/ServiceRecord.php`

**Intent:** Eloquent model for service history records.

**Contract:** `declare(strict_types=1)`. `#[Fillable(['maintenance_task_id', 'completed_at', 'metric_reading', 'notes'])]`. Relationship: `belongsTo(MaintenanceTask::class)`. `casts()` returns `['completed_at' => 'datetime']`.

### Success Criteria

#### Automated Verification

- `php artisan migrate` runs all six new migrations without errors
- `php artisan test` passes with no regressions in existing Breeze auth tests

#### Manual Verification

- Tinker: create an Appliance belonging to a Household, attach a MaintenanceTask, create a ServiceRecord; verify `$appliance->maintenanceTasks()->with('serviceRecords')->get()` returns correctly nested data
- Tinker: verify data isolation — create two Households each with one Appliance; `Household::find(1)->appliances` returns only its own appliance

---

## Testing Strategy

### Automated Tests

- `php artisan test` after each phase — confirms no regressions in Breeze auth tests (registration, login, profile)
- No new feature tests in this change — business logic (schedule calculation, AI suggestions, dashboard grouping) belongs to S-01/S-02/S-03

### Manual Testing Steps

1. Register a new user → DB has user + household + pivot (role: owner)
2. `php artisan db:seed` → 13 types in `appliance_types` with `household_id = null`; re-run → still 13
3. Tinker: full chain — Appliance → MaintenanceTask → ServiceRecord → relationships resolve in both directions
4. Tinker: data isolation — two households, each sees only its own appliances

## Migration Notes

Migrations run automatically on Fly.io deploy via `release_command: php artisan migrate --force` in `fly.toml`. All six are additive (no existing tables altered). Dependency order is enforced by the `0001_06_01_00000N_` prefix sequence. Any rollback must happen in reverse order: service_records → maintenance_tasks → appliances → appliance_types → household_user → households.

## References

- Roadmap: `context/foundation/roadmap.md` (F-01)
- PRD: `context/foundation/prd.md` (FR-001, FR-002, FR-005, FR-010–013)
- Tech stack: `context/foundation/tech-stack.md`
- Registration component: `resources/views/livewire/pages/auth/register.blade.php`
- User model: `app/Models/User.php`
- Migrations reference: `database/migrations/0001_01_01_000000_create_users_table.php`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Household Scaffold + Registration

#### Automated

- [x] 1.1 `php artisan migrate` completes without errors (households + household_user tables created) — a50f3c4
- [x] 1.3 `php artisan test` passes after Phase 1 changes (no regressions in Breeze auth tests) — a50f3c4

#### Manual

- [x] 1.2 Register a new user with household name → one row each in users, households, household_user; pivot role = owner — a50f3c4

### Phase 2: Appliance Types + Seeder

#### Automated

- [ ] 2.1 `php artisan migrate` completes without errors (appliance_types table created)
- [ ] 2.2 `php artisan db:seed` completes without errors
- [ ] 2.3 `ApplianceType::whereNull('household_id')->count()` returns 13

#### Manual

- [ ] 2.4 Re-seeding is idempotent — running db:seed twice keeps count at 13

### Phase 3: Core Domain Models

#### Automated

- [ ] 3.1 `php artisan migrate` runs all six migrations without errors
- [ ] 3.2 `php artisan test` passes with no regressions

#### Manual

- [ ] 3.3 Tinker: Appliance → MaintenanceTask → ServiceRecord chain created and relationships resolve both ways
- [ ] 3.4 Data isolation confirmed: two households, each sees only its own appliances via the relationship

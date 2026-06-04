# First Appliance + AI Plan (S-01) Implementation Plan

## Overview

Implement the north-star user flow: a 4-step Volt wizard where a user adds an appliance, receives AI-generated maintenance task suggestions via Anthropic (prism-php/prism), reviews and edits the task list, optionally backdates services, and confirms the plan. Confirmed state persists in the DB. After confirmation the user lands on an appliance detail page at `/appliances/{id}`.

## Current State Analysis

- **Dashboard**: empty placeholder ("You're logged in!"). S-01 is the first real user-facing flow.
- **Domain models**: Appliance, ApplianceType, MaintenanceTask, ServiceRecord, Household all exist (F-01). `maintenance_tasks` has no `description` column — one migration needed.
- **Livewire**: 3.6.4, class-based Volt (anonymous class pattern), `wire:loading` available, `wire:init` fires only on component mount (not on step change — see Critical Implementation Details).
- **AI**: no packages installed; `prism-php/prism` must be added; `ANTHROPIC_API_KEY` not in `.env.example`.
- **ApplianceType**: 13 seeded system types (`household_id = null`) + per-household custom types. Must use two-tier query (`whereNull` OR `where household_id`) — lessons.md rule.
- **Routing**: `Volt::route()` used for Volt pages; new appliance routes must carry `['auth', 'verified']` middleware.
- **Navigation**: primary nav has space for new links; currently only "Dashboard".

### Key Discoveries

- `app/Models/MaintenanceTask.php` — `$fillable` must be updated to include `description` after migration.
- `interval_unit` DB enum: `days|weeks|months|years|hours|km`. S-01 AI prompt must constrain to calendar units only; implementation must branch on `interval_unit` before writing `next_due_at` vs `next_due_at_value` (lessons.md rule).
- `anchor_type` enum: `from_last_done|fixed_calendar`. S-01 plan confirmation must calculate `next_due_at` correctly for both.
- Only `UserFactory` exists — four new factories needed for test setup.
- `resources/views/components/` — `x-app-layout`, `x-text-input`, `x-input-label`, `x-input-error`, `x-primary-button`, `x-secondary-button`, `x-modal` all available; use these to match existing UI conventions.

## Desired End State

After this plan is complete:
- A logged-in user can navigate to `/appliances/create`, fill in appliance details, receive AI-generated tasks, edit the list, optionally backdate services, and confirm the plan.
- Confirmed data is persisted: `Appliance.is_plan_confirmed = true`, each `MaintenanceTask.is_confirmed = true`, `next_due_at` set per task, `ServiceRecord` rows created for backdated `from_last_done` tasks only (not for `fixed_calendar` tasks).
- User lands on `/appliances/{id}` showing the confirmed appliance and its task list.
- A nav link is present in the primary navigation.
- All four test scenarios pass with `Prism::fake()` (no real API calls in CI).

### How to verify

Run `php artisan test --filter Appliance` → all pass. Then manually complete the full wizard in a browser against a real `ANTHROPIC_API_KEY` — tasks appear, plan confirms, detail page shows the plan.

## What We're NOT Doing

- No appliance edit/delete (S-03).
- No dashboard task listing by status (S-02).
- No file uploads, email notifications, or cross-household sharing (parked in roadmap).
- No metric-unit (hours/km) tasks in S-01 — AI is constrained to calendar units; metric-type task creation is not built.
- No `wire:stream` / streaming output — synchronous Prism call with `wire:loading` spinner.
- No manual task-creation fallback when AI fails — Retry button only; user cannot proceed past step 2 without at least one task.
- No per-user appliance scoping beyond household — v1 is single-household-per-user.
- No appliance factory in S-01 seeds — factories are for testing only.

## Implementation Approach

All DB writes are deferred to the `confirm()` action in step 4 (no partial saves during the wizard). The wizard holds all user-entered state in Livewire public properties throughout. The AI call is synchronous; the loading state between step 1 → step 2 uses an Alpine `x-init` bridge (see Critical Implementation Details). The `GenerateMaintenancePlan` action encapsulates the Prism call so it can be independently faked in tests.

## Critical Implementation Details

**Alpine `x-init` AI trigger** — `wire:init` fires once on initial component mount, not when `$step` changes. To auto-trigger `fetchSuggestions()` when the step-2 loading div enters the DOM, use Alpine:
```blade
<div x-data x-init="$wire.fetchSuggestions()">
    {{-- spinner --}}
</div>
```
Render this div only when `$step === 2 && $aiLoading`. Alpine's `x-init` fires when the element is added to the DOM on each Livewire re-render. The Retry button calls `wire:click="retryFetch"` directly.

**Combobox `@entangle` sync** — using `wire:model.live` on the type search input would fire a Livewire roundtrip per keystroke. Instead, bind Alpine's local `search` to Livewire's `typeSearch` via `@entangle`, which defers sync until the next Livewire request (i.e., `nextStep()`). Alpine filters the full type list client-side on every keystroke:
```blade
<div x-data="{
    search: @entangle('typeSearch'),
    open: false,
    types: @js($allTypes),
    get filtered() {
        if (!this.search) return this.types;
        return this.types.filter(t => t.name.toLowerCase().includes(this.search.toLowerCase()));
    }
}">
```
`$allTypes` is a public array property loaded in `mount()` — not a computed property — to avoid per-request DB queries.

**`next_due_at` branch** — lessons.md requires branching on `interval_unit` before writing either date field. In `confirm()`:
```php
$nextDueAt = match($task['interval_unit']) {
    'days'   => $anchorDate->copy()->addDays($task['interval_value']),
    'weeks'  => $anchorDate->copy()->addWeeks($task['interval_value']),
    'months' => $anchorDate->copy()->addMonths($task['interval_value']),
    'years'  => $anchorDate->copy()->addYears($task['interval_value']),
};
// Always assign next_due_at (datetime). Leave next_due_at_value null — S-01 only creates calendar tasks.
```
`$anchorDate` is the backdate date if provided, otherwise `Carbon::today()`. For `fixed_calendar` tasks, `anchor_date = $anchorDate`; for `from_last_done`, `anchor_date = null` and `last_completed_at = $anchorDate` (if backdated) or `null`.

---

## Phase 1: Foundation

### Overview

Install `prism-php/prism`, publish its config, add the `ANTHROPIC_API_KEY` env var, add the `description` column to `maintenance_tasks`, update the model's `$fillable`, and create the four model factories needed by the test suite.

### Changes Required

#### 1. Install prism-php/prism

**File**: `composer.json` / `composer.lock` (modified by CLI)

**Intent**: Add the only new Composer dependency for S-01.

**Contract**: Run `composer require prism-php/prism`, then `php artisan vendor:publish --tag=prism-config` to generate `config/prism.php`.

#### 2. Prism config — Anthropic block

**File**: `config/prism.php` (generated by vendor:publish)

**Intent**: Wire the Anthropic provider to the `ANTHROPIC_API_KEY` env variable.

**Contract**: The published config already contains the correct Anthropic block. No manual edits required beyond verifying the key is `env('ANTHROPIC_API_KEY', '')`.

#### 3. `.env.example` — add Anthropic key

**File**: `.env.example`

**Intent**: Document the required API key so any developer or deploy script knows to set it.

**Contract**: Add `ANTHROPIC_API_KEY=` (empty value) under the third-party services block.

#### 4. Migration — add `description` to `maintenance_tasks`

**File**: `database/migrations/YYYY_MM_DD_HHMMSS_add_description_to_maintenance_tasks.php` (new)

**Intent**: Persist the AI-generated task description so users can read the "why" after the wizard completes.

**Contract**: `$table->string('description')->nullable()->after('name');` in `up()`; `$table->dropColumn('description');` in `down()`.

#### 5. Update MaintenanceTask `$fillable`

**File**: `app/Models/MaintenanceTask.php`

**Intent**: Allow mass-assignment of the new `description` field.

**Contract**: Add `'description'` to the `$fillable` array, between `'name'` and `'interval_value'`.

#### 6. Model factories

**Files** (new):
- `database/factories/HouseholdFactory.php`
- `database/factories/ApplianceTypeFactory.php`
- `database/factories/ApplianceFactory.php`
- `database/factories/MaintenanceTaskFactory.php`

**Intent**: Provide test setup without repetitive `Model::create()` calls in every test.

**Contract**:
- `HouseholdFactory`: generates a `name` string.
- `ApplianceTypeFactory`: `household_id = null` by default (system type); override with `->for($household)` for scoped types.
- `ApplianceFactory`: requires `household` and `applianceType` relationships; `is_plan_confirmed = false` by default; `purchase_date = null`.
- `MaintenanceTaskFactory`: requires `appliance`; defaults `interval_value = 6`, `interval_unit = 'months'`, `anchor_type = 'from_last_done'`, `is_confirmed = false`, `next_due_at = now()->addMonths(6)`, `next_due_at_value = null`.

### Success Criteria

#### Automated Verification

- `composer show prism-php/prism` lists the package without error
- `php artisan migrate --pretend` shows the `add_description_to_maintenance_tasks` migration without error
- `php artisan migrate` runs cleanly on a fresh DB
- `php artisan tinker --execute="App\Models\MaintenanceTask::factory()->make(['description' => 'test']);"` — no exception

#### Manual Verification

- `config/prism.php` exists and contains an `anthropic` block with `env('ANTHROPIC_API_KEY', '')`
- `.env.example` contains `ANTHROPIC_API_KEY=`
- `database/migrations/` contains the new `add_description` migration file

**Implementation Note**: After this phase and all automated verification passes, confirm manually that `config/prism.php` exists and the env key is present, then proceed to Phase 2.

---

## Phase 2: GenerateMaintenancePlan Action

### Overview

Encapsulate the Anthropic Prism call in a dedicated action class. This separation makes the AI call independently testable via `Prism::fake()` and keeps the wizard component lean.

### Changes Required

#### 1. GenerateMaintenancePlan action class

**File**: `app/Actions/GenerateMaintenancePlan.php` (new)

**Intent**: Accept raw appliance strings (not a persisted model — the appliance doesn't exist yet at call time), call Prism with structured output schema and cached system prompt, and return the `tasks` array. Let exceptions propagate — the wizard handles them.

**Contract**:
- Signature: `__invoke(string $applianceName, string $applianceModel, string $typeName): array`
- Returns: `array` of task maps — each `['name', 'description', 'interval_value', 'interval_unit']`.
- Provider: `Provider::Anthropic`, model `'claude-sonnet-4-5'`.
- Schema: `ObjectSchema` containing an `ArraySchema('tasks')` of `ObjectSchema('task')` with `StringSchema('name')`, `StringSchema('description')`, `IntegerSchema('interval_value')`, `StringSchema('interval_unit')`. Required fields: `name`, `interval_value`, `interval_unit`.
- System prompt (with Anthropic prompt caching): instructs the model to suggest 3–6 practical maintenance tasks, **constrain `interval_unit` to `days|weeks|months|years` only** (never `hours` or `km`), and return calendar-based intervals. Cache via `->withProviderOptions(['cacheType' => 'ephemeral', 'cacheTtl' => '5m'])` on the `SystemMessage`.
- User prompt: `"Suggest maintenance tasks for: {$applianceName}, model {$applianceModel}, type {$typeName}."` via `UserMessage` (not `withPrompt()` — prompt caching requires `withMessages()`).
- Options: `->usingTemperature(0.3)`, `->withMaxTokens(1024)`, `->withClientOptions(['timeout' => 30])`, `->withClientRetry(2, 500)`.
- Returns `$response->structured['tasks']`.
- Does NOT catch exceptions — `PrismException` and `\Throwable` propagate to the wizard.

### Success Criteria

#### Automated Verification

- `php artisan tinker --execute="app(\App\Actions\GenerateMaintenancePlan::class);"` — resolves without error
- `Prism::fake()` test (written in Phase 5) calls the action and returns the faked structured array

#### Manual Verification

- With a real `ANTHROPIC_API_KEY` set in `.env`, call the action from Tinker with an appliance name + model + type — verify 3–6 tasks are returned with calendar `interval_unit` values only

**Implementation Note**: Manual verification of the real API call is the key risk-reduction step for the product's core bet. Run it before proceeding to Phase 3.

---

## Phase 3: Appliance Wizard Component

### Overview

The 4-step class-based Volt component at `resources/views/livewire/pages/appliances/create.blade.php`. This is the core of S-01. All wizard state lives in public properties; all DB writes happen in `confirm()` only.

### Changes Required

#### 1. Wizard Volt component — class section

**File**: `resources/views/livewire/pages/appliances/create.blade.php` (new, class section)

**Intent**: Define all public properties, lifecycle hooks, and action methods for the 4-step wizard.

**Contract** — public properties:

| Property | Type | Purpose |
|---|---|---|
| `$step` | `int` (1–4) | Current wizard step; drives all conditional rendering |
| `$name` | `string` | Step 1: appliance name |
| `$model` | `string` | Step 1: appliance model (required per lessons.md) |
| `$typeSearch` | `string` | Step 1: bound via `@entangle` to Alpine combobox search input |
| `$selectedTypeId` | `?int` | Step 1: ID of chosen ApplianceType; null = custom type |
| `$purchaseDate` | `string` | Step 1: optional ISO date string |
| `$allTypes` | `array` | Loaded in `mount()` via two-tier query; fed to Alpine `@js($allTypes)` |
| `$aiLoading` | `bool` | Step 2: true while AI call is in flight; shows spinner div |
| `$aiError` | `?string` | Step 2: user-facing error message on AI failure |
| `$tasks` | `array` | Step 2: mutable task list — each entry `[name, description, interval_value, interval_unit, anchor_type]` |
| `$backdates` | `array` | Step 3: one entry per task — `[date, metric, notes, skip]` |

**Contract** — methods:

- `mount()`: retrieves `$household = Auth::user()->households()->first()`; calls `abort_if(!$household, 403)` immediately after. Stores as `$this->householdId`. Loads `$allTypes` using the two-tier pattern (`whereNull('household_id')->orWhere('household_id', $this->householdId)`), ordered by name. Returns `['id', 'name']` maps only.
- `selectType(int $id, string $name)`: sets `$selectedTypeId` and `$typeSearch`; called by Alpine when user picks from dropdown.
- `nextStep()`: dispatches to `advanceFromStep1()`, `advanceFromStep2()`, or increments `$step` (for step 3 → 4). Never skips validation.
- `prevStep()`: decrements `$step`; resets `$aiError`.
- `advanceFromStep1()`: validates `name` (required, string, max:255), `model` (required, string, max:255), `typeSearch` (required, string, max:255). On success: sets `$aiLoading = true`, `$step = 2`.
- `advanceFromStep2()`: guards that `$tasks` is non-empty. Always re-initialises `$backdates` from scratch (one `['date' => '', 'metric' => '', 'notes' => '', 'skip' => false]` per task). Sets `$step = 3`. Note: back-navigation from step 3 to step 2 intentionally resets backdates — preserving them would require index-matching against a mutable task list (add/delete/reorder), which is out of S-01 scope. Tradeoff accepted for simplicity.
- `fetchSuggestions()`: calls `GenerateMaintenancePlan` action; on success maps result to `$tasks` array (adds `'anchor_type' => 'from_last_done'` default); on `PrismException` / `\Throwable` sets `$aiError` and logs the error. Always sets `$aiLoading = false` in `finally`.
- `retryFetch()`: calls `fetchSuggestions()` directly.
- `deleteTask(int $index)`: splices from `$tasks`.
- `addTask()`: appends an empty task map to `$tasks` with defaults `interval_value = 6`, `interval_unit = 'months'`, `anchor_type = 'from_last_done'`.
- `confirm()`: see DB contract below.

**Contract** — `confirm()` DB transaction:

Wrapped in `DB::transaction()`. Steps in order:
1. Resolve `ApplianceType`: if `$selectedTypeId` is set, find it; otherwise `firstOrCreate(['name' => $typeSearch, 'household_id' => $householdId])`.
2. Create `Appliance` with `is_plan_confirmed = true`.
3. For each task in `$tasks`:
   - Determine `$anchorDate` (Carbon): if `$backdates[$i]['date']` is set and not skipped → parse it; otherwise `Carbon::today()`.
   - Calculate `$nextDueAt` via `match($task['interval_unit'])` branching (see Critical Implementation Details).
   - Create `MaintenanceTask` with `is_confirmed = true`, `next_due_at = $nextDueAt`, `next_due_at_value = null`, `anchor_date` = `$anchorDate` only if `anchor_type === 'fixed_calendar'`, `last_completed_at` = `$anchorDate` only if `anchor_type === 'from_last_done'` AND a backdate date was provided.
   - If `anchor_type === 'from_last_done'` AND (backdate date OR metric was provided and not skipped): create `ServiceRecord` with `completed_at`, `metric_reading`, `notes`. For `fixed_calendar` tasks, the backdate date is used only to compute `next_due_at` — no ServiceRecord is written.
4. Redirect to `route('appliances.show', $appliance)` via `$this->redirect(..., navigate: true)`.

#### 2. Wizard Volt component — Blade template

**File**: `resources/views/livewire/pages/appliances/create.blade.php` (new, template section)

**Intent**: Conditional step rendering using `@if($step === N)` blocks. Existing `x-` Blade components (`x-text-input`, `x-input-label`, `x-input-error`, `x-primary-button`) used throughout to match app conventions.

**Contract** — step 1 (appliance details):

Form with `wire:submit.prevent="nextStep"`. Fields:
- Name: `<x-text-input wire:model="name" />` + `<x-input-error :messages="$errors->get('name')" />`
- Model: same pattern with `wire:model="model"`
- Type: combobox (see Alpine contract in Critical Implementation Details). Shows "New type '…' will be created" hint when `!$selectedTypeId && $typeSearch` and no filtered matches.
- Purchase date: `<input type="date" wire:model="purchaseDate" />` (optional, no validation required)
- Submit: `<x-primary-button type="submit">Next</x-primary-button>`

**Contract** — step 2 (AI + review):

Three mutually exclusive sub-states, all inside `@if($step === 2)`:
- `$aiLoading`: show spinner div with `x-data x-init="$wire.fetchSuggestions()"`. Spinner uses Tailwind animate-spin. No user-actionable elements.
- `$aiError && !$aiLoading`: show `$aiError` text and a Retry button with `wire:click="retryFetch"` and `wire:loading.attr="disabled"`.
- `!$aiLoading && !$aiError && count($tasks)`: show editable task list wrapped in `wire:submit.prevent="nextStep"`.

Task list row (per task `$tasks[$i]`):
- Name input: `wire:model="tasks.{{ $i }}.name"`
- Description (read-only display, not editable): show AI description as contextual hint text.
- Interval value: `<input type="number" min="1" wire:model="tasks.{{ $i }}.interval_value">`
- Interval unit: `<select wire:model="tasks.{{ $i }}.interval_unit">` with options: days/weeks/months/years.
- Anchor type: `<select wire:model="tasks.{{ $i }}.anchor_type">` with options: `from_last_done` / `fixed_calendar`.
- Delete: `<button wire:click.prevent="deleteTask({{ $i }})">Remove</button>`

Below task list: `<button wire:click.prevent="addTask">+ Add task</button>`.

Submit: `<x-primary-button>Next</x-primary-button>` + Back button `wire:click="prevStep"`.

**Contract** — step 3 (backdate):

For each task, a collapsible row:
- Skip checkbox: `<input type="checkbox" wire:model="backdates.{{ $i }}.skip">` — when checked, hides the date/metric/notes fields.
- Date field: shown for all tasks in S-01 (AI only generates calendar tasks). Label changes based on `anchor_type`: `from_last_done` → "When did you last do this?", `fixed_calendar` → "Schedule from this date:". Both use `<input type="date" wire:model="backdates.{{ $i }}.date">`.
- Notes: `<textarea wire:model="backdates.{{ $i }}.notes">` (optional).
- Metric field (`backdates.{{ $i }}.metric`): rendered but conditionally shown only when `interval_unit` is `hours` or `km` — not applicable in S-01 since AI is constrained to calendar units, but the field must be wired for correctness.

**Contract** — step 4 (confirmation summary):

Read-only summary: appliance name, model, type name (resolved from `$selectedTypeId` or `$typeSearch`), task count. Task list shows name + interval + anchor type (no editing). Confirm button: `<x-primary-button wire:click="confirm" wire:loading.attr="disabled">Confirm Plan</x-primary-button>`. Back button: `wire:click="prevStep"`.

### Success Criteria

#### Automated Verification

- `php artisan route:list | grep appliances` shows the `/appliances/create` route (after Phase 4 routes are added)
- Step 1 validation test passes (Phase 5)
- Happy path test passes (Phase 5)

#### Manual Verification

- Full wizard flow works end-to-end in browser with a real API key
- Combobox: typing "wash" filters to "Washing Machine"; Enter with "Custom Thing" with no match shows the "will be created" hint; confirm creates the ApplianceType
- Step 2 AI loading spinner appears, then task list renders
- Task editing: name changes, interval changes, delete a task, add a custom task — all reflect in step 4 summary
- Step 3 skip checkbox hides the date field
- Step 4 confirm writes all records — check via `php artisan tinker` after confirming
- Redirect lands on `/appliances/{id}`

**Implementation Note**: Pause here and complete the full manual browser verification before proceeding to Phase 4.

---

## Phase 4: Appliance Detail Page + Routes + Navigation

### Overview

Register the two new routes, build the read-only appliance detail page that S-01 redirects to, and add the navigation link. This phase makes the wizard accessible from the app shell.

### Changes Required

#### 1. Route registration

**File**: `routes/web.php`

**Intent**: Make `/appliances/create` and `/appliances/{appliance}` accessible to authenticated, verified users.

**Contract**: Add `use Livewire\Volt\Volt;` at the top of `web.php`. Register two routes:
```php
Volt::route('appliances/create', 'pages.appliances.create')
    ->middleware(['auth', 'verified'])
    ->name('appliances.create');

Volt::route('appliances/{appliance}', 'pages.appliances.show')
    ->middleware(['auth', 'verified'])
    ->name('appliances.show');
```
`/appliances/create` must be declared before `{appliance}` to avoid route conflicts.

#### 2. Appliance detail Volt component

**File**: `resources/views/livewire/pages/appliances/show.blade.php` (new)

**Intent**: Read-only post-confirmation view. Shows the confirmed appliance and its maintenance plan. No edit or delete (S-03).

**Contract**:
- `#[Layout('layouts.app')]` attribute.
- `mount(Appliance $appliance)`: verify the appliance belongs to the authenticated user's household (`abort_if($appliance->household_id !== $household->id, 403)`); eager-load `applianceType` and `maintenanceTasks`; assign to `$this->appliance`.
- Template: appliance header (name, model, type name, purchase date if set). Task list showing per-task: name, description (if set), interval (e.g. "Every 3 months"), anchor type label, next due date formatted as human-readable. Link to dashboard (`route('dashboard')`).
- No edit/delete controls.

#### 3. Navigation link

**File**: `resources/views/livewire/layout/navigation.blade.php`

**Intent**: Give users a persistent entry point to start the wizard from any authenticated page.

**Contract**: Add an `<x-nav-link>` for "Add Appliance" in the primary nav `div` (alongside the existing Dashboard link), and a matching `<x-responsive-nav-link>` in the mobile menu section:
```blade
<x-nav-link :href="route('appliances.create')" :active="request()->routeIs('appliances.create')">
    {{ __('Add Appliance') }}
</x-nav-link>
```

### Success Criteria

#### Automated Verification

- `php artisan route:list | grep appliances` shows both routes with correct middleware
- Unauthenticated GET `/appliances/create` redirects to `/login` (Laravel default guard behaviour)

#### Manual Verification

- "Add Appliance" link appears in the nav bar when logged in
- `/appliances/{id}` shows the confirmed appliance and task list (visit after completing the wizard)
- `/appliances/{id}` for an appliance belonging to a different household returns 403
- Mobile nav menu contains the responsive link

**Implementation Note**: Confirm all manual items before proceeding to Phase 5.

---

## Phase 5: Test Suite

### Overview

Four Pest/PHPUnit feature tests covering the four scenarios selected during planning. All use `Prism::fake()` — no real API calls. Tests live in `tests/Feature/Appliances/`.

### Changes Required

#### 1. Happy path test

**File**: `tests/Feature/Appliances/AddApplianceWizardTest.php` (new)

**Intent**: Verify the full wizard flow creates the correct DB records when everything goes right.

**Contract**:
- Set up: create `User` + `Household` via factories; attach user to household; `actingAs($user)`.
- `Prism::fake([StructuredResponseFake::make()->withStructured(['tasks' => [...2 tasks...]])->withUsage(new Usage(120, 80))])`.
- Use `Livewire\Volt\Volt::test('pages.appliances.create')` (or `Livewire::test()` equivalent for Volt).
- Call sequence: `->set('name', 'Test Washer')->set('model', 'WM500')->set('typeSearch', 'Washing Machine')->set('selectedTypeId', $washingMachineTypeId)->call('nextStep')` (step 1 → 2, triggers `$aiLoading = true`).
- Call `fetchSuggestions()` explicitly (since Alpine `x-init` is not executed in unit tests).
- `->assertSet('tasks', fn($tasks) => count($tasks) === 2)`.
- Call `nextStep()` (step 2 → 3), call `nextStep()` (step 3 → 4), call `confirm()`.
- Assert: `Appliance::where('name', 'Test Washer')->where('is_plan_confirmed', true)->exists()`.
- Assert: `MaintenanceTask::where('is_confirmed', true)->count() === 2`.
- Assert redirect to `route('appliances.show', ...)`.
- `$fake->assertCallCount(1)`.

#### 2. AI failure test

**File**: `tests/Feature/Appliances/AiFailureTest.php` (new)

**Intent**: Verify that a `PrismException` surfaces the error message and Retry button without crashing the wizard, and that a successful retry proceeds normally.

> **Phase 5 decision point (F2 fix)**: Before writing this test, inspect the installed Prism source for exception-simulation support:
> - `grep -r "throw\|exception\|Exception" vendor/prism-php/prism/src/Testing/ --include="*.php" -l`
> - If `StructuredResponseFake` (or a sibling class) supports throwing, use `Prism::fake()` with exception config.
> - If not, bind a mock of `GenerateMaintenancePlan` via `$this->instance(GenerateMaintenancePlan::class, fn() => throw new PrismException('test'))` and use `Prism::fake()` only for the success case.

**Contract**:
- `Prism::fake()` configured to throw `PrismException` on first call, return valid structured data on second (or mock GenerateMaintenancePlan directly — see decision point above).
- Call `fetchSuggestions()` → `->assertSet('aiError', fn($e) => !is_null($e))` → `->assertSet('aiLoading', false)`.
- Call `retryFetch()` → `->assertSet('tasks', fn($t) => count($t) > 0)` → `->assertSet('aiError', null)`.

#### 3. Step validation test

**File**: `tests/Feature/Appliances/WizardValidationTest.php` (new)

**Intent**: Verify that step 1 cannot be advanced without required fields.

**Contract**:
- Call `nextStep()` with `$name = ''` → `->assertHasErrors(['name'])` → `->assertSet('step', 1)`.
- Call `nextStep()` with `$name = 'Washer'`, `$model = ''` → `->assertHasErrors(['model'])`.
- Call `nextStep()` with `$name = 'Washer'`, `$model = 'WM500'`, `$typeSearch = ''` → `->assertHasErrors(['typeSearch'])`.

#### 4. Task editing test

**File**: `tests/Feature/Appliances/TaskEditingTest.php` (new)

**Intent**: Verify that name edit, interval edit, delete, and add-task all propagate correctly to the DB after confirmation.

**Contract**:
- After `fetchSuggestions()` with `Prism::fake()` (2 faked tasks).
- Edit: `->set('tasks.0.name', 'Custom Name')->set('tasks.0.interval_value', 12)`.
- Delete: `->call('deleteTask', 1)` → `->assertSet('tasks', fn($t) => count($t) === 1)`.
- Add: `->call('addTask')` → `->assertSet('tasks', fn($t) => count($t) === 2)`.
- Advance to step 4 and call `confirm()`.
- Assert DB: `MaintenanceTask::where('name', 'Custom Name')->where('interval_value', 12)->exists()`.
- Assert: total `MaintenanceTask` count for the appliance is 2 (1 edited + 1 custom).

### Success Criteria

#### Automated Verification

- `php artisan test --filter Appliance` — all 4 test files pass
- `php artisan test --filter Appliance` — no real HTTP calls to Anthropic (Prism::fake() in all tests)

#### Manual Verification

- N/A — all four scenarios are covered by automated tests

**Implementation Note**: All four test files must pass before S-01 is considered complete.

---

## Testing Strategy

### Unit Tests

- `GenerateMaintenancePlan` action: `Prism::fake()` verifies schema, prompt constraints, retry config. One test per the action's contract.

### Integration Tests (Livewire)

- Happy path: full wizard → DB state (Phase 5, test 1)
- AI failure + retry (Phase 5, test 2)
- Step validation (Phase 5, test 3)
- Task editing → DB (Phase 5, test 4)

### Manual Testing Steps

1. Set `ANTHROPIC_API_KEY` in `.env`. Run `php artisan migrate`.
2. Register a user; confirm email (or set `MAIL_MAILER=log`).
3. Click "Add Appliance" in the nav → verify step 1 loads.
4. Fill name + model; type "wash" in type field → verify "Washing Machine" appears; select it.
5. Click Next → verify spinner appears, then task list with 3–6 tasks.
6. Edit one task name; delete another; add a custom task. Click Next.
7. Backdate one task; skip another. Click Next.
8. Review step 4 summary. Click "Confirm Plan".
9. Verify redirect to `/appliances/{id}`. Verify task list appears.
10. In Tinker: verify `Appliance.is_plan_confirmed = true`, `MaintenanceTask.is_confirmed = true` per task, `next_due_at` set, `ServiceRecord` exists for the backdated `from_last_done` task (no ServiceRecord for `fixed_calendar` tasks).
11. Visit `/appliances/{other_household_id}` → verify 403.

## Performance Considerations

- `withClientOptions(['timeout' => 30])` caps the synchronous AI call at 30 seconds. The wizard blocks during this time — acceptable for S-01 given no background jobs.
- `allTypes` is loaded once in `mount()` (not per-render computed) — single DB query per wizard session.
- Prompt caching (`'cacheType' => 'ephemeral'`, TTL `'5m'`) reduces cost and latency on repeated wizard use within a session.

## Migration Notes

- The `add_description_to_maintenance_tasks` migration is additive (nullable column) and safe to run on an empty table (F-01 created the table but no user data exists yet).
- No data backfill required.

## References

- Research: `context/changes/first-appliance-ai-plan/research.md` — Part A (Prism API reference + schema), Part B (codebase compatibility audit)
- Lessons: `context/foundation/lessons.md` — three rules that directly constrain this implementation
- F-01 archive: `context/archive/2026-06-01-domain-schema-bootstrap/` — domain model decisions
- Prism fake API: `research.md § Testing with Prism::fake()`
- Existing Volt patterns: `resources/views/livewire/pages/auth/register.blade.php`
- Existing action pattern: `app/Livewire/Actions/Logout.php`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Foundation

#### Automated

- [x] 1.1 `composer show prism-php/prism` lists the package without error — c21a180
- [x] 1.2 `php artisan migrate --pretend` shows the description migration without error — c21a180
- [x] 1.3 `php artisan migrate` runs cleanly on a fresh DB — c21a180
- [x] 1.4 `php artisan tinker` — `MaintenanceTask::factory()->make(['description' => 'test'])` — no exception — c21a180

#### Manual

- [x] 1.5 `config/prism.php` exists and contains Anthropic block with `env('ANTHROPIC_API_KEY', '')` — c21a180
- [x] 1.6 `.env.example` contains `ANTHROPIC_API_KEY=` — c21a180

### Phase 2: GenerateMaintenancePlan Action

#### Automated

- [x] 2.1 `app(GenerateMaintenancePlan::class)` resolves without error
- [ ] 2.2 `Prism::fake()` test calls the action and returns the faked structured array (verified via Phase 5 happy path test)

#### Manual

- [x] 2.3 Real API call via Tinker returns 3–6 tasks with calendar `interval_unit` values only

### Phase 3: Appliance Wizard Component

#### Automated

- [ ] 3.1 Step 1 validation test passes (Phase 5 test 3)
- [ ] 3.2 Happy path test passes (Phase 5 test 1)
- [ ] 3.3 Task editing test passes (Phase 5 test 4)

#### Manual

- [ ] 3.4 Full wizard flow works end-to-end in browser with real API key
- [ ] 3.5 Combobox filters correctly; custom type "will be created" hint appears on no-match
- [ ] 3.6 AI loading spinner appears, then task list renders
- [ ] 3.7 Task editing (name, interval, delete, add) reflects in step 4 summary
- [ ] 3.8 Backdate skip checkbox hides date field
- [ ] 3.9 Confirm writes all DB records and redirects to `/appliances/{id}`

### Phase 4: Appliance Detail Page + Routes + Navigation

#### Automated

- [ ] 4.1 `php artisan route:list | grep appliances` shows both routes with correct middleware
- [ ] 4.2 Unauthenticated GET `/appliances/create` redirects to `/login`

#### Manual

- [ ] 4.3 "Add Appliance" nav link appears when logged in
- [ ] 4.4 `/appliances/{id}` shows confirmed appliance and task list
- [ ] 4.5 `/appliances/{other_id}` returns 403

### Phase 5: Test Suite

#### Automated

- [ ] 5.1 Happy path test (`AddApplianceWizardTest`) passes
- [ ] 5.2 AI failure test (`AiFailureTest`) passes
- [ ] 5.3 Step validation test (`WizardValidationTest`) passes
- [ ] 5.4 Task editing test (`TaskEditingTest`) passes
- [ ] 5.5 `php artisan test --filter Appliance` — all pass, zero real API calls

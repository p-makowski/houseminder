# Dashboard Tasks and Mark Done — Implementation Plan

## Overview

Convert the placeholder `/dashboard` page into a live Volt component that lists all confirmed maintenance tasks across the household's appliances in four urgency sections, with a mark-done action that logs a `ServiceRecord` and advances the next due date.

## Current State Analysis

The `/dashboard` route and nav link both exist, but the view (`resources/views/dashboard.blade.php`) is a static blade rendering "You're logged in!". Every other auth-required page is a class-based Volt component — the dashboard is the sole exception.

`MaintenanceTask` carries all the fields needed (`next_due_at`, `last_completed_at`, `interval_value`, `interval_unit`, `anchor_type`, `is_confirmed`) but has zero Eloquent scopes. `ServiceRecord` exists and is the established completion log, but no runtime mark-done action has ever been implemented — the only completion code is the wizard's `confirm()` method in `create.blade.php:153–241`, which serves as the reference pattern.

### Key Discoveries

- `resources/views/dashboard.blade.php` — static placeholder; must be deleted when the Volt page is in place
- `routes/web.php` — dashboard is a closure route; must become `Volt::route` (Volt facade already imported)
- `create.blade.php:201–207` — existing `next_due_at` recalculation pattern (match on interval_unit, add interval to anchor date)
- `tests/Feature/Appliances/ApplianceTestCase.php` — test base class pattern: `RefreshDatabase`, User + Household factory, `actingAs`
- `phpstan.neon` — Larastan level 6, analyses `app/`; PHPStan and Pint both present in `vendor/`

## Desired End State

`/dashboard` is a Volt page that:
- Shows four sections: **Overdue** (past due), **Due this week** (due within 7 days), **Upcoming** (due later), **Manual tracking** (metric tasks — informational only)
- Each calendar task row displays appliance name, task name, due date, and a "Mark done" button
- Clicking "Mark done" creates a `ServiceRecord`, updates `last_completed_at`, recalculates `next_due_at = now() + interval`, and re-renders the four sections without a page reload
- Metric tasks appear in the "Manual tracking" section with a "No date" badge; no mark-done button
- Only `is_confirmed = true` tasks are shown; unconfirmed draft tasks are invisible

### Verification

Navigate to `/dashboard` after running `php artisan migrate --seed` and creating an appliance via the wizard with at least one backdated task. Overdue tasks appear in the Overdue section; clicking Mark done moves the task to Upcoming without a page reload.

## What We're NOT Doing

- Task editing or deletion (S-03)
- Mark-done for metric tasks with a reading input (future)
- Filtering or searching by appliance
- Configurable "due soon" threshold (7 days is hardcoded)
- Pagination (household scale — tens of tasks)

## Implementation Approach

Two phases in dependency order: backend logic first (scopes + action + isolated tests), then the UI layer (Volt page + page tests). This lets `RecordTaskCompletion` be verified in isolation before it is wired to a component.

## Critical Implementation Details

- **`is_confirmed` filter**: all scopes must include `where('is_confirmed', true)` — wizard sessions that stall before step 4 leave unconfirmed tasks in the DB that must not surface on the dashboard.
- **Eager loading**: every query in the Volt component must eager-load `appliance` to display appliance names; skipping it causes N+1 queries.
- **Ownership guard — double layer**: `RecordTaskCompletion::execute()` guards internally with `abort_if`; the Volt component's `markDone()` must also fetch the task through the household scope before passing it to the action — defense in depth against direct `wire:click` manipulation.
- **Route change**: the old `resources/views/dashboard.blade.php` must be deleted after the Volt page is in place; leaving it creates a confusing dead file since Volt no longer routes through it.

---

## Phase 1: Backend Foundation

### Overview

Add three Eloquent scopes to `MaintenanceTask` and extract a `RecordTaskCompletion` action class. No UI changes in this phase. Tests cover the action in isolation.

### Changes Required

#### 1. MaintenanceTask — query scopes

**File**: `app/Models/MaintenanceTask.php`

**Intent**: Add three composable Eloquent scopes that encode the query invariants (confirmed, calendar vs. metric, household boundary) so callers never need to re-state them.

**Contract**:
- `scopeCalendar(Builder $query)`: restricts to `interval_unit IN (days, weeks, months, years)`, `is_confirmed = true`, `next_due_at IS NOT NULL`
- `scopeMetric(Builder $query)`: restricts to `interval_unit IN (hours, km)`, `is_confirmed = true`
- `scopeForHousehold(Builder $query, int $householdId)`: `whereHas('appliance', fn($q) => $q->where('household_id', $householdId))`

Date-range conditions (overdue, due-this-week, upcoming) are applied by callers, not embedded in scopes, to keep the scopes composable.

#### 2. RecordTaskCompletion action

**File**: `app/Actions/RecordTaskCompletion.php`

**Intent**: Encapsulate the three-step completion write so the dashboard component and any future caller share one implementation. Mirrors the wizard's completion logic but operates at runtime against `now()` instead of a user-supplied backdate.

**Contract**:
- Single public method: `execute(MaintenanceTask $task, User $user): void`
- Ownership guard: resolve household first, then guard — matching the pattern at `create.blade.php:169-170`:
  `$household = $user->households()->first();`
  `abort_if(!$household || $task->appliance->household_id !== $household->id, 403);`
  Direct `->first()->id` without a null guard will throw TypeError (not 403) and fail PHPStan level 6.
- Wraps writes in `DB::transaction`
- Inserts `ServiceRecord` with `completed_at = now()`
- Sets `last_completed_at = now()` on the task
- Recalculates `next_due_at` by matching on `interval_unit` (days/weeks/months/years → add interval to `now()`); leaves `next_due_at` unchanged for metric units (`hours`/`km`) — the lesson from `lessons.md` requires branching on `interval_unit` before any next-due write
- Both `from_last_done` and `fixed_calendar` anchor types use the same "advance from now()" calculation (user decision from planning)

#### 3. Phase 1 tests

**File**: `tests/Feature/Dashboard/RecordTaskCompletionTest.php`

**Intent**: Verify the action's full contract in isolation before any UI is built.

**Contract**: Extends a base class matching `ApplianceTestCase` (`RefreshDatabase`, factory User + Household, `actingAs`). Test cases:
- Executing the action creates a `ServiceRecord` row with `completed_at` ≈ now
- `last_completed_at` on the task is updated to ≈ now
- `next_due_at` is recalculated correctly for each calendar unit (days, weeks, months, years)
- `next_due_at` is **not** changed for a metric task (`interval_unit = 'hours'`)
- A task belonging to a **different** household causes a 403 abort

### Success Criteria

#### Automated Verification

- Dashboard feature tests pass: `php artisan test tests/Feature/Dashboard/`
- PHPStan passes with no new errors: `./vendor/bin/phpstan analyse`
- Pint reports no style issues: `./vendor/bin/pint app/Models/MaintenanceTask.php app/Actions/RecordTaskCompletion.php --test`

#### Manual Verification

- `app/Models/MaintenanceTask.php` declares the three scopes
- `app/Actions/RecordTaskCompletion.php` exists alongside `GenerateMaintenancePlan.php`
- Existing appliance wizard tests still pass: `php artisan test tests/Feature/Appliances/`

**Implementation Note**: After all automated verifications pass, pause for manual confirmation that the appliance wizard tests still pass before proceeding to Phase 2.

---

## Phase 2: Dashboard Volt Page

### Overview

Convert the `/dashboard` route to a `Volt::route`, create the Volt component with four sections and a `markDone()` action wired to `RecordTaskCompletion`, remove the old static blade file, and add feature tests for the full page flow.

### Changes Required

#### 1. Route conversion

**File**: `routes/web.php`

**Intent**: Replace the closure-based dashboard route with a `Volt::route` to make the dashboard consistent with every other auth-required page in the app.

**Contract**: The closure and `return view('dashboard')` are removed. The replacement is:
```php
Volt::route('/dashboard', 'pages.dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
```
The `Volt` facade import at the top of the file already covers this route.

#### 2. Delete old static dashboard blade

**File**: `resources/views/dashboard.blade.php`

**Intent**: Remove the static placeholder — it is unreachable after the route change and would mislead future developers.

**Contract**: File deleted. No other file references it directly (the nav link uses `route('dashboard')`, not a view name).

#### 3. Dashboard Volt component

**File**: `resources/views/livewire/pages/dashboard.blade.php`

**Intent**: A class-based Volt page that loads four task collections for the authenticated user's household and exposes a `markDone()` action that updates the DB and re-renders the four sections in place.

**Contract**:

Component class opens with `new #[Layout('layouts.app')] class extends Component`.

Four public Collection properties: `$overdue`, `$dueThisWeek`, `$upcoming`, `$metric`.

`mount()` resolves `auth()->user()->households()->first()->id` (aborts 403 if no household), then assigns each property:
- `$overdue` — `calendar()->forHousehold($id)->where('next_due_at', '<', now())->orderBy('next_due_at')->with('appliance')->get()`
- `$dueThisWeek` — `calendar()->forHousehold($id)->whereBetween('next_due_at', [now(), now()->addDays(7)])->orderBy('next_due_at')->with('appliance')->get()`
- `$upcoming` — `calendar()->forHousehold($id)->where('next_due_at', '>', now()->addDays(7))->orderBy('next_due_at')->with('appliance')->get()`
- `$metric` — `metric()->forHousehold($id)->orderBy('name')->with('appliance')->get()`

`markDone(int $taskId)`: resolves `$householdId` fresh via `Auth::user()->households()->first()` inside the method — must NOT be stored as a public Livewire property (public properties are serialised to the client and are tamperable). Fetches task using `MaintenanceTask::calendar()->forHousehold($householdId)->findOrFail($taskId)` (scope acts as ownership guard at fetch time; including `calendar()` makes the task unfetchable for metric IDs). Calls `(new RecordTaskCompletion)->execute($task, auth()->user())`. Extracts the four collection queries into a private `loadTasks()` method called from both `mount()` and `markDone()` to avoid duplication.

Template: `<x-app-layout>` with `<x-slot name="header">Dashboard</x-slot>`. Four `<section>` blocks rendered in order. Each section is hidden if its collection is empty (empty-state message shown instead). Calendar task rows include the Mark Done button: `wire:click="markDone({{ $task->id }})"`. Metric task rows show a "No date" badge and no button.

#### 4. Phase 2 tests

**File**: `tests/Feature/Dashboard/DashboardPageTest.php`

**Intent**: Verify that the Volt page renders the right tasks in the right sections and that the mark-done flow works end-to-end via Livewire test helpers.

**Contract**: Same base pattern as `ApplianceTestCase`. Test cases:
- Unauthenticated GET `/dashboard` redirects to `/login` (via the `auth` middleware; the `verified` middleware only redirects authenticated-but-unverified users to `/verify-email`)
- Authenticated user sees "Dashboard" in the response
- A task with `next_due_at = yesterday` appears in the overdue section
- A task with `next_due_at = now()->addDays(3)` appears in the due-this-week section
- A task with `next_due_at = now()->addDays(30)` appears in the upcoming section
- A metric task (`interval_unit = 'hours'`) appears in the manual tracking section
- A task with `is_confirmed = false` does not appear in any section
- A task belonging to a different household's appliance does not appear
- Calling `markDone()` via Livewire creates a `ServiceRecord` and updates `last_completed_at` on the task

### Success Criteria

#### Automated Verification

- Full test suite passes: `php artisan test`
- PHPStan passes: `./vendor/bin/phpstan analyse`
- Pint reports no style issues: `./vendor/bin/pint --test`

#### Manual Verification

- Navigate to `/dashboard` after login — page loads with four section headings (some may show empty states)
- Create an appliance with a maintenance plan; backdate one task to appear overdue
- Overdue task appears in the Overdue section; upcoming task appears in Upcoming
- Click "Mark done" on the overdue task — it moves to Upcoming with no page reload
- Run `php artisan tinker` → `App\Models\ServiceRecord::latest()->first()` — confirm the record was created
- Run `php artisan tinker` → `App\Models\MaintenanceTask::latest()->first()->next_due_at` — confirm recalculation
- A task from a different household does not appear (create a second user + household via tinker if needed)

**Implementation Note**: After all automated verifications pass, pause for manual testing confirmation before declaring Phase 2 complete.

---

## Testing Strategy

### Unit Tests

None — the action and page are fully covered by feature tests against the in-memory SQLite database.

### Integration Tests

- `RecordTaskCompletionTest` — action in isolation
- `DashboardPageTest` — Volt page with Livewire's test helpers

### Manual Testing Steps

1. Log in, navigate to `/dashboard` — empty-state messages for all four sections
2. Run wizard: add an appliance with two calendar tasks, backdate one to yesterday
3. Reload dashboard — overdue task in Overdue, future task in Upcoming
4. Click "Mark done" on the overdue task — it moves to Upcoming without reload
5. Verify via tinker: `ServiceRecord::latest()->first()` shows today's record
6. Verify: `MaintenanceTask::find($id)->next_due_at` is in the future

## Performance Considerations

Four separate queries per load — acceptable at household scale (tens of tasks). All queries eager-load `appliance` to eliminate N+1. No caching needed at this scale.

## Migration Notes

No schema changes. The `/dashboard` URL and `dashboard` named route are preserved — the navigation component requires no changes.

## References

- Research: `context/changes/dashboard-tasks-and-mark-done/research.md`
- Completion pattern reference: `resources/views/livewire/pages/appliances/create.blade.php:153–241`
- Volt page pattern: `resources/views/livewire/pages/appliances/show.blade.php`
- Test base class: `tests/Feature/Appliances/ApplianceTestCase.php`
- Lessons: `context/foundation/lessons.md` (interval_unit branching rule)

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Backend Foundation

#### Automated

- [ ] 1.1 Dashboard feature tests pass: `php artisan test tests/Feature/Dashboard/`
- [ ] 1.2 PHPStan passes with no new errors: `./vendor/bin/phpstan analyse`
- [ ] 1.3 Pint reports no style issues on new files: `./vendor/bin/pint app/Models/MaintenanceTask.php app/Actions/RecordTaskCompletion.php --test`

#### Manual

- [ ] 1.4 `app/Models/MaintenanceTask.php` declares the three scopes
- [ ] 1.5 `app/Actions/RecordTaskCompletion.php` exists alongside `GenerateMaintenancePlan.php`
- [ ] 1.6 Existing appliance wizard tests still pass: `php artisan test tests/Feature/Appliances/`

### Phase 2: Dashboard Volt Page

#### Automated

- [ ] 2.1 Full test suite passes: `php artisan test`
- [ ] 2.2 PHPStan passes: `./vendor/bin/phpstan analyse`
- [ ] 2.3 Pint reports no style issues: `./vendor/bin/pint --test`

#### Manual

- [ ] 2.4 `/dashboard` loads after login with four section headings
- [ ] 2.5 Overdue task visible in Overdue section after wizard run with backdated task
- [ ] 2.6 Mark done moves task to Upcoming without page reload
- [ ] 2.7 `ServiceRecord::latest()->first()` exists with today's `completed_at`
- [ ] 2.8 `MaintenanceTask::find($id)->next_due_at` is recalculated to a future date

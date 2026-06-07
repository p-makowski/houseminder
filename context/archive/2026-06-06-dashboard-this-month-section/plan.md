# "This Month" Section + Recurrence Labels — Implementation Plan

## Overview

Add a "This month" section to both the dashboard and the appliance detail page, showing calendar tasks due in the rolling 30-day window beyond "this week". Narrow "Upcoming" to tasks due after 30 days. Add a properly pluralized recurrence label to every calendar task card on both pages. The appliance detail page switches from a flat sorted list to the same four-section structure (plus a "Manual tracking" section at the bottom), while keeping its sort buttons to re-order within each section.

---

## Current State Analysis

**Dashboard** (`resources/views/livewire/pages/dashboard.blade.php`): Livewire Volt inline component. Four `#[Computed]` methods: `overdue()` (line 31), `dueThisWeek()` (line 42), `upcoming()` (line 55), `metric()` (line 66). `upcoming()` has no upper bound — everything beyond 7 days lands there. Recurrence label missing from three of the four card blocks; only the manual-tracking block (line 165) shows it.

**Appliance detail** (`resources/views/livewire/pages/appliances/show.blade.php`): Single `sortedTasks()` computed property (line 49) returns a flat PHP-sorted list. Sort buttons call `setSortBy()` (line 186). Status/border colour inferred per-card via inline `match(true)` (line 240). Recurrence label already present (line 349).

**Tests**: `DashboardTestCase` and `ApplianceTestCase` are the base classes. `DashboardBoundaryTest` covers the 7-day boundary. `ApplianceShowDisplayTest` asserts border colours on the flat list — these tests use `is_confirmed = false` (factory default).

---

## Desired End State

**Dashboard**: five sections — Overdue, Due this week, This month (blue), Upcoming, Manual tracking — every section with an empty-state message. Every calendar task card shows "Every N unit(s)" below the due date. "This month" captures `now()+7d < next_due_at ≤ now()+30d`; "Upcoming" captures `next_due_at > now()+30d`.

**Appliance detail**: same five sections, same sort-within-section behaviour. Sort buttons (Name / Due date / Frequency) re-order within each section. Metric tasks in a "Manual tracking" section at the bottom.

**Tests**: 30-day boundary fully covered; appliance section display tested.

### Key Discoveries

- `MaintenanceTask::calendar()` scope filters `is_confirmed = true` — the appliance detail must NOT use it, because the existing display tests create tasks with `is_confirmed = false` (factory default, `app/Models/MaintenanceTaskFactory.php`)
- Appliance detail's `sortedTasks()` sort logic (lines 54–68) is a clean extract into a private `sortTasks(Collection): Collection` helper
- `DashboardBoundaryTest::test_task_due_one_second_past_seven_days_is_upcoming` technically passes after Phase 1 (task appears in "This month", assertions still hold) but the test name becomes misleading — update it
- `Str::plural` handles `days/weeks/months/years` correctly; metric section keeps raw `{{ $task->interval_unit }}` (the `calendar()` filter keeps metric tasks out of calendar sections)
- `now()` is called independently in each computed property — consistent with existing codebase pattern; sub-second race window is acceptable

---

## What We're NOT Doing

- No shared `task-card` Blade component (no extraction of duplicate card markup)
- No `recurrenceLabel()` model accessor
- No change to how `is_confirmed` affects the dashboard (still only confirmed tasks)
- No metric-task pluralization (metric section keeps raw format)
- No multi-household support
- No changes to the wizard, AI generation, or task creation flows

---

## Implementation Approach

Phase 1 is self-contained: dashboard query changes + Blade view + boundary tests. Phase 2 restructures the appliance detail: replace the flat computed property with five per-section ones sharing a private sort helper, then update the view template.

---

## Critical Implementation Details

**Appliance detail must not use `calendar()` scope.** `MaintenanceTask::calendar()` requires `is_confirmed = true`. The appliance detail page intentionally shows all tasks (including unconfirmed AI-generated ones). Use `$this->appliance->maintenanceTasks()->whereIn('interval_unit', [...])` instead. Skipping this detail would cause existing `ApplianceShowDisplayTest` tests to fail silently when tasks disappear from all sections.

**`sortTasks` accesses `$this->sortBy`.** The helper is a private method on the Volt component — it can read `$this->sortBy` directly. No parameter needed.

---

## Phase 1: Dashboard — "This month" section + recurrence labels

### Overview

Add one computed property, tighten the `upcoming()` boundary, insert the "This month" section in the Blade view, add recurrence labels to all four calendar card blocks, and update/add boundary tests.

### Changes Required

#### 1. Add `Str` import to dashboard PHP block

**File**: `resources/views/livewire/pages/dashboard.blade.php`

**Intent**: Make `Str::plural()` available in the Volt PHP section and the Blade template below.

**Contract**: Add `use Illuminate\Support\Str;` to the existing `use` block (lines 1–11).

---

#### 2. Add `dueThisMonth()` computed property

**File**: `resources/views/livewire/pages/dashboard.blade.php`

**Intent**: Return confirmed calendar tasks with `next_due_at` strictly after 7 days and at most 30 days from now, ordered by due date.

**Contract**: `#[Computed]` method returning `Collection`, placed between `dueThisWeek()` and `upcoming()`. Query chain mirrors `dueThisWeek()`: `MaintenanceTask::calendar()->forHousehold($id)->where(...)->where(...)->orderBy('next_due_at')->with('appliance')->get()`. Lower bound: `where('next_due_at', '>', now()->addDays(7))`. Upper bound: `where('next_due_at', '<=', now()->addDays(30))`.

---

#### 3. Tighten `upcoming()` upper bound

**File**: `resources/views/livewire/pages/dashboard.blade.php` (line 59)

**Intent**: "Upcoming" now starts at 30 days instead of 7.

**Contract**: Change the single `where` condition from `now()->addDays(7)` to `now()->addDays(30)`.

---

#### 4. Insert "This month" section in the Blade view

**File**: `resources/views/livewire/pages/dashboard.blade.php`

**Intent**: Render the new section between "Due this week" and "Upcoming" with blue colour styling and a consistent empty state.

**Contract**: Section block following the same structure as the "Due this week" section. Header text: "This month". Header class: `text-blue-700`. Card border: `border-blue-200`. Date text class: `text-blue-600`. Date format: `M j, Y`. "Mark done" button: same indigo style. Empty state: "Nothing due this month." Iterates `$this->dueThisMonth`.

---

#### 5. Add recurrence label to all four calendar card blocks

**File**: `resources/views/livewire/pages/dashboard.blade.php`

**Intent**: Each calendar task card shows "Every N unit(s)" below the due-date line, using correct pluralisation.

**Contract**: In each of the four calendar section card `<div>` inner blocks (overdue ~line 98, due this week ~line 120, new "this month" block, upcoming ~line 142), add a `<p>` after the due-date line:

```blade
<p class="text-sm text-gray-500">Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}</p>
```

The manual-tracking section (line 165) already shows `{{ $task->interval_unit }}` — update it to use `Str::plural` for consistency:

```blade
<p class="text-sm text-gray-500">Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}</p>
```

---

#### 6. Add 30-day boundary tests

**File**: `tests/Feature/Dashboard/DashboardThisMonthBoundaryTest.php` (new file)

**Intent**: Cover the two critical boundaries — `now()+7d` vs `now()+7d+1s` (this week / this month split) and `now()+30d` vs `now()+30d+1s` (this month / upcoming split) — matching the style of `DashboardBoundaryTest`.

**Contract**: Extends `DashboardTestCase`. Four test methods:

- Task at `now()->addDays(7)->addSecond()` → in "this month"; "Upcoming" empty
- Task at `now()->addDays(30)` → in "this month"; "No upcoming tasks."
- Task at `now()->addDays(30)->addSecond()` → in "upcoming"; "Nothing due this month."
- Task at `now()->addDays(15)` → in "this month" (mid-window sanity check)

---

#### 7. Update misleading boundary test method

**File**: `tests/Feature/Dashboard/DashboardBoundaryTest.php`

**Intent**: The method `test_task_due_one_second_past_seven_days_is_upcoming` names the wrong section after Phase 1 — the task now lands in "This month". Rename and add an assertion that confirms it.

**Contract**: Rename to `test_task_due_one_second_past_seven_days_is_in_this_month`. Add `->assertSee('No upcoming tasks.')` to the assertion chain.

---

### Success Criteria

#### Automated Verification

- `php artisan test --filter=Dashboard` — all dashboard tests pass, including 4 new boundary tests
- `composer phpstan` — no new errors

#### Manual Verification

- Dashboard loads; tasks due in 8–30 days appear under "This month" (blue heading) and NOT under "Upcoming"
- Tasks due in > 30 days appear under "Upcoming" only
- Every calendar task card shows "Every N month(s)" (or day/week/year variant) below the due date
- "This month" section shows "Nothing due this month." when empty
- "Upcoming" section shows "No upcoming tasks." when empty

**Implementation Note**: After all automated verification passes, manually confirm the above before proceeding to Phase 2.

---

## Phase 2: Appliance detail — sections with per-section sort

### Overview

Replace the single `sortedTasks()` computed property with five per-section computed properties sharing a private `sortTasks()` helper. Replace the flat task loop in the Blade view with five section blocks — each with header, empty state, and a task loop containing the inline edit form and delete button. Sort buttons continue to drive `$this->sortBy`, which `sortTasks()` reads directly.

### Changes Required

#### 1. Add `Str` import to appliance show PHP block

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Make `Str::plural()` available for calendar-section recurrence labels.

**Contract**: Add `use Illuminate\Support\Str;` to the existing `use` block (lines 1–16) if not already present.

---

#### 2. Add private `sortTasks()` helper

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Extract the sort logic from `sortedTasks()` into a reusable private method, so all five section computed properties share identical sort behaviour.

**Contract**: Private method `sortTasks(Collection $tasks): Collection`. Body is an exact extraction of the current `match($this->sortBy)` block (lines 54–68 of `sortedTasks()`). Reads `$this->sortBy` directly — no parameter needed.

---

#### 3. Replace `sortedTasks()` with five section computed properties

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Provide a separate reactive collection for each section; each applies a date (or type) filter and then calls `sortTasks()`.

**Contract**: Remove the `#[Computed] public function sortedTasks()` method entirely. Add five `#[Computed]` methods. **Important**: do NOT chain the `calendar()` scope — the appliance detail shows all tasks regardless of `is_confirmed`. Use `$this->appliance->maintenanceTasks()` with manual filter conditions.

| Method | Filter | Sort |
|--------|--------|------|
| `overdue()` | `whereIn(interval_unit, calendar_units)->whereNotNull(next_due_at)->where(next_due_at < now())` | `$this->sortTasks(...)` |
| `dueThisWeek()` | `... ->whereBetween(next_due_at, [now(), now()->addDays(7)])` | `$this->sortTasks(...)` |
| `dueThisMonth()` | `... ->where(next_due_at > now()+7d)->where(next_due_at <= now()+30d)` | `$this->sortTasks(...)` |
| `upcoming()` | `... ->where(next_due_at > now()+30d)` | `$this->sortTasks(...)` |
| `metric()` | `whereIn(interval_unit, ['hours','km'])` | `$this->sortTasks(...)` |

Calendar units array: `['days', 'weeks', 'months', 'years']`.

Each query ends with `->get()`. No `with('appliance')` needed (the appliance is already known from the page context).

---

#### 4. Replace flat task loop with five section blocks

**File**: `resources/views/livewire/pages/appliances/show.blade.php` (lines 234–355)

**Intent**: Remove the flat `@foreach($this->sortedTasks ...)` loop and the inline `@php $status = match(...) @endphp` block; replace with five section blocks that hardcode the correct border colour per section.

**Contract**: The existing `sortedTasks->isEmpty()` check (line 234) is replaced by five sections. Each section:

```
<section>
    <h3 class="text-base font-semibold text-[colour]-700 mb-2">Section name</h3>
    @if($this->sectionProperty->isEmpty())
        <p class="text-sm text-gray-500">Empty state message.</p>
    @else
        <div class="space-y-3">
            @foreach($this->sectionProperty as $task)
                {{-- EDIT FORM when $editingTaskId === $task->id (exact current markup) --}}
                {{-- READ CARD with hardcoded border colour --}}
            @endforeach
        </div>
    @endif
</section>
```

Section colours (header text / card border / date text):

| Section | Header | Border | Date text |
|---------|--------|--------|-----------|
| Overdue | `text-red-700` | `border-red-200` | `text-red-600` |
| Due this week | `text-yellow-700` | `border-yellow-200` | `text-yellow-600` |
| This month | `text-blue-700` | `border-blue-200` | `text-blue-600` |
| Upcoming | `text-gray-700` | `border-gray-200` | `text-gray-500` |
| Manual tracking | `text-gray-700` | `border-gray-200` | — (no date) |

The edit form (`@if($editingTaskId === $task->id) ... @else ... @endif`) is reproduced verbatim inside each section's loop — no changes to its markup.

In the read card: add recurrence label using `Str::plural` for calendar sections. The metric section keeps the raw `{{ $task->interval_unit }}` format:

- Calendar sections: `Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}`
- Metric section: `Every {{ $task->interval_value }} {{ $task->interval_unit }}`

The "Mark done" button on the metric section card should be removed — metric tasks don't have a calendar due date to recalculate. The current flat list showed it; the dashboard does not show it for metric tasks.

---

#### 5. Add appliance section display tests

**File**: `tests/Feature/Appliances/ApplianceShowSectionsTest.php` (new file)

**Intent**: Verify each section receives the correct tasks and that sort-within-section works.

**Contract**: Extends `ApplianceTestCase`. Creates an `Appliance` with `household_id` in `setUp`. Test methods:

- Overdue task (next_due_at subDay) → red border visible, overdue section heading visible
- Due-this-week task (next_due_at addDays(3)) → yellow border visible
- This-month task (next_due_at addDays(15)) → "This month" heading visible, task name visible
- Upcoming task (next_due_at addDays(45)) → task name visible in upcoming section
- Metric task (interval_unit km, next_due_at null) → "Manual tracking" heading visible, gray border
- Two tasks in "This month" + `setSortBy('name')` → assertSeeInOrder([alphabetically first, second])

---

### Success Criteria

#### Automated Verification

- `php artisan test --filter=Appliances` — all appliance tests pass, including 6 new section tests; no regressions in `ApplianceShowDisplayTest`
- `composer phpstan` — no new errors

#### Manual Verification

- Appliance detail page renders with five labelled sections in correct urgency order
- Sort buttons re-order tasks within each section independently
- Inline edit form opens and saves within whichever section the task is in
- After "Mark done", task moves to its new section on re-render
- "Delete" flow works within sections; confirmation modal still appears
- Metric task ("Manual tracking" section) has no "Mark done" button
- Recurrence labels show correct pluralisation: "Every 1 month", "Every 6 months", "Every 14 days"

**Implementation Note**: Verify the above manually before marking this phase complete.

---

## Testing Strategy

### Unit Tests

None — no new utility classes introduced; all logic lives in the Volt component.

### Feature Tests

- `DashboardThisMonthBoundaryTest` — 4 tests covering both boundaries of the 30-day window
- `ApplianceShowSectionsTest` — 6 tests covering per-section task routing and sort-within-section

### Manual Testing Steps

1. Seed or create tasks spanning all five time windows (overdue, 3d, 15d, 45d, metric)
2. Visit `/dashboard` — confirm sections and recurrence labels
3. Visit an appliance detail — confirm sections, sort buttons, and recurrence labels
4. Mark a "this month" task done — confirm it moves to the correct new section
5. Edit a task's due date from "upcoming" to "this week" — confirm section reassignment on save
6. Verify `php artisan test` passes fully

---

## References

- Research: `context/changes/dashboard-this-month-section/research.md`
- Dashboard component: `resources/views/livewire/pages/dashboard.blade.php`
- Appliance show component: `resources/views/livewire/pages/appliances/show.blade.php`
- Existing boundary tests: `tests/Feature/Dashboard/DashboardBoundaryTest.php`
- Existing display tests: `tests/Feature/Appliances/ApplianceShowDisplayTest.php`
- Base test cases: `tests/Feature/Dashboard/DashboardTestCase.php`, `tests/Feature/Appliances/ApplianceTestCase.php`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: Dashboard

#### Automated

- [x] 1.1 `php artisan test --filter=Dashboard` — all tests pass including 4 new boundary tests — 9735386
- [x] 1.2 `composer phpstan` — no new errors — 9735386

#### Manual

- [ ] 1.3 "This month" section visible (blue) with correct tasks; "Upcoming" correct
- [ ] 1.4 Recurrence labels on all calendar card blocks
- [ ] 1.5 Empty-state messages render correctly

### Phase 2: Appliance detail

#### Automated

- [x] 2.1 `php artisan test --filter=Appliances` — all tests pass including 6 new section tests; no regressions in ApplianceShowDisplayTest — 09a152b
- [x] 2.2 `composer phpstan` — no new errors — 09a152b

#### Manual

- [ ] 2.3 Five sections render in correct order with section headings
- [ ] 2.4 Sort buttons re-order tasks within sections
- [ ] 2.5 Inline edit and delete work within sections; mark-done moves task to new section
- [ ] 2.6 Metric section has no "Mark done" button; recurrence labels correct

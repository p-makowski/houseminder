# Appliance Detail Page — Schedule Management Implementation Plan

## Overview

Transform `resources/views/livewire/pages/appliances/show.blade.php` from a 73-line read-only display into a fully interactive schedule management page. Four new capabilities land in the single Volt component: status-colored task cards matching the dashboard palette, a sortable task list, mark-done for all task types, inline task editing with recalculation, and task deletion with confirmation.

## Current State Analysis

`show.blade.php` loads tasks once in `mount()` via `$appliance->load(['maintenanceTasks'])` and renders them in a flat, un-sorted, un-colored list with no interactivity. The dashboard already has the full pattern we're replicating: `markDone()` + `RecordTaskCompletion`, red/yellow/gray color scheme keyed on `next_due_at`, and `#[Computed]` properties for reactive list management.

No standalone edit or delete actions exist for `MaintenanceTask` records. Task mutation in the create wizard happens in-memory on unconfirmed tasks — this plan introduces the first post-confirmation task mutation flow.

### Key Discoveries

- `app/Actions/RecordTaskCompletion.php` — `__invoke(MaintenanceTask $task, User $user)`: creates a `ServiceRecord`, updates `last_completed_at`, recalculates `next_due_at` for calendar units, leaves it unchanged for metric. Handles its own household ownership guard. Direct reuse.
- `app/Support/CalendarInterval.php:14` — `calculateNextDueAt(Carbon $anchor, string $unit, int $value)` throws `InvalidArgumentException` for non-calendar units. Must not be called for metric tasks (hours/km).
- `database/migrations/2026_06_01_000006_create_service_records_table.php:15` — `cascadeOnDelete()` on `maintenance_task_id` FK: deleting a `MaintenanceTask` auto-deletes its `ServiceRecord` rows at the DB level.
- `app/Models/MaintenanceTask.php:21` — all mutable fields including `name`, `description`, `interval_value`, `interval_unit`, `anchor_type`, `next_due_at` are in `#[Fillable]`.
- `tests/Feature/Appliances/ApplianceTestCase.php` — abstract base class with user + household + actingAs setup. New test classes in this namespace extend it; no new base class needed (no new namespace).
- Dashboard color contract (`dashboard.blade.php:90-152`): red = `border-red-200` / `text-red-600`; yellow = `border-yellow-200` / `text-yellow-600`; gray = `border-gray-200` / `text-gray-500`.

## Desired End State

The appliance detail page at `/appliances/{appliance}` displays the maintenance task list with:
- Status-colored borders and due-date text matching the dashboard exactly (overdue=red, ≤7 days=yellow, upcoming/metric=gray)
- A segmented sort control (Name / Due date / Frequency) above the list; default sort: due date ascending, metric tasks (no `next_due_at`) at the bottom
- A "Mark done" button on every task (calendar and metric) that records a service entry and refreshes the list
- Each task card shows an "Edit" link that expands the card into an inline form with all fields editable; saving a calendar task recalculates `next_due_at` from `last_completed_at`
- A "Delete" link that opens a confirmation modal warning that service history will be permanently deleted

Verify by navigating to an appliance with tasks across all statuses and exercising each action.

### Key Discoveries (repeated in plan context):

- `RecordTaskCompletion` is reusable as-is — no new action class needed for mark-done
- No existing pattern for `MaintenanceTask` edit/delete in actions layer; logic goes directly in the Volt component (consistent with how `edit.blade.php` handles appliance delete)
- Interval-unit switching must stay within the same category (calendar ↔ calendar, metric ↔ metric) — changing from a calendar unit to a metric unit (or vice versa) would flip which `next_due_at` field is authoritative (lessons.md rule) and is out of scope

## What We're NOT Doing

- No new route or Volt page for task editing — inline only
- No cross-category interval_unit switching (e.g., converting a "every 12 months" task to "every 500 km")
- No sorting persistence across page reloads — sort state lives in Livewire component memory only
- No bulk actions (mark all done, delete multiple)
- No service record history view on this page
- No changes to `RecordTaskCompletion`, `CalendarInterval`, or any model

## Implementation Approach

All changes land in the single Volt component at `show.blade.php`. The mount stays lean (just ownership guard + appliance load); task retrieval moves to a `#[Computed]` property so every mutation (mark-done, delete) automatically triggers a fresh sorted collection without manual reload calls.

Edit state is managed via a small set of public properties (`editingTaskId` + 6 form fields). Delete state uses a single `deletingTaskId` nullable int. Both patterns are consistent with how `edit.blade.php` handles its delete modal.

---

## Phase 1: Status Colors + Sort Controls

### Overview

Convert the flat static task list into a status-colored, sortable collection. No write operations introduced — this phase is pure read-path enhancement. It lays the reactive foundation (`#[Computed] sortedTasks`) that phases 2–4 will mutate against.

### Changes Required

#### 1. Volt component PHP block

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Add `$sortBy` public property and a `#[Computed] sortedTasks()` method. Move task fetching out of `mount()` into the computed property so subsequent mutations refresh the list automatically.

**Contract**:
- `public string $sortBy = 'due_date'` — valid values: `'name'`, `'due_date'`, `'interval'`
- `mount()` keeps the appliance load (`$appliance->load('applianceType')`) but drops the `maintenanceTasks` eager load; tasks come from the computed property hereafter
- `sortedTasks()` queries `$this->appliance->maintenanceTasks()` fresh from the DB, then applies in-PHP sort via `sortBy()` / `sortByDesc()`:
  - `'name'` → sort by `name` ascending
  - `'due_date'` → sort by `next_due_at` ascending, nulls last (metric tasks at bottom): `sortBy(fn($t) => $t->next_due_at?->timestamp ?? PHP_INT_MAX)`
  - `'interval'` → sort by interval normalized to days: days×1, weeks×7, months×30, years×365, hours/km → PHP_INT_MAX (metric tasks at bottom); formula: `$t->interval_value * $multiplier`
- Add `public function setSortBy(string $key): void` that validates the key is in the allowed set before assigning

#### 2. Blade template — sort controls

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Render a segmented button group above the task list so the user can change sort order.

**Contract**: Three buttons with `wire:click="setSortBy('name'|'due_date'|'interval')"`. Active button styled with `bg-indigo-600 text-white`; inactive with `bg-white text-gray-700 border border-gray-300`. Labels: "Name", "Due date", "Frequency".

#### 3. Blade template — status-colored task cards

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Apply per-card color classes based on task status, matching the dashboard color contract exactly.

**Contract**: In the `@foreach($this->sortedTasks as $task)` loop, derive `$status` from:
- `next_due_at !== null && next_due_at < now()` → `'overdue'` → `border-red-200`, `text-red-600` on the date
- `next_due_at !== null && next_due_at <= now()+7days` → `'due_soon'` → `border-yellow-200`, `text-yellow-600`
- `next_due_at !== null` → `'upcoming'` → `border-gray-200`, `text-gray-500`
- `next_due_at === null` (metric) → `'metric'` → `border-gray-200`, `text-gray-500`

Use a `@php $status = match(true) { ... } @endphp` block at the top of each loop iteration. Section heading colors are not needed (this page has a flat list, not grouped sections).

### Success Criteria

#### Automated Verification

- Type checking passes: `./vendor/bin/phpstan analyse`
- Existing tests still pass: `php artisan test --filter=ApplianceShowTest`

#### Manual Verification

- Sort buttons switch order visibly; "Frequency" sorts shortest interval first, metric tasks last
- Overdue task cards have red borders; due-within-7-days have yellow; others gray
- Colors match the dashboard for the same tasks

**Implementation Note**: The `ApplianceShowTest` filter is a smoke check only (boots + 403 gate) — PHPStan and manual verification are the primary quality gates for phases 1–4. Functional test coverage lands in Phase 5. After automated verification passes, confirm manually that sort and colors work correctly before proceeding.

---

## Phase 2: Mark Done

### Overview

Add `markDone(int $taskId)` to the Volt component, reusing `RecordTaskCompletion`. Works for both calendar tasks (recalculates `next_due_at`) and metric tasks (records service entry only). The `#[Computed] sortedTasks` from Phase 1 refreshes automatically after save.

### Changes Required

#### 1. `markDone` method

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Handle a "Mark done" click by finding the task, verifying it belongs to this appliance, and delegating to `RecordTaskCompletion`.

**Contract**:
- Signature: `public function markDone(int $taskId): void`
- Look up `MaintenanceTask::findOrFail($taskId)` — no household scope needed here because `RecordTaskCompletion` already performs the household ownership guard (`abort_if` on line 20 of the action)
- Verify `$task->appliance_id === $this->appliance->id` with `abort_if` (403) — prevents a task from a different appliance being marked done via crafted request
- Call `(new RecordTaskCompletion)($task, Auth::user())`
- No explicit reload needed — `#[Computed] sortedTasks` invalidates on next render

#### 2. Mark done button in template

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Add a "Mark done" button to every task card (calendar and metric alike).

**Contract**: `wire:click="markDone({{ $task->id }})"` with `wire:loading.attr="disabled"` and `disabled:opacity-50`. Style matches dashboard: `text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded`. Place in the top-right of the card alongside the (forthcoming) Edit and Delete controls.

### Success Criteria

#### Automated Verification

- Type checking passes: `./vendor/bin/phpstan analyse`
- Tests pass: `php artisan test --filter=ApplianceShowTest`

#### Manual Verification

- Clicking "Mark done" on a calendar task advances its due date by the task's interval
- Clicking "Mark done" on a metric task records a service entry (no date change)
- After marking done, the task card status color updates correctly (e.g., an overdue task turns gray if the new due date is far out)

---

## Phase 3: Task Delete with Confirmation

### Overview

Add task-level delete: a "Delete" link per card, a confirmation modal with service history warning, and the delete method. The DB cascade handles `ServiceRecord` cleanup automatically.

### Changes Required

#### 1. Delete state + methods

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Manage the delete confirmation flow via a nullable `deletingTaskId` property and three methods.

**Contract**:
- `public ?int $deletingTaskId = null`
- `public function confirmDelete(int $taskId): void` — sets `$this->deletingTaskId = $taskId` after verifying `appliance_id === $this->appliance->id` (abort_if 403)
- `public function deleteTask(): void` — fetches the task, verifies ownership again, calls `$task->delete()` (cascade handles service records), sets `$this->deletingTaskId = null`
- `public function cancelDelete(): void` — sets `$this->deletingTaskId = null`

#### 2. Delete button in template

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Add a "Delete" text link to each task card that triggers the confirmation modal.

**Contract**: `wire:click="confirmDelete({{ $task->id }})"` styled as `text-sm text-red-600 hover:text-red-800`.

#### 3. Confirmation modal

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Show a modal when `deletingTaskId` is set, warning the user that service history will be deleted.

**Contract**: Rendered when `$this->deletingTaskId !== null`. Modal copy: title "Delete task?", body "This will permanently delete **[task name]** and all its service history records. This cannot be undone." Two buttons: "Cancel" (`wire:click="cancelDelete"`) and "Delete" (`wire:click="deleteTask"`, red styling). Task name resolved by finding the task in `$this->sortedTasks` by id, or via a `#[Computed] deletingTask()` helper property.

### Success Criteria

#### Automated Verification

- Type checking passes: `./vendor/bin/phpstan analyse`
- Tests pass: `php artisan test --filter=ApplianceShowTest`

#### Manual Verification

- Delete link appears on each task card
- Clicking Delete shows the confirmation modal with the correct task name and history warning
- Confirming deletion removes the task from the list
- Cancelling leaves the task in place
- A task from a different appliance cannot be deleted via crafted `confirmDelete` call (403)

---

## Phase 4: Inline Task Edit

### Overview

Each task card gains an "Edit" link that expands the card into an inline editable form. Saving recalculates `next_due_at` for calendar tasks from `last_completed_at`. Interval unit options are restricted to the current task's category.

### Changes Required

#### 1. Edit state properties

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Declare public properties for the currently-editing task and its form fields.

**Contract**:
- `public ?int $editingTaskId = null`
- `public string $editName = ''`
- `public string $editDescription = ''`
- `public int $editIntervalValue = 1`
- `public string $editIntervalUnit = 'months'`
- `public string $editIntervalCategory = ''` — `'calendar'` or `'metric'`; populated in `startEdit()`; used only for validation, not persisted
- `public string $editAnchorType = 'from_last_done'`
- `public ?string $editNextDueAt = null` (date string for the date input; null = not overridden)

#### 2. `startEdit`, `saveEdit`, `cancelEdit` methods

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Populate edit fields from the task, validate and persist on save, and clear state on cancel.

**Contract**:

`startEdit(int $taskId)`:
- Verify `appliance_id === $this->appliance->id` (abort_if 403)
- Close any open delete confirmation: `$this->deletingTaskId = null`
- Populate all edit properties from the task
- Set `$this->editIntervalCategory` to `'calendar'` if `$task->interval_unit ∈ ['days','weeks','months','years']`, else `'metric'`
- Set `$this->editingTaskId = $taskId`

`saveEdit()`:
- Validate:
  - `editName`: required, string, max:255
  - `editDescription`: nullable, string, max:1000
  - `editIntervalValue`: required, integer, min:1
  - `editIntervalUnit`: required, string, `in:days,weeks,months,years` if `editIntervalCategory === 'calendar'`, else `in:hours,km` if `'metric'` — enforces same-category constraint at validation time before the task is fetched
  - `editAnchorType`: required for calendar tasks, in:from_last_done,fixed_calendar
  - `editNextDueAt`: nullable, date (only meaningful for calendar tasks)
- Fetch task, verify ownership (abort_if 403)
- Update all fields via `$task->fill([...])` using the validated values
- Recalculation logic for calendar tasks (interval_unit ∈ days/weeks/months/years):
  - If `editNextDueAt` is explicitly set by user → use it directly as `next_due_at`
  - Else → `$anchor = $task->last_completed_at ?? $task->anchor_date ?? now()`, then `next_due_at = CalendarInterval::calculateNextDueAt($anchor, $editIntervalUnit, $editIntervalValue)`
  - Guard: `abort_if` with 422 if somehow a metric unit reaches `calculateNextDueAt` (defensive, should never happen given validation)
- For metric tasks: do not modify `next_due_at` or `next_due_at_value`
- `$task->save()` inside `DB::transaction()`
- Set `$this->editingTaskId = null`

`cancelEdit()`:
- Set `$this->editingTaskId = null`

#### 3. Edit link + inline form in template

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Toggle each task card between read view and inline edit form based on `editingTaskId`.

**Contract**:
- Wrap each task card in `@if($editingTaskId === $task->id) ... @else ... @endif`
- Read view: existing card layout plus "Edit" (`wire:click="startEdit({{ $task->id }})"`) and "Delete" text links
- Edit form view: a form inside the card with:
  - Text input bound to `wire:model="editName"`
  - Textarea bound to `wire:model="editDescription"`
  - Number input bound to `wire:model.number="editIntervalValue"` (min 1)
  - Select for `editIntervalUnit` — options determined by the task's current category (calendar or metric); Blade `@if` on `in_array($task->interval_unit, ['days','weeks','months','years'])` to decide which option set to render
  - Select for `editAnchorType` (from_last_done / fixed_calendar) — hidden for metric tasks
  - Date input for `editNextDueAt` — only shown for calendar tasks; placeholder text: "Leave blank to auto-calculate"
  - "Save" button (`wire:click="saveEdit"`) and "Cancel" link (`wire:click="cancelEdit"`)

### Success Criteria

#### Automated Verification

- Type checking passes: `./vendor/bin/phpstan analyse`
- Tests pass: `php artisan test --filter=ApplianceShowTest`

#### Manual Verification

- Clicking "Edit" on a task expands that card into an inline form with pre-populated values; other tasks remain in read view
- Saving a calendar task with a changed interval updates `next_due_at` to `last_completed_at + new interval`
- Providing an explicit next_due_at date overrides recalculation
- Saving a metric task (hours/km) does not alter any date field
- Validation errors display inline (name required, interval_value ≥ 1)
- Cancel leaves the task unchanged
- A task from a different appliance cannot be edited (403 on startEdit)

---

## Phase 5: Feature Tests

### Overview

Add new test classes in `Tests\Feature\Appliances\` extending `ApplianceTestCase`. Cover the three new write paths (mark-done, edit, delete) plus the 403 ownership guard on each.

### Changes Required

#### 1. `ApplianceShowMarkDoneTest`

**File**: `tests/Feature/Appliances/ApplianceShowMarkDoneTest.php`

**Intent**: Verify mark-done creates a service record, recalculates the due date for calendar tasks, and rejects cross-household calls.

**Contract**: Tests:
- Calendar task: `markDone` → `ServiceRecord` exists for the task + `next_due_at` updated to `last_completed_at + interval`
- Metric task: `markDone` → `ServiceRecord` exists + `next_due_at` is unchanged
- Task from different appliance (different household): `markDone` → 403

#### 2. `ApplianceShowTaskEditTest`

**File**: `tests/Feature/Appliances/ApplianceShowTaskEditTest.php`

**Intent**: Verify task edit persists all fields and recalculates next_due_at correctly for calendar tasks.

**Contract**: Tests:
- `saveEdit` with changed interval on a calendar task → `next_due_at` = `last_completed_at + new interval` (freeze time for determinism)
- `saveEdit` with explicit `editNextDueAt` → `next_due_at` matches the provided date
- `saveEdit` on a metric task → `next_due_at` unchanged
- `startEdit` on a task from a different household → 403

#### 3. `ApplianceShowTaskDeleteTest`

**File**: `tests/Feature/Appliances/ApplianceShowTaskDeleteTest.php`

**Intent**: Verify task deletion removes the task and its service records.

**Contract**: Tests:
- `deleteTask` after `confirmDelete` → task row gone from DB, associated `ServiceRecord` rows also gone (cascade)
- `confirmDelete` on a task from a different household → 403

### Success Criteria

#### Automated Verification

- All new tests pass: `php artisan test --filter="ApplianceShowMarkDoneTest|ApplianceShowTaskEditTest|ApplianceShowTaskDeleteTest"`
- Full test suite still green: `php artisan test`
- Type checking passes: `./vendor/bin/phpstan analyse`

#### Manual Verification

- All interactive flows work end-to-end on the running app with no console errors
- No regressions on dashboard (mark-done still works there)
- No regressions on appliance list or edit pages

---

## Testing Strategy

### Unit Tests

Not applicable — no new standalone classes introduced.

### Feature Tests (covered in Phase 5)

- Mark done: calendar recalc, metric no-recalc, 403
- Task edit: interval recalc, explicit date override, metric no-recalc, 403
- Task delete: cascade, 403

### Manual Testing Steps

1. Add an appliance with tasks in overdue, due-soon, and upcoming status; verify three distinct card colors
2. Sort by Name, Due date, Frequency — verify order changes correctly
3. Mark a calendar task done — verify the due date advances and the card color updates
4. Edit a task: change name, interval, description; verify save persists all fields
5. Edit a calendar task: change interval, leave next_due_at blank — verify auto-recalculation
6. Edit a calendar task: provide explicit next_due_at — verify that date is used (no recalculation)
7. Click Delete — verify the modal shows the task name and history warning; confirm — task disappears
8. Verify dashboard still works (mark done from there) with no regressions

## References

- Dashboard pattern: `resources/views/livewire/pages/dashboard.blade.php`
- RecordTaskCompletion: `app/Actions/RecordTaskCompletion.php`
- CalendarInterval: `app/Support/CalendarInterval.php:14`
- Lessons: `context/foundation/lessons.md` — action `__invoke` pattern, interval_unit field authority, guard pattern

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Status Colors + Sort Controls

#### Automated

- [ ] 1.1 Type checking passes: `./vendor/bin/phpstan analyse`
- [ ] 1.2 Existing tests still pass: `php artisan test --filter=ApplianceShowTest`

#### Manual

- [ ] 1.3 Sort buttons switch order visibly; Frequency sorts shortest interval first, metric tasks last
- [ ] 1.4 Overdue task cards have red borders; due-within-7-days yellow; others gray
- [ ] 1.5 Colors match dashboard for same tasks

### Phase 2: Mark Done

#### Automated

- [ ] 2.1 Type checking passes: `./vendor/bin/phpstan analyse`
- [ ] 2.2 Tests pass: `php artisan test --filter=ApplianceShowTest`

#### Manual

- [ ] 2.3 Clicking Mark done on a calendar task advances its due date by the interval
- [ ] 2.4 Clicking Mark done on a metric task records a service entry (no date change)
- [ ] 2.5 After marking done, task card status color updates correctly

### Phase 3: Task Delete with Confirmation

#### Automated

- [ ] 3.1 Type checking passes: `./vendor/bin/phpstan analyse`
- [ ] 3.2 Tests pass: `php artisan test --filter=ApplianceShowTest`

#### Manual

- [ ] 3.3 Delete link appears on each task card
- [ ] 3.4 Clicking Delete shows confirmation modal with correct task name and history warning
- [ ] 3.5 Confirming deletion removes the task from the list
- [ ] 3.6 Cancelling leaves the task in place
- [ ] 3.7 A task from a different appliance cannot be deleted via crafted confirmDelete call (403)

### Phase 4: Inline Task Edit

#### Automated

- [ ] 4.1 Type checking passes: `./vendor/bin/phpstan analyse`
- [ ] 4.2 Tests pass: `php artisan test --filter=ApplianceShowTest`

#### Manual

- [ ] 4.3 Clicking Edit expands that card into an inline form with pre-populated values
- [ ] 4.4 Saving calendar task with changed interval updates next_due_at to last_completed_at + new interval
- [ ] 4.5 Providing explicit next_due_at overrides recalculation
- [ ] 4.6 Saving metric task does not alter any date field
- [ ] 4.7 Validation errors display inline
- [ ] 4.8 Cancel leaves the task unchanged
- [ ] 4.9 A task from a different appliance cannot be edited (403 on startEdit)

### Phase 5: Feature Tests

#### Automated

- [ ] 5.1 New tests pass: `php artisan test --filter="ApplianceShowMarkDoneTest|ApplianceShowTaskEditTest|ApplianceShowTaskDeleteTest"`
- [ ] 5.2 Full test suite green: `php artisan test`
- [ ] 5.3 Type checking passes: `./vendor/bin/phpstan analyse`

#### Manual

- [ ] 5.4 All interactive flows work end-to-end with no console errors
- [ ] 5.5 No regressions on dashboard, appliance list, or edit pages

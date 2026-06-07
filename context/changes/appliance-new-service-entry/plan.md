# Add New Maintenance Task from Appliance Page — Implementation Plan

## Overview

Add an "+ Add task" button to the appliance show page so users can create new maintenance tasks directly without going through the creation wizard. Clicking the button reveals an inline form box — combining the task definition fields from wizard Step 2 and the "last service" backdate fields from wizard Step 3 — all in one step. No modal, no new routes, no new Action classes.

## Current State Analysis

`resources/views/livewire/pages/appliances/show.blade.php` is a single-file Volt component with full task management (list / edit / delete / mark-done) but no task creation path. Tasks are currently only created during the 4-step appliance wizard.

The page already uses inline Livewire state for edit and delete flows (Pattern B: a `bool`/nullable property controls visibility, no modal component). The same pattern applies here — `$addingTask` controls whether the form box is visible.

### Key Discoveries:

- `show.blade.php:21-39` — existing public properties (edit + delete state); new `add*` properties follow the same pattern
- `_edit-form.blade.php` — template for the task-definition section of the add form (same fields, bound to `add*` properties)
- `create.blade.php:198-237` — canonical creation logic: sets `last_completed_at`, calculates `next_due_at` from backdate when provided, conditionally creates `ServiceRecord` with `completed_at`, `metric_reading`, `notes`
- `CalendarInterval::calculateNextDueAt()` throws on non-calendar units (lessons.md guard rule applies)
- "Notes" from the backdate step belongs to `ServiceRecord.notes` (max 2000), not `MaintenanceTask`

## Desired End State

A clearly-labelled "+ Add task" button sits next to the "Maintenance Plan" heading. Clicking it reveals an inline form box directly below the heading controls. The box has two sections: task definition (name, description, interval) and "Last service" (optional backdate date, optional metric reading for metric tasks, optional notes). On save, the new task persists and a `ServiceRecord` is created if backdate data was provided. The form collapses and the new task appears in the correct section.

## What We're NOT Doing

- Using `x-modal` or any modal overlay — inline box only (Pattern B, consistent with existing edit/delete flows)
- Exposing `anchor_type` to the user — silently defaults to `from_last_done`
- Adding a dedicated empty-state prompt for appliances with zero tasks — the heading button serves discovery
- Extracting a dedicated Action class for task creation — logic is ~25 lines used from one place
- Adding a "skip" checkbox — all last-service fields are optional by default (blank = skip)
- Setting `last_metric_value` on the task from the metric reading — matches wizard behaviour (metric reading goes to `ServiceRecord.metric_reading` only)

## Implementation Approach

Clicking "+ Add task" calls `startAddTask()`, which resets all `add*` properties and sets `$addingTask = true`. An `@if($addingTask)` block immediately below the heading controls renders the `_add-form.blade.php` partial. `saveNewTask()` validates, branches on `addIntervalCategory`, creates the task (setting `last_completed_at` and calculating `next_due_at` from `addLastDoneAt` when provided), then conditionally creates a `ServiceRecord`, resets properties, and sets `$addingTask = false`. `cancelAddTask()` sets `$addingTask = false`.

## Critical Implementation Details

**`interval_unit` branching guard**: Per lessons.md, `CalendarInterval::calculateNextDueAt()` throws on metric units. `saveNewTask()` must branch on `addIntervalCategory` *before* calling the helper — do not rely solely on validation. Calendar branch: if `addLastDoneAt` is set, use it as the anchor; otherwise use `now()`. Set `next_due_at_value = null`. Metric branch: set `next_due_at = null`, `next_due_at_value = null`.

**`is_confirmed` must be `true`**: `scopeCalendar()` / `scopeMetric()` both filter `is_confirmed = true`. A task saved without it is invisible on the show page.

**`ServiceRecord` creation condition**: Mirrors the wizard exactly — create only when `anchor_type === 'from_last_done'` AND (`addLastDoneAt` is set OR `addLastMetric` is set). `completed_at` = `addLastDoneAt` if provided, else `Carbon::today()`. This is the same guard used in `create.blade.php:230`.

**`last_completed_at` on the task**: Set to `addLastDoneAt` only when `anchor_type === 'from_last_done'` AND `addLastDoneAt` is provided. Mirrors `create.blade.php:222`.

---

## Phase 1: Livewire PHP block — new state and `saveNewTask()` method

### Overview

Add 10 public properties and 3 methods (`startAddTask`, `saveNewTask`, `cancelAddTask`) to the PHP block of `show.blade.php`. This is all server-side logic for the feature.

### Changes Required:

#### 1. New public properties

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Declare the add-form state after the existing `editNextDueAt` property (line 39). Ten properties: one bool for form visibility, plus nine form-field properties.

**Contract**: `public bool $addingTask = false`, `public string $addName = ''`, `public string $addDescription = ''`, `public int $addIntervalValue = 1`, `public string $addIntervalUnit = 'months'`, `public string $addIntervalCategory = 'calendar'`, `public ?string $addNextDueAt = null`, `public ?string $addLastDoneAt = null`, `public ?string $addLastMetric = null`, `public string $addNotes = ''`.

#### 2. `startAddTask()` method

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Reset all `add*` form properties to their defaults (so reopening after an abandoned draft shows a blank form), collapse any open edit form, then reveal the add form. Place after `cancelEdit()`.

**Contract**: Sets each `add*` form property back to its default. Sets `$this->editingTaskId = null` (same as existing edit methods do for `$this->deletingTaskId`). Sets `$this->addingTask = true`.

#### 3. `cancelAddTask()` method

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Collapse the add form without saving. Place after `startAddTask()`.

**Contract**: Sets `$this->addingTask = false`. No property reset needed — `startAddTask()` resets on next open.

#### 4. `saveNewTask()` method

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Validate the add-form input, create the task with correct backdate handling, conditionally create a `ServiceRecord`, reset form state, and collapse the form.

**Contract**: The branching pattern is load-bearing — calendar and metric paths must diverge before any call to `CalendarInterval`, and backdate/ServiceRecord logic follows the wizard's `confirm()` exactly:

```php
public function saveNewTask(): void
{
    foreach (['addNextDueAt', 'addLastDoneAt'] as $field) {
        if ($this->$field === '') {
            $this->$field = null;
        }
    }

    $allowedUnits = $this->addIntervalCategory === 'calendar'
        ? ['days', 'weeks', 'months', 'years']
        : ['hours', 'km'];

    $validated = $this->validate([
        'addName'          => ['required', 'string', 'max:255'],
        'addDescription'   => ['nullable', 'string', 'max:1000'],
        'addIntervalValue' => ['required', 'integer', 'min:1'],
        'addIntervalUnit'  => ['required', 'string', Rule::in($allowedUnits)],
        'addNextDueAt'     => ['nullable', 'date'],
        'addLastDoneAt'    => ['nullable', 'date'],
        'addLastMetric'    => ['nullable', 'numeric'],
        'addNotes'         => ['nullable', 'string', 'max:2000'],
    ]);

    $hasDate   = ! empty($validated['addLastDoneAt']);
    $hasMetric = ! empty($validated['addLastMetric']);
    $anchor    = $hasDate ? Carbon::parse($validated['addLastDoneAt']) : now();

    $data = [
        'name'              => $validated['addName'],
        'description'       => $validated['addDescription'] ?: null,
        'interval_value'    => $validated['addIntervalValue'],
        'interval_unit'     => $validated['addIntervalUnit'],
        'anchor_type'       => 'from_last_done',
        'last_completed_at' => $hasDate ? $anchor : null,
        'is_confirmed'      => true,
    ];

    if ($this->addIntervalCategory === 'calendar') {
        $data['next_due_at'] = ! empty($validated['addNextDueAt'])
            ? Carbon::parse($validated['addNextDueAt'])
            : CalendarInterval::calculateNextDueAt($anchor, $validated['addIntervalUnit'], (int) $validated['addIntervalValue']);
        $data['next_due_at_value'] = null;
    } else {
        $data['next_due_at']       = null;
        $data['next_due_at_value'] = null;
    }

    DB::transaction(function () use ($data, $hasDate, $hasMetric, $anchor, $validated) {
        $task = $this->appliance->maintenanceTasks()->create($data);

        if ($hasDate || $hasMetric) {
            ServiceRecord::create([
                'maintenance_task_id' => $task->id,
                'completed_at'        => $hasDate ? $anchor : Carbon::today(),
                'metric_reading'      => $hasMetric ? $validated['addLastMetric'] : null,
                'notes'               => ! empty($validated['addNotes']) ? $validated['addNotes'] : null,
            ]);
        }
    });

    $this->addName            = '';
    $this->addDescription     = '';
    $this->addIntervalValue   = 1;
    $this->addIntervalUnit    = 'months';
    $this->addIntervalCategory = 'calendar';
    $this->addNextDueAt       = null;
    $this->addLastDoneAt      = null;
    $this->addLastMetric      = null;
    $this->addNotes           = '';
    $this->addingTask         = false;
}
```

Also add `use App\Models\ServiceRecord;` to the imports at the top of the file if not already present.

### Success Criteria:

#### Automated Verification:

- PHPStan passes: `composer phpstan`
- Full test suite passes: `php artisan test`

#### Manual Verification:

- Saving a calendar task with no backdate creates a `maintenance_tasks` DB record with `is_confirmed = true`, `next_due_at` calculated from `now()`, no `ServiceRecord`
- Saving a calendar task with a backdate creates a record with `last_completed_at` set and `next_due_at` calculated from that date, plus a `ServiceRecord`
- Saving a metric task with a metric reading creates a `ServiceRecord` with `metric_reading` populated

**Implementation Note**: After all automated verification passes, pause here for manual confirmation before proceeding to Phase 2.

---

## Phase 2: `_add-form.blade.php` partial

### Overview

Create the add-form partial. Two sections in one box: task definition (mirrors `_edit-form.blade.php` plus interval-category radio) and "Last service" (optional backdate fields from wizard Step 3).

### Changes Required:

#### 1. New partial file

**File**: `resources/views/livewire/pages/appliances/_add-form.blade.php` (new file)

**Intent**: Render the complete add-task form in a single bordered box. The first section defines the task; the second section optionally captures last-service history. Visually separated by a heading, but submitted together as one form.

**Contract**: Structure:

**Section 1 — Task definition** (same styling as `_edit-form.blade.php`, indigo border):
1. **Name** — text input, `wire:model="addName"`, `@error('addName')` display
2. **Description** — textarea, `wire:model="addDescription"`
3. **Interval category** — radio group: "Calendar" (`value="calendar"`) and "Metric" (`value="metric"`), `wire:model="addIntervalCategory"`, `wire:model.live` so category switch re-renders unit options instantly
4. **Every / Unit row** — `wire:model.number="addIntervalValue"` + `wire:model="addIntervalUnit"` select; unit options filtered by `$addIntervalCategory` (same conditional as `_edit-form.blade.php`)
5. **Next due date** — shown only when `$addIntervalCategory === 'calendar'`; `wire:model="addNextDueAt"`; optional, "leave blank to auto-calculate" hint

**Section 2 — Last service (optional)**, preceded by a small heading "Last service (optional)" in gray:
6. **When did you last do this?** — date input, `wire:model="addLastDoneAt"`; label text "When did you last do this?"
7. **Metric reading** — shown only when `$addIntervalCategory === 'metric'`; `wire:model="addLastMetric"`, type text (matches wizard)
8. **Notes** — textarea rows=2, `wire:model="addNotes"`, label "Notes (optional)"

**Buttons**:
9. **Save** — `wire:click="saveNewTask"`, indigo primary style
10. **Cancel** — `wire:click="cancelAddTask"`, secondary style (same as `_edit-form.blade.php` cancel)

Error display for `addName`, `addIntervalValue`, `addIntervalUnit`, `addNextDueAt`, `addLastDoneAt`, `addLastMetric` using `@error('fieldName')` pattern.

### Success Criteria:

#### Automated Verification:

- `php artisan view:cache` completes without errors (confirms Blade syntax is valid)

#### Manual Verification:

- Switching category radio from "Calendar" to "Metric" swaps unit options and hides the next-due date field, shows metric reading field
- Switching back to "Calendar" hides metric reading, shows next-due date
- Cancel button collapses the form (no server data written)

**Implementation Note**: After manual checks pass, proceed to Phase 3.

---

## Phase 3: Button and inline form in `show.blade.php` template

### Overview

Add the "+ Add task" button to the heading row and render the add-form partial inline (no modal). Two Blade changes; no PHP logic changes.

### Changes Required:

#### 1. "+ Add task" / "Cancel" button in the heading row

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Place the button inside the existing flex row (line 276: `<div class="flex items-center justify-between mb-3">`) between the `<h2>` heading and the sort control group. When `$addingTask` is false show "+ Add task"; when true, show "Cancel" (or omit the button entirely — the cancel inside the form serves the same purpose). Simplest: always show "+ Add task", let `startAddTask()` reset and re-open.

**Contract**: `<button wire:click="startAddTask">+ Add task</button>` styled as an indigo text-link: `inline-flex items-center text-sm text-indigo-600 hover:text-indigo-800`. No conditional needed on the button itself.

#### 2. Inline add-form block

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Render the add-form partial immediately below the heading controls row, above the first task section (`<section>` for "Overdue"). It is visible only when `$addingTask` is true.

**Contract**: `@if($addingTask) @include('livewire.pages.appliances._add-form') @endif` inserted as the first child of the `<div class="space-y-6">` container (line 295), before the "Overdue" section.

### Success Criteria:

#### Automated Verification:

- `php artisan test` passes — no regressions in existing show-page tests

#### Manual Verification:

- Clicking "+ Add task" reveals the form box above the task sections
- Clicking "Cancel" (or clicking "+ Add task" again) collapses the form
- Submitting a valid calendar task (no backdate): form collapses, task appears in correct section, no `ServiceRecord` in DB
- Submitting a calendar task with backdate + notes: task `last_completed_at` set, `ServiceRecord` created with `notes`
- Submitting a metric task with metric reading: task in "Manual tracking", `ServiceRecord` has `metric_reading`
- Validation errors display inline; form stays open

**Implementation Note**: Full manual walkthrough required before writing tests.

---

## Phase 4: Feature tests — `ApplianceShowTaskCreateTest`

### Overview

New test class `tests/Feature/Appliances/ApplianceShowTaskCreateTest.php` extending `ApplianceTestCase`. Covers happy paths (calendar without backdate, calendar with backdate, metric with metric reading), validation rejection, authorization guard, and form state reset.

### Changes Required:

#### 1. `ApplianceShowTaskCreateTest.php`

**File**: `tests/Feature/Appliances/ApplianceShowTaskCreateTest.php` (new file)

**Intent**: Verify the task creation flow end-to-end using Livewire's testing helpers. Each test is fully self-contained (its own appliance fixture) per the `ApplianceTestCase` convention.

**Contract**: Five test methods:

1. `test_can_create_calendar_task_without_backdate()` — valid calendar data, no `addLastDoneAt`; asserts task in DB with `is_confirmed = true`, `next_due_at` not null, `last_completed_at` null; asserts zero `ServiceRecord` rows; asserts `addingTask` property is `false` and `addName` is `''` on the component after save

2. `test_can_create_calendar_task_with_backdate_and_notes()` — valid calendar data + `addLastDoneAt` + `addNotes`; asserts `last_completed_at` equals backdate, `next_due_at` calculated from backdate (not today); asserts one `ServiceRecord` with matching `notes`

3. `test_can_create_metric_task_with_metric_reading()` — `addIntervalCategory = 'metric'`, `addIntervalUnit = 'hours'`, `addLastMetric = '5000'`; asserts task `next_due_at = null`; asserts `ServiceRecord` with `metric_reading = 5000`

4. `test_validation_rejects_invalid_data()` — empty `addName`, `addIntervalValue = 0`; asserts validation errors on both fields, zero tasks and zero `ServiceRecord` rows created

5. `test_unauthorized_user_cannot_create_task()` — second user with no household membership calls `saveNewTask`; asserts 403

### Success Criteria:

#### Automated Verification:

- `php artisan test --filter=ApplianceShowTaskCreateTest` — all 5 tests pass
- `php artisan test` — full suite green
- `composer phpstan` — no new errors

#### Manual Verification:

- No regressions in other show-page test classes (edit, delete, markDone, sections, display)

---

## Testing Strategy

### Unit Tests:

- None — all logic lives in the Livewire component and is covered by feature tests

### Integration Tests:

- `ApplianceShowTaskCreateTest` (5 methods) covers DB writes, `ServiceRecord` creation, Livewire state, and authorization

### Manual Testing Steps:

1. Navigate to an existing appliance — "+ Add task" appears next to "Maintenance Plan" heading
2. Click button — form box appears above task sections
3. Fill calendar task: name "Filter check", every 3 months, leave all optional fields blank → Save → task in "Upcoming" or "This month", no ServiceRecord
4. Click "+ Add task" again — form is blank (state was reset)
5. Fill calendar task with backdate: add date 6 months ago, notes "Replaced filter" → Save → `last_completed_at` set, `ServiceRecord` with notes in DB
6. Fill metric task: switch to Metric, every 5000 km, metric reading 42000 → Save → task in "Manual tracking", `ServiceRecord` with `metric_reading`
7. Open form, submit with empty name and interval = 0 — errors display, form stays open
8. Click Cancel — form collapses, no DB changes

## Performance Considerations

None — no new queries. `ServiceRecord` creation runs inside the same `DB::transaction` as the task; the five `#[Computed]` properties on the page are unaffected.

## Migration Notes

No schema changes. `ServiceRecord` and all `MaintenanceTask` fields used (`last_completed_at`, `next_due_at`, `next_due_at_value`, `anchor_type`, `is_confirmed`) exist in current migrations.

## References

- Research: `context/changes/appliance-new-service-entry/research.md`
- Canonical creation + ServiceRecord logic: `resources/views/livewire/pages/appliances/create.blade.php:198-237`
- Edit form partial (template for task section): `resources/views/livewire/pages/appliances/_edit-form.blade.php`
- Wizard backdate step (template for last-service section): `create.blade.php:439-503` (Step 3 Blade)
- Test base class: `tests/Feature/Appliances/ApplianceTestCase.php`
- `CalendarInterval::calculateNextDueAt()`: `app/Support/CalendarInterval.php`
- `ServiceRecord` model: `app/Models/ServiceRecord.php`
- Lessons: `context/foundation/lessons.md` — interval_unit branching, is_confirmed, base test case rules

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Livewire PHP block

#### Automated

- [x] 1.1 PHPStan passes: `composer phpstan` — ebd3fe4
- [x] 1.2 Full test suite passes: `php artisan test` — ebd3fe4

#### Manual

- [ ] 1.3 Calendar task (no backdate): `next_due_at` from `now()`, no `ServiceRecord`
- [ ] 1.4 Calendar task (with backdate): `last_completed_at` set, `next_due_at` from backdate, `ServiceRecord` created
- [ ] 1.5 Metric task with metric reading: `ServiceRecord` with `metric_reading` created

### Phase 2: _add-form.blade.php partial

#### Automated

- [x] 2.1 `php artisan view:cache` completes without errors — 27d0a23

#### Manual

- [ ] 2.2 Category radio switches unit options (calendar ↔ metric)
- [ ] 2.3 Next-due date field appears/disappears on category toggle
- [ ] 2.4 Metric reading field appears only for metric category
- [ ] 2.5 Cancel button collapses form without saving

### Phase 3: Button and inline form wiring

#### Automated

- [x] 3.1 `php artisan test` passes (no regressions)

#### Manual

- [ ] 3.2 "+ Add task" reveals form box above task sections
- [ ] 3.3 Cancel collapses form, no DB changes
- [ ] 3.4 Valid calendar task (no backdate): form collapses, task in correct section, no ServiceRecord
- [ ] 3.5 Calendar task with backdate + notes: ServiceRecord created with notes
- [ ] 3.6 Metric task with metric reading: task in "Manual tracking", ServiceRecord with metric_reading
- [ ] 3.7 Validation errors display inline, form stays open

### Phase 4: ApplianceShowTaskCreateTest

#### Automated

- [ ] 4.1 `php artisan test --filter=ApplianceShowTaskCreateTest` — all 5 pass
- [ ] 4.2 `php artisan test` — full suite green
- [ ] 4.3 `composer phpstan` — no new errors

#### Manual

- [ ] 4.4 No regressions in other show-page test classes

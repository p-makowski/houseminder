---
date: 2026-06-07T16:26:33Z
researcher: p-makowski
git_commit: eed45f1c4f2add3ab6c2adb8b90b1664255eee77
branch: main
repository: houseminder
topic: "Add button on appliance page to create a new maintenance task"
tags: [research, codebase, appliance, maintenance-task, livewire, volt]
status: complete
last_updated: 2026-06-07
last_updated_by: p-makowski
---

# Research: Add button on appliance page to create a new maintenance task

**Date**: 2026-06-07T16:26:33Z
**Researcher**: p-makowski
**Git Commit**: eed45f1c4f2add3ab6c2adb8b90b1664255eee77
**Branch**: main
**Repository**: houseminder

## Research Question

Currently there is no way to add a new maintenance task for a given appliance.
Add a button on the appliance page for that.

## Summary

The appliance detail page (`show.blade.php`) is an inline Volt component with full task
management (list/edit/delete/mark-done) but no way to create a new task. Tasks are
currently only created through the 4-step appliance creation wizard. The page already uses
a Livewire-property-driven inline overlay for the delete confirmation modal — that same
pattern is the right fit for an "add task" form. The `_edit-form.blade.php` partial can
be mirrored directly to produce an "add task" form partial, reusing the exact same fields
and validation rules. No new routes, models, or actions are needed — just new Livewire
state and a `saveNewTask()` method that calls `$appliance->maintenanceTasks()->create()`
and respects the `interval_unit` branching rule (lessons.md).

## Detailed Findings

### Appliance show page — current state

- **Route**: `routes/web.php:34` — `Volt::route('appliances/{appliance}', 'pages.appliances.show')`
  name `appliances.show`, middleware `auth` + `verified`
- **Component file**: `resources/views/livewire/pages/appliances/show.blade.php` (single
  Volt file; PHP and Blade co-located)
- **Existing Livewire state** (lines 21-27):
  - `public Appliance $appliance` — model-bound
  - `public string $sortBy = 'due_date'`
  - `public ?int $deletingTaskId = null` — controls delete modal
  - `public ?int $editingTaskId = null` + 6 edit-field properties — controls inline edit
- **Computed properties** (lines 69-130): `overdue()`, `dueThisWeek()`, `dueThisMonth()`,
  `upcoming()`, `metric()` — five categorized collections
- **Existing actions**: `markDone()`, `startEdit()` / `saveEdit()` / `cancelEdit()`,
  `confirmDelete()` / `deleteTask()` / `cancelDelete()`
- **Missing**: No `addingTask` state, no creation method, no "Add task" button anywhere

### MaintenanceTask model and schema

- **Model**: `app/Models/MaintenanceTask.php`
- **Fillable**: `appliance_id`, `name`, `description`, `interval_value`, `interval_unit`,
  `anchor_type`, `anchor_date`, `last_completed_at`, `last_metric_value`, `next_due_at`,
  `next_due_at_value`, `is_confirmed`
- **Casts**: `anchor_date` → date, `last_completed_at` → datetime, `next_due_at` → datetime,
  `last_metric_value` / `next_due_at_value` → float, `is_confirmed` → boolean
- **Relationships**: `belongsTo(Appliance::class)`, `hasMany(ServiceRecord::class)`
- **Scopes**: `scopeCalendar()` (days/weeks/months/years + confirmed),
  `scopeMetric()` (hours/km + confirmed), `scopeForHousehold()`

**Critical interval-unit rule** (lessons.md): `interval_unit` determines which due-date
field is authoritative. Calendar units → `next_due_at` (datetime). Metric units (hours/km)
→ `next_due_at_value` (float). Both fields cannot be set at the same time.

- **Migration**: `database/migrations/2026_06_01_000005_create_maintenance_tasks_table.php`
  — `interval_unit` is enum `days|weeks|months|years|hours|km`
- **Description added**: `database/migrations/2026_06_04_090811_add_description_to_maintenance_tasks.php`
- **Appliance → tasks**: `app/Models/Appliance.php:31` — `hasMany(MaintenanceTask::class)`

### Existing task creation — wizard path

All current creation happens in
`resources/views/livewire/pages/appliances/create.blade.php`:

- **`confirm()` method** (lines 159-244): the only place tasks are persisted today
- **Creation call** (line ~215): `$appliance->maintenanceTasks()->create([...])`
- **Always sets `is_confirmed: true`** (wizard-confirmed tasks are live immediately)
- **`next_due_at` calculation**: `CalendarInterval::calculateNextDueAt()` for calendar
  tasks; `next_due_at_value` left null for new calendar tasks, metric value from backdate
  step for metric tasks
- **Validation rules** (lines 163-173) — the canonical template for any new creation path:
  ```
  name           required string max:255
  interval_value required integer min:1
  interval_unit  required in:days,weeks,months,years,hours,km   (wizard is calendar-only;
                          metric units also exist in the model and show page edit)
  anchor_type    required in:from_last_done,fixed_calendar
  description    nullable string max:1000
  ```

### Modal and form patterns

**Two patterns exist in the codebase:**

**Pattern A — `x-modal` component** (Alpine event-driven)
- File: `resources/views/components/modal.blade.php`
- Trigger: any element dispatches `$dispatch('open-modal', 'name')` via Alpine
- Close: dispatches `$dispatch('close')`, ESC key, backdrop click
- Features: focus trap, body scroll-lock, `maxWidth` prop, backdrop
- Used in: `livewire/pages/appliances/edit.blade.php:107`, `livewire/profile/delete-user-form.blade.php`

**Pattern B — Livewire-property inline overlay** (used in `show.blade.php` already)
- No component — raw `<div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">`
- Controlled by a nullable Livewire property (`deletingTaskId`): `@if($this->deletingTask)`
- No ESC, no focus trap
- Used for the delete confirmation in `show.blade.php:455-475`

**Pattern B is already in use in the target file.** Using it for the add form keeps the
file internally consistent. If a11y (ESC, focus trap) is a priority, Pattern A is
preferable, but Pattern B is the lower-friction choice given the existing delete modal.

### Edit form partial — template for add form

`resources/views/livewire/pages/appliances/_edit-form.blade.php`

Fields:
- `name` — text input, `wire:model="editName"`
- `description` — textarea, `wire:model="editDescription"`
- `interval_value` + `interval_unit` — side-by-side inputs, `wire:model.number` / `wire:model`
- `editIntervalCategory` — drives whether calendar or metric units are shown
- `next_due_at` — date input (calendar tasks only, optional with auto-calculate note)
- Save / Cancel buttons

A nearly identical `_add-form.blade.php` partial can be derived from this. The only
difference is the form binds to new `addTask*` properties instead of `edit*` properties.

### Dashboard task card pattern (service-cards-styling)

`resources/views/livewire/pages/dashboard.blade.php` — already uses `<x-maintenance-task-card>`
with the `$actions` slot for "Mark done" buttons. The show page uses the same component.

The card component (`resources/views/components/maintenance-task-card.blade.php`) accepts:
`task`, `color` (red/yellow/blue/gray), `showDraftBadge`, `showDescription`, `showApplianceName`,
and an `$actions` named slot.

### Button styling conventions

| Context | Classes |
|---|---|
| Primary action (Mark done / Save) | `text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded` |
| "Add" text-link (from wizard) | `inline-flex items-center text-sm text-indigo-600 hover:text-indigo-800` |
| Cancel / secondary | `text-sm text-gray-700 border border-gray-300 px-4 py-2 rounded hover:bg-gray-50` |
| Edit link | `text-sm text-indigo-600 hover:text-indigo-800` |
| Delete link | `text-sm text-red-600 hover:text-red-800` |
| `x-primary-button` | `bg-gray-800 text-white uppercase tracking-widest font-semibold text-xs px-4 py-2 rounded-md` |

**No FAB (floating action button) exists** anywhere in the app. The add button should be
a standard inline action, likely alongside the "Maintenance Plan" section heading.

## Code References

- `routes/web.php:34` — Appliance show route
- `resources/views/livewire/pages/appliances/show.blade.php` — Full show page (Volt component)
  - Lines 21-27: public properties
  - Lines 50-130: sortTasks() + 5 Computed properties
  - Lines 140-245: confirmDelete / deleteTask / startEdit / saveEdit / cancelEdit / markDone
  - Lines 257-451: Blade output — header, sort controls, 5 task sections
  - Lines 455-475: Delete confirmation modal (Pattern B)
- `resources/views/livewire/pages/appliances/_edit-form.blade.php` — Edit form partial (template for add form)
- `resources/views/livewire/pages/appliances/create.blade.php:159-244` — `confirm()`: canonical creation logic
- `resources/views/livewire/pages/appliances/create.blade.php:163-173` — Validation rules template
- `app/Models/MaintenanceTask.php` — Model, fillable, casts, scopes
- `database/migrations/2026_06_01_000005_create_maintenance_tasks_table.php` — Schema
- `database/migrations/2026_06_04_090811_add_description_to_maintenance_tasks.php` — Description column
- `app/Models/Appliance.php:31` — `hasMany(MaintenanceTask::class)`
- `resources/views/components/modal.blade.php` — Pattern A modal component
- `resources/views/components/maintenance-task-card.blade.php:1-7` — Card props
- `app/Support/CalendarInterval.php` — `calculateNextDueAt()` used when saving calendar tasks

## Architecture Insights

1. **Volt inline components**: All appliance pages are single-file Volt components — PHP
   `new class extends Component` block at the top, Blade template below. New state/methods
   are added directly to the PHP block.

2. **`#[Computed]` for per-request derived data**: Following lessons.md, the five task
   collections are `#[Computed]`. Any new derived state (e.g., `newTaskIntervalCategory`)
   should be a public property, not `#[Computed]`, since it needs Livewire hydration.

3. **Ownership guard pattern**: `mount()` calls `abort_if()` after verifying the appliance
   belongs to `$user->households()->first()`. The new creation method must perform the
   same check (or can inherit it since `$this->appliance` is already validated in mount).

4. **`interval_unit` branching**: Per lessons.md, any write path must branch on
   `interval_unit`: if calendar → compute `next_due_at` via `CalendarInterval::calculateNextDueAt()`,
   set `next_due_at_value = null`; if metric → set `next_due_at = null`, `next_due_at_value = null`
   (or user-supplied value). This is the most critical correctness rule.

5. **`is_confirmed` must always be `true`** for tasks added via this UI (same as wizard).
   The `scopeCalendar()` and `scopeMetric()` scopes both filter `is_confirmed = true` —
   a task with `is_confirmed = false` would be invisible on the show page.

6. **`anchor_type` default**: Wizard uses `from_last_done` as the common default.
   The add form can default to `from_last_done` and not expose `anchor_type` to the user
   unless we want the complexity. `anchor_date` is only needed for `fixed_calendar`.

7. **No dedicated Action class needed**: The creation logic is ~10 lines and only used
   from one place. Per codebase conventions, the method lives directly in the Volt
   component's PHP block (same as `saveEdit()`). A separate Action class would be
   premature abstraction given the current scope.

## Historical Context (from prior changes)

- `context/archive/2026-06-06-appliance-detail-page/plan.md` — Built all 5 phases of the
  show page (computed task lists, markDone, confirmDelete, inline edit, tests). The archive
  explicitly lists "new task creation" as **out of scope** — this change fills that gap.
- `context/archive/2026-06-06-appliance-detail-page/plan.md` — Pattern B (Livewire
  property modal) was chosen for delete confirmation instead of `x-modal` to keep the
  component self-contained. The same reasoning applies here.
- `context/changes/service-cards-styling/plan.md` — Extracted `x-maintenance-task-card`
  Blade component and refactored both dashboard and show page to use it. The actions slot
  pattern is established and tested.

## Open Questions

1. **Metric task support in the add form?** The wizard's task creation is calendar-only
   (AI generates only calendar tasks). The edit form in `show.blade.php` supports metric
   units. Should the new add form support hours/km? The model and edit path support it,
   but it adds UI complexity. Lean: start calendar-only (matching the wizard's scope) and
   add metric support later. The field is nullable on both due-date fields so adding it
   later is non-destructive.

2. **`anchor_type` exposed to user?** The wizard always uses `from_last_done`; `fixed_calendar`
   is a niche option (e.g., annual filter replacement always in March). Simpler to default
   to `from_last_done` and omit `anchor_type` from the add form, keeping the form minimal.

3. **Where does the button live?** Two natural spots:
   - **Next to "Maintenance Plan" heading** (line ~276) — visually adjacent to the task list, clear intent
   - **Per-section "Add" link** (at the bottom of each category) — more contextual but harder to discover
   Recommendation: Single button next to the section heading, matching the wizard's "Add task" style.

4. **Empty state?** If an appliance has zero tasks (edge case — all were deleted), the page
   currently shows nothing. The add button solves the empty-state UX problem too.

5. **Tests**: Following the `appliance-detail-page` precedent, a new test class
   `ApplianceShowTaskCreateTest` should be added in `tests/Feature/Appliances/`,
   extending the existing `ApplianceTestCase` base (lessons.md rule).

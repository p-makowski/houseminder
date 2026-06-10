# Add New Maintenance Task from Appliance Page — Plan Brief

> Full plan: `context/changes/appliance-new-service-entry/plan.md`
> Research: `context/changes/appliance-new-service-entry/research.md`

## What & Why

The appliance show page has full task management (edit, delete, mark-done) but no way to create new tasks — users are stuck with whatever the wizard generated. This change adds an inline "+ Add task" form that combines the wizard's task-definition step and backdate step into a single box, so a new task (with optional last-service history) can be created without leaving the page.

## Starting Point

`show.blade.php` is a Volt single-file component (~477 lines) with 6 existing public properties for edit/delete state and a `_edit-form.blade.php` partial. Task creation (including `ServiceRecord` population) only happens in `create.blade.php:confirm()`. The inline Pattern B (bool property controls visibility) is already used for the delete confirmation.

## Desired End State

An "+ Add task" button sits next to the "Maintenance Plan" heading. Clicking it reveals an inline form box above the task sections — two sections in one: task definition (name, description, interval, category) and "Last service (optional)" (backdate date, metric reading for metric tasks, notes). On save, the task persists with `is_confirmed = true`; a `ServiceRecord` is created when backdate or metric data is provided. The form collapses and the task appears in the correct section.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Presentation pattern | Inline box (Pattern B, bool `$addingTask`) | No modal — matches existing edit/delete flows; user preference | Plan |
| Metric unit support | Full (all 6 units) | Users should be able to create metric tasks without a two-step workaround | Prior planning |
| Last-service fields | Date + metric reading + notes (all optional) | Mirrors wizard Step 3 exactly, no skip checkbox needed since fields are optional | Plan |
| Notes target | `ServiceRecord.notes` (not `MaintenanceTask`) | Matches wizard `confirm()` behaviour — notes describe a service event, not the task | Research |
| `anchor_type` | Hidden, default `from_last_done` | Minimises form complexity; `fixed_calendar` is a power-user edge case | Research + Plan |
| `last_metric_value` on task | Not set from backdate | Matches wizard — metric reading goes to `ServiceRecord.metric_reading` only | Research |
| `is_confirmed` on save | Always `true` | `scopeCalendar()` / `scopeMetric()` filter `is_confirmed = true` — false = invisible | Research |
| Action class | No — inline method | ~25 lines, single call site | Research |

## Scope

**In scope:**
- 10 new public properties on `show.blade.php` PHP block (`$addingTask` + 9 form fields)
- `startAddTask()`, `saveNewTask()`, `cancelAddTask()` methods
- New `_add-form.blade.php` partial (task section + last-service section)
- "+ Add task" button in the "Maintenance Plan" heading row
- `@if($addingTask)` inline block at top of sections area
- `ApplianceShowTaskCreateTest` (5 test methods)

**Out of scope:**
- Any modal overlay (`x-modal` or bare div)
- Exposing `anchor_type` to the user
- Empty-state prompt for zero-task appliances
- Dedicated Action class
- Changes to edit / delete / markDone flows

## Architecture / Approach

Single Volt component file gets new state and methods. `startAddTask()` resets form properties and sets `$addingTask = true`. `saveNewTask()` validates, branches on `addIntervalCategory` (calendar vs. metric per lessons.md guard), creates the task (with `last_completed_at` from backdate when provided), and conditionally creates a `ServiceRecord` inside the same `DB::transaction`. The form partial has two visual sections but a single save action.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. PHP block | 10 properties + `startAddTask` / `saveNewTask` / `cancelAddTask` with full backdate + ServiceRecord logic | `interval_unit` branching guard must run before `CalendarInterval::calculateNextDueAt()` |
| 2. `_add-form` partial | Two-section form UI: task definition + last-service fields | `wire:model.live` on interval-category radio must trigger instant re-render for unit/field toggling |
| 3. Inline wiring | Feature is user-visible end-to-end | Form placement (above Overdue section) must feel natural, not buried |
| 4. Tests | 5 test methods covering all backdate combinations + auth guard | `ServiceRecord` creation asserts need to check correct `completed_at` and `metric_reading` |

**Prerequisites:** No migrations, no new packages. `ServiceRecord` model already exists.
**Estimated effort:** ~1–2 sessions across 4 phases.

## Open Risks & Assumptions

- `addIntervalCategory` radio uses `wire:model.live` (not plain `wire:model`) so the unit dropdown and last-service fields update without an explicit button click — verify Livewire re-renders the conditional sections correctly.
- When backdate is provided for a calendar task, `next_due_at` is calculated from that date rather than `now()`. If the backdate is in the future, the task would immediately appear in "Overdue" or "This week" sections based on the calculated date — this is correct behaviour, matching the wizard.

## Success Criteria (Summary)

- A new maintenance task (calendar or metric) can be created from the appliance show page in one step, with optional last-service history
- `ServiceRecord` is created exactly when backdate or metric data is provided, matching wizard behaviour
- `php artisan test` and `composer phpstan` pass green with no regressions

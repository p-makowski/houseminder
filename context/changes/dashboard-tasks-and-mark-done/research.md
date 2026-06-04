---
date: 2026-06-04T19:06:40+0000
researcher: p-makowski
git_commit: 5792fd9bac0a15f7388296098ecd4d2fec6456ad
branch: main
repository: houseminder
topic: "Dashboard showing maintenance tasks with mark-done action"
tags: [research, codebase, MaintenanceTask, ServiceRecord, Livewire, Volt, dashboard]
status: complete
last_updated: 2026-06-04
last_updated_by: p-makowski
---

# Research: Dashboard showing maintenance tasks with mark-done action

**Date**: 2026-06-04T19:06:40+0000
**Researcher**: p-makowski
**Git Commit**: 5792fd9bac0a15f7388296098ecd4d2fec6456ad
**Branch**: main
**Repository**: houseminder

## Research Question

What does the current codebase have that's relevant to building a dashboard listing maintenance tasks across all household appliances, with a mark-done action per task?

## Summary

The data layer is solid and purpose-built for this feature. `MaintenanceTask` has `next_due_at` (calendar tasks) and `last_completed_at`, while `ServiceRecord` provides the append-only history log for each completion. S-01 already writes these fields during wizard confirmation — the mark-done action will follow the same pattern at runtime.

The dashboard route (`/dashboard`) exists but renders a static blade file with a placeholder. It must be replaced with a Volt page (consistent with the rest of the app) or have a Livewire component embedded. All other app pages use **class-based Volt** with `#[Layout]` attributes, so a new Volt dashboard page is the natural fit.

No overdue/upcoming scopes exist on the model yet — they need to be added. No mark-done action exists anywhere in the codebase — this is genuinely new territory.

---

## Detailed Findings

### MaintenanceTask Model

**File**: `app/Models/MaintenanceTask.php`

**All fields** (from `#[Fillable]` + migration):

| Field | Type | Notes |
|---|---|---|
| `appliance_id` | FK | Cascades on delete |
| `name` | string | |
| `description` | string, nullable | Added in `2026_06_04_090811` migration |
| `interval_value` | unsignedInteger | |
| `interval_unit` | enum | `days\|weeks\|months\|years\|hours\|km` |
| `anchor_type` | enum | `from_last_done\|fixed_calendar`, default `from_last_done` |
| `anchor_date` | date, nullable | Used only for `fixed_calendar` tasks |
| `last_completed_at` | datetime, nullable | Updated on every mark-done |
| `last_metric_value` | double, nullable | Metric tasks only (hours/km) |
| `next_due_at` | datetime, nullable | Calendar tasks only — authoritative due date |
| `next_due_at_value` | double, nullable | Metric tasks only |
| `is_confirmed` | boolean, default false | Set to true after wizard confirmation |

**Relationships**:
- `appliance()` → BelongsTo `Appliance`
- `serviceRecords()` → HasMany `ServiceRecord`

**No scopes yet** — `overdue()`, `upcoming()`, `dueWithin()` etc. all need to be added.

**Lesson (from lessons.md)**: `interval_unit` determines which next-due field is authoritative. Any code that reads or writes due dates must branch on `interval_unit` first.

---

### ServiceRecord Model

**File**: `app/Models/ServiceRecord.php`

| Field | Type | Notes |
|---|---|---|
| `maintenance_task_id` | FK | Cascades on delete |
| `completed_at` | datetime | |
| `metric_reading` | double, nullable | For metric task completions |
| `notes` | text, nullable | |

This is the **append-only log** for task completion history. Mark-done must:
1. Insert a new `ServiceRecord` row with `completed_at = now()`.
2. Update `MaintenanceTask.last_completed_at = now()`.
3. Recalculate and write `next_due_at` based on `anchor_type` + `interval_value` + `interval_unit`.

The existing code in `create.blade.php:212–234` is the only place this pattern is currently implemented — for backdated tasks during wizard confirmation. The mark-done runtime action will mirror it.

---

### Existing Completion Logic (only reference point)

**File**: `resources/views/livewire/pages/appliances/create.blade.php:212–234`

```php
// Inside confirm() method — creates backdated service records:
$task->update([
    'last_completed_at' => $completionDate,
    'next_due_at'       => $nextDue,          // recalculated from interval
]);
ServiceRecord::create([
    'maintenance_task_id' => $task->id,
    'completed_at'        => $completionDate,
]);
```

This is the **template for mark-done**. The runtime action differs only in using `now()` instead of a user-provided backdate.

---

### Household → Task Query Path

There is no direct `Household → MaintenanceTask` relationship. Tasks live under `Appliance`:

```
Household → hasMany Appliance → hasMany MaintenanceTask
```

To get all tasks for a household, the query must go through appliances:

```php
MaintenanceTask::whereHas('appliance', fn($q) =>
    $q->where('household_id', $householdId)
)->with('appliance')->get();
```

For the dashboard, filtering to only **confirmed** calendar tasks with a `next_due_at` value is essential (metric tasks have `next_due_at = null`).

---

### Dashboard Route and Current State

**File**: `routes/web.php`

```php
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');
```

**Current view** (`resources/views/dashboard.blade.php`): Uses `<x-app-layout>` with header slot and a single "You're logged in!" message. It is a **plain blade file, not a Volt component**. All other auth-required pages are Volt — this inconsistency should be resolved by converting the dashboard to a Volt page.

**Navigation** (`resources/views/livewire/layout/navigation.blade.php:35`): A "Dashboard" link already exists in the nav and points to `route('dashboard')`. No new nav entry needed.

---

### Livewire / Volt Patterns

All app pages use **class-based Volt** with the `new #[Layout('layouts.app')] class extends Component { ... }` pattern.

**Mount pattern** (from show.blade.php):
```php
public function mount(Appliance $appliance): void
{
    // ownership guard: abort_if($appliance->household_id !== auth()->user()->household_id, 403);
}
```

The new dashboard page will use `mount()` to load the authenticated user's household tasks.

**Action pattern** (wire:click, from create.blade.php:368):
```php
// In template:
wire:click.prevent="deleteTask({{ $i }})"

// In component class:
public function deleteTask(int $index): void { ... }
```

Mark-done follows this same pattern: `wire:click="markDone({{ $task->id }})"`.

**Success feedback pattern** (from update-profile-information-form.blade.php:110):
```php
// In component: $this->dispatch('task-done', id: $taskId);
// In template:  <x-action-message on="task-done">Done.</x-action-message>
```

**Redirect with navigate** (from auth pages):
```php
$this->redirect(route('dashboard'), navigate: true);
```

---

### Route and File Conventions

| Concern | Pattern |
|---|---|
| Route registration | `Volt::route('path', 'pages.component-name')` in `routes/web.php` |
| Volt page files | `resources/views/livewire/pages/{area}/{name}.blade.php` |
| Middleware | `['auth', 'verified']` group wrapping |
| Layout attribute | `new #[Layout('layouts.app')] class extends Component` |
| Model binding | `mount(Model $model)` — Laravel auto-resolves route model binding |

A dashboard Volt page would live at `resources/views/livewire/pages/dashboard.blade.php` (or `dashboard/index.blade.php`).

---

## Code References

- `app/Models/MaintenanceTask.php:1–39` — Model, fields, casts, relationships
- `app/Models/ServiceRecord.php:1–28` — Completion log model
- `database/migrations/2026_06_01_000005_create_maintenance_tasks_table.php:13–27` — Full schema
- `database/migrations/2026_06_01_000006_create_service_records_table.php:13–20` — ServiceRecord schema
- `database/migrations/2026_06_04_090811_add_description_to_maintenance_tasks.php` — Description column
- `database/factories/MaintenanceTaskFactory.php:16–32` — Factory defaults
- `resources/views/livewire/pages/appliances/create.blade.php:153–241` — `confirm()` method (completion template)
- `resources/views/livewire/pages/appliances/show.blade.php:1–72` — Task display, read-only
- `resources/views/dashboard.blade.php` — Current empty dashboard
- `routes/web.php` — All routes incl. dashboard
- `resources/views/livewire/layout/navigation.blade.php:35` — Existing Dashboard nav link
- `context/foundation/lessons.md` — 4 locked lessons

---

## Architecture Insights

1. **Dashboard is a blank canvas** — the route and nav link already exist; only the view content needs replacing with a Volt component.

2. **No status enum** — "done" is not a field on `MaintenanceTask`. Done-ness is expressed by `last_completed_at` being set and `next_due_at` being in the future. Overdue = `next_due_at < now()` and `is_confirmed = true`. Pending = `next_due_at >= now()`.

3. **Metric tasks are invisible to calendar queries** — `interval_unit IN ('hours','km')` tasks have `next_due_at = null` and should be excluded from the dashboard's due-date view (S-01 scope limit). This is safe — no metric tasks are created yet.

4. **Next-due recalculation belongs in the model or a dedicated Action** — the wizard inline-calculates it; mark-done should extract this into a reusable `RecalculateNextDue` action or model method to avoid duplication.

5. **Ownership guard required** — every Livewire action that writes must verify `$task->appliance->household_id === auth()->user()->household_id`. The existing show page has this pattern.

6. **`is_confirmed` filter** — only confirmed tasks (wizard completion step 4 done) have meaningful `next_due_at` values. Unconfirmed draft tasks should be excluded from the dashboard.

---

## Historical Context (from prior changes)

- `context/archive/2026-06-01-domain-schema-bootstrap/plan.md:200–254` — F-01 created all domain models and migrations; dual next-due field design decision documented here.
- `context/archive/2026-06-03-first-appliance-ai-plan/plan.md:1–608` — S-01 implemented the wizard and established the completion-record pattern (`ServiceRecord` + `last_completed_at` update). AI constrained to calendar units only.
- F-01 explicitly noted the dashboard as `"empty placeholder ("You're logged in!")"` — it was never meant to stay this way.

---

## Open Questions

1. **Grouping strategy** — overdue vs. due soon (within 7 days?) vs. upcoming? What threshold defines "due soon"?
2. **Scope** — show tasks from all appliances, or let the user filter by appliance?
3. **After mark-done** — does the task disappear from "overdue" immediately (optimistic UI) or after a page refresh?
4. **Metric tasks** — exclude silently from the dashboard, or show a separate "not tracked by date" section?
5. **Next-due recalculation** — extract to a shared Action, or keep inline in the Livewire component?
6. **`fixed_calendar` anchor_type** — does mark-done reset `next_due_at` to `anchor_date + N intervals`, or advance from `now()`? (This is a domain question with no prior decision.)

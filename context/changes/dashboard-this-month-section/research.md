---
date: 2026-06-07T00:00:00+00:00
researcher: p-makowski
git_commit: 0618a33c5392d510122405e2937c23e5dd7de81b
branch: main
repository: houseminder
topic: "Add This Month dashboard section and recurrence labels on task cards"
tags: [research, codebase, dashboard, maintenance-tasks, livewire, appliance-detail]
status: complete
last_updated: 2026-06-07
last_updated_by: p-makowski
---

# Research: Add "This Month" Dashboard Section and Recurrence Labels

**Date**: 2026-06-07  
**Researcher**: p-makowski  
**Git Commit**: 0618a33c5392d510122405e2937c23e5dd7de81b  
**Branch**: main  
**Repository**: houseminder

## Research Question

Add a separate "This month" section to the dashboard (and appliance detail page) showing tasks due in the next 30 days, excluding tasks already in "Overdue" and "Due this week". Also show a recurrence label ("Every 2 months") on every task card.

---

## Summary

The dashboard is a single Livewire Volt file with four `#[Computed]` query properties. The "Upcoming" section currently captures everything beyond 7 days with no upper bound — splitting it at 30 days is a two-line query change. The appliance detail page uses a flat sorted list (no sections) and would need sections added to mirror the dashboard. Recurrence labels are already shown on the appliance detail page and the manual-tracking dashboard section; the three calendar-based dashboard sections simply need the same `Every {{ $task->interval_value }} {{ $task->interval_unit }}` line added. There are no shared Blade partials for task cards — both pages use inline markup.

---

## Detailed Findings

### Dashboard Component

- **File**: `resources/views/livewire/pages/dashboard.blade.php` (Livewire Volt, inline class)
- **Route**: `routes/web.php:14`, name `dashboard`
- **Auth guard**: `mount()` aborts 403 if no household (line 17)
- **markDone**: `markDone(int $taskId)` lines 20–28, calls `RecordTaskCompletion` action
- **resolveHouseholdId**: private helper at line 75, used by all computed properties

Current computed sections:

| Method | Lines | Query condition |
|--------|-------|-----------------|
| `overdue()` | 31–39 | `next_due_at < now()` |
| `dueThisWeek()` | 42–52 | `whereBetween(next_due_at, [now, now+7d])` |
| `upcoming()` | 55–63 | `next_due_at > now()+7d` — **no upper bound** |
| `metric()` | 66–73 | metric scope (hours/km), no date condition |

All calendar sections chain: `MaintenanceTask::calendar()->forHousehold($id)->...->with('appliance')->get()`

**What changes**: `upcoming()` condition becomes `next_due_at > now()+30d`. A new `dueThisMonth()` computed property fills `now()+7d < next_due_at <= now()+30d`.

Boundary note from `tests/Feature/Dashboard/DashboardBoundaryTest.php`: a task at exactly `now()+7d` falls in `dueThisWeek`; `now()+7d+1s` falls in `upcoming`. The same boundary logic applies to the new section.

### MaintenanceTask Model

- **File**: `app/Models/MaintenanceTask.php`

Key fields:

| Field | Type | Purpose |
|-------|------|---------|
| `interval_value` | unsignedInteger | Recurrence magnitude (e.g. 2) |
| `interval_unit` | enum | `days \| weeks \| months \| years \| hours \| km` |
| `next_due_at` | datetime (nullable) | Authoritative for calendar tasks |
| `next_due_at_value` | float (nullable) | Authoritative for metric tasks |
| `is_confirmed` | boolean | Only confirmed tasks appear on dashboard |

Existing scopes (lines 28–47):
- `scopeCalendar()` — requires `interval_unit IN ['days','weeks','months','years']`, `is_confirmed = true`, `next_due_at IS NOT NULL`
- `scopeMetric()` — requires `interval_unit IN ['hours','km']`, `is_confirmed = true`
- `scopeForHousehold(int $householdId)` — filters through `appliance.household_id`

**No `dueThisMonth` scope exists** yet. A new scope is needed:

```php
public function scopeDueThisMonth(Builder $query): void
{
    $now = now();
    $query->where('next_due_at', '>', $now->copy()->addDays(7))
          ->where('next_due_at', '<=', $now->copy()->addDays(30));
}
```

This scope should **not** be added directly — the date boundaries are better kept in the component as computed properties (consistent with how `dueThisWeek` and `upcoming` are defined, so the boundaries can see each other clearly).

### Recurrence Label — Current State

Recurrence is **purely derived** from `interval_value` + `interval_unit`; no pre-formatted column exists in the DB.

Current occurrences in views:

| Location | Line | Template |
|----------|------|----------|
| `dashboard.blade.php` — manual tracking section | 165 | `Every {{ $task->interval_value }} {{ $task->interval_unit }}` |
| `appliances/show.blade.php` — read card | 349 | `Every {{ $task->interval_value }} {{ $task->interval_unit }}` |

**Missing** on dashboard sections: overdue (lines 96–104), due this week (lines 117–126), upcoming (lines 139–148).

**Pluralization issue**: `Every 1 months` instead of `Every 1 month`. Fix options:
1. `Str::plural($task->interval_unit, $task->interval_value)` — works for `month/months`, `week/weeks`, `year/years`, `day/days` but produces `kms` for `km` (wrong)
2. Conditional: `$task->interval_value === 1 ? $task->interval_unit : Str::plural($task->interval_unit)` — same `km` issue
3. A small lookup map: `['km' => 'km', 'hours' => 'h', ...]`

Simplest safe approach for now: use `Str::plural` for calendar units only; metric units (`hours`, `km`) already display correctly as-is in their dedicated manual-tracking section (no pluralization needed there). The recurrence label on calendar cards only covers `days/weeks/months/years` where Str::plural works correctly.

### Appliance Detail Page

- **File**: `resources/views/livewire/pages/appliances/show.blade.php`
- **Component class**: Livewire Volt inline (lines 1–194)
- **`sortedTasks()`**: computed property (lines 49–69), loads `$this->appliance->maintenanceTasks()->get()` then sorts in PHP by `$sortBy` (due_date / name / interval)
- **Status coloring**: inline `match(true)` at lines 240–255 — assigns `overdue / due_soon / upcoming / metric` per card
- **Section structure**: **none** — flat list, no grouping by time window
- **Recurrence label**: already present at line 349: `Every {{ $task->interval_value }} {{ $task->interval_unit }}`

The user wants "this also applies to appliance detail page" → add the same four sections (overdue, due this week, this month, upcoming) to the appliance detail page. This means replacing the flat `sortedTasks()` computed property with four separate computed properties mirroring the dashboard, or a single grouped collection. However, the appliance detail page currently supports sort buttons (by name, due date, frequency) which would need to be reconciled with section-based grouping.

**Decision point for planning**: either (a) add sections to appliance detail (matches dashboard UX, breaks sort controls), or (b) keep flat list but add a "this month" visual badge/indicator on cards in that date range.

### No Shared Task Card Partials

Both pages render task cards as inline markup — no `resources/views/components/task-card.blade.php` or similar exists. Adding the recurrence label to three dashboard sections means editing `dashboard.blade.php` in three places. A shared component would reduce duplication but is beyond the stated scope of this change.

---

## Code References

- `resources/views/livewire/pages/dashboard.blade.php:31-63` — three calendar computed sections
- `resources/views/livewire/pages/dashboard.blade.php:55-63` — `upcoming()` to be split
- `resources/views/livewire/pages/dashboard.blade.php:96-104` — overdue card (missing recurrence)
- `resources/views/livewire/pages/dashboard.blade.php:117-126` — due this week card (missing recurrence)
- `resources/views/livewire/pages/dashboard.blade.php:139-148` — upcoming card (missing recurrence)
- `resources/views/livewire/pages/dashboard.blade.php:165` — manual tracking recurrence reference pattern
- `app/Models/MaintenanceTask.php:28-47` — existing scopes (`calendar`, `metric`, `forHousehold`)
- `app/Models/MaintenanceTask.php:61-71` — casts including `next_due_at` as datetime
- `app/Support/CalendarInterval.php` — date math for `calculateNextDueAt`, not relevant here
- `resources/views/livewire/pages/appliances/show.blade.php:49-69` — `sortedTasks()` flat list
- `resources/views/livewire/pages/appliances/show.blade.php:238-355` — task card loop and inline status logic
- `resources/views/livewire/pages/appliances/show.blade.php:349` — existing recurrence label pattern
- `tests/Feature/Dashboard/DashboardBoundaryTest.php` — boundary test (7-day boundary documented)

---

## Architecture Insights

1. **Volt inline component pattern** — both pages use `new class extends Component { ... }` inside the blade file. New computed properties follow the `#[Computed]` attribute + `public function name(): Collection` pattern.

2. **Date boundaries owned by the component** — no scope on the model carries a specific window. The model provides `calendar()` + `forHousehold()` as composable filters; the component applies the date condition inline. This is the existing convention and should be followed for `dueThisMonth`.

3. **`now()` called per property** — each computed property calls `now()` independently, which means there is a sub-second race window where a task could theoretically fall in two sections (or none) if the request spans midnight. This is acceptable and matches the existing pattern.

4. **Appliance detail sort vs. sections tension** — the sort buttons (Name / Due date / Frequency) and section-based grouping are orthogonal UX paradigms. The most likely resolution is to drop or hide the sort controls when sections are active, or to only apply sections in the due-date sort view. This is a planning-phase decision.

5. **Recurrence label format** — the pattern `Every {{ $task->interval_value }} {{ $task->interval_unit }}` is already the de-facto standard. For calendar units, `Str::plural($unit, $value)` handles pluralization correctly. The metric units (`hours`, `km`) should be left as-is.

---

## Historical Context

- `context/archive/` — `appliance-detail-page` change was recently closed (commit `0618a33`). The show page task cards and inline section logic came from that work. Recurrence label (`line 349`) was added there.
- The `DashboardBoundaryTest.php` was established in an earlier dashboard phase — the 7-day boundary is already test-covered; a new boundary test for 30 days will be needed.

---

## Open Questions

1. **"This month" = next 30 days or end-of-calendar-month?** The change notes say "next 30 days". A rolling 30-day window is simpler and consistent with how "this week" uses 7 days (not end-of-week). Recommend: rolling `now()+30d`.

2. **Appliance detail page approach**: Full sections (4 groups) vs. badge/indicator on flat list? Full sections mirrors the dashboard but conflicts with the existing sort UI.

3. **Pluralization helper**: Inline `Str::plural` in the view, or a model accessor `recurrenceLabel` / a Blade component? Inline is simplest and consistent with the existing codebase style.

4. **Empty "This month" section**: Should it render an empty-state message like the other sections, or be hidden entirely? Existing sections all show a "nothing here" message — likely keep consistent.

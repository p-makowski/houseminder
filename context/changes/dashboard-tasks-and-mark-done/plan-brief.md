# Dashboard Tasks and Mark Done — Plan Brief

> Full plan: `context/changes/dashboard-tasks-and-mark-done/plan.md`
> Research: `context/changes/dashboard-tasks-and-mark-done/research.md`

## What & Why

The `/dashboard` page is a static "You're logged in!" placeholder. This change replaces it with a live Volt component that shows all of the household's confirmed maintenance tasks grouped by urgency, and adds a mark-done action so users can log task completions directly from the dashboard.

## Starting Point

The data layer is complete: `MaintenanceTask` and `ServiceRecord` models exist with all needed fields, and the wizard's `confirm()` method (`create.blade.php:153–241`) already demonstrates the three-step completion write. The dashboard route and nav link exist but the view is a static blade file, inconsistent with every other auth page which uses class-based Volt.

## Desired End State

Authenticated users see a dashboard with four sections — Overdue, Due this week, Upcoming, and Manual tracking (metric tasks). Each calendar task has a "Mark done" button. Clicking it creates a `ServiceRecord`, advances `next_due_at` by the task's interval from now, and re-renders the list in place — no page reload. Metric tasks are displayed with a "No date" badge; no mark-done button for them yet.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Dashboard implementation | Class-based Volt page | Consistent with every other auth page in the app | Research |
| Grouping strategy | 4 sections: Overdue / Due this week / Upcoming / Manual tracking | Three-level urgency makes the most critical tasks unmissable | Plan |
| "Due this week" threshold | 7 days, hardcoded | Simplest baseline; no user preference UX needed at this stage | Plan |
| Metric tasks | Fourth section, display-only | No metric tasks exist yet; avoids dead "Log reading" UX | Plan |
| Mark-done UX | Livewire reactive re-render | No page reload; follows Livewire's intended model | Plan |
| next_due_at recalculation | `now() + interval` for all anchor types | One code path; user accepted schedule drift for fixed_calendar tasks | Plan |
| Completion logic extraction | `RecordTaskCompletion` action class | Tested in isolation in Phase 1 before the UI consumes it | Plan |
| Ownership guard | Double layer: action + scope-filtered fetch in markDone() | Defense in depth against direct wire:click manipulation | Plan |

## Scope

**In scope:**
- `MaintenanceTask` scopes: `calendar()`, `metric()`, `forHousehold()`
- `RecordTaskCompletion` action class
- Dashboard Volt page with four sections
- Route conversion (`Volt::route`) + deletion of old static blade
- Feature tests for the action and the page

**Out of scope:**
- Task editing or deletion (S-03)
- Mark-done for metric tasks with a reading input
- Appliance-level filtering on the dashboard
- Configurable "due soon" threshold
- Pagination

## Architecture / Approach

`RecordTaskCompletion` is a plain action class (like `GenerateMaintenancePlan`) that encapsulates the three-step completion write. The Volt component fetches the task through the household-scoped query (built-in ownership guard), delegates to the action, then re-runs all four collection queries to refresh the view. No new routes, no new models, no migrations.

```
markDone(taskId)
  └── MaintenanceTask::calendar()->forHousehold($id)->findOrFail($taskId)
  └── RecordTaskCompletion::execute($task, $user)
        ├── ServiceRecord::create(completed_at = now())
        ├── $task->last_completed_at = now()
        └── $task->next_due_at = now() + interval
  └── reload four collections → Livewire re-renders
```

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Backend Foundation | Model scopes, `RecordTaskCompletion` action, isolated tests | PHPStan level 6 may flag type issues on the new action |
| 2. Dashboard Volt Page | Route conversion, Volt component, UI template, page tests | Old `dashboard.blade.php` must be deleted or it masks the Volt page |

**Prerequisites:** Wizard (S-01) must be functional — tests in `tests/Feature/Appliances/` must pass before Phase 1 starts.
**Estimated effort:** ~1 session across 2 phases.

## Open Risks & Assumptions

- `auth()->user()->households()->first()` is the household-resolution pattern used by S-01; assumes each user has exactly one household (consistent with current registration flow).
- PHPStan level 6 may require explicit return types on the new action — address during Phase 1 before moving on.

## Success Criteria (Summary)

- `php artisan test` passes in full with no regressions
- Clicking "Mark done" on an overdue task moves it to Upcoming without a page reload
- `ServiceRecord::latest()->first()` and a future `next_due_at` are verifiable via tinker after a mark-done action

# "This Month" Section + Recurrence Labels — Plan Brief

> Full plan: `context/changes/dashboard-this-month-section/plan.md`
> Research: `context/changes/dashboard-this-month-section/research.md`

## What & Why

The dashboard "Upcoming" section is overloaded — it captures everything beyond 7 days with no upper bound, making it hard to prioritise near-term tasks. This plan splits "Upcoming" at 30 days: a new "This month" section catches the near-term batch (8–30 days), while "Upcoming" becomes the far-future queue. It also adds recurrence labels ("Every 6 months") to every calendar task card on both the dashboard and the appliance detail page, and restructures the appliance detail page from a flat list into the same five-section layout.

## Starting Point

The dashboard has four computed sections (`overdue`, `dueThisWeek`, `upcoming`, `metric`). `upcoming()` has no upper bound. Three of the four card blocks show no recurrence label. The appliance detail page is a flat PHP-sorted list with inline status logic and sort buttons; its task cards already show recurrence.

## Desired End State

Both the dashboard and the appliance detail page show five sections: **Overdue** / **Due this week** / **This month** (blue, rolling 30-day window) / **Upcoming** (> 30 days) / **Manual tracking**. Every calendar task card shows "Every N unit(s)" below the due date. The appliance detail sort buttons (Name / Due date / Frequency) re-order tasks within each section independently.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| "This month" window | Rolling `now()+7d < due ≤ now()+30d` | Consistent with "this week" = rolling 7 days, no calendar-boundary edge cases | Plan |
| Appliance detail structure | Full sections + sort within section | Mirrors dashboard UX as requested; sort buttons preserved | Plan |
| Recurrence pluralisation | `Str::plural` inline in Blade (calendar sections only) | One-liner fix; handles days/weeks/months/years correctly; metric section stays as-is | Research + Plan |
| Empty-state behaviour | Show message, never hide section | Consistent with all existing dashboard sections | Plan |
| Metric tasks on appliance detail | "Manual tracking" section at bottom | Mirrors dashboard; no task silently disappears | Plan |
| `is_confirmed` filtering on appliance detail | No filter (all tasks shown) | Appliance detail is a management view; factory default is `false`; applying `calendar()` scope would break existing tests | Plan |

## Scope

**In scope:**
- New `dueThisMonth()` computed property + narrowed `upcoming()` on dashboard
- "This month" section in dashboard Blade view (blue)
- Recurrence label on all four calendar card blocks (dashboard)
- Five section computed properties + private `sortTasks()` helper on appliance detail
- Five section blocks in appliance detail Blade view
- 30-day boundary tests (4 new); appliance section display tests (6 new)
- Rename misleading `test_task_due_one_second_past_seven_days_is_upcoming`

**Out of scope:**
- Shared task-card Blade component
- `recurrenceLabel()` model accessor
- Multi-household support
- Changes to wizard / AI generation flows
- Metric task pluralisation

## Architecture / Approach

Both pages use Livewire Volt inline components. The dashboard gets one new `#[Computed]` method and one updated method. The appliance detail page gets its single `sortedTasks()` replaced by five `#[Computed]` methods sharing a private `sortTasks(Collection): Collection` helper that reads `$this->sortBy`. Date boundaries live in the component (not in model scopes), consistent with the existing codebase convention. The appliance detail uses raw relationship queries — not the `calendar()` scope — to avoid filtering out unconfirmed tasks.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Dashboard | New "This month" section, narrowed "Upcoming", recurrence labels, boundary tests | None — straightforward computed + Blade addition |
| 2. Appliance detail | Flat list → 5 sections with sort-within-section, recurrence labels, section tests | Edit/delete forms must be reproduced inside 5 section loops; `is_confirmed` filter must be omitted |

**Prerequisites:** None — no migrations, no new models, no route changes.  
**Estimated effort:** ~2 focused sessions across 2 phases.

## Open Risks & Assumptions

- The metric "Mark done" button on the appliance detail page is removed (metric tasks have no calendar due date). This is a behaviour change not explicitly requested — verify with user if needed.
- `Str::plural('hours', 1)` returns "hour" (correct); `Str::plural('km', 2)` returns "km" (correct in Laravel's inflector) — verified by reasoning, confirm during implementation.

## Success Criteria (Summary)

- All existing dashboard and appliance tests pass; 10 new tests added
- Tasks in the 8–30 day window appear in "This month" on both pages; tasks beyond 30 days in "Upcoming"
- Every calendar task card shows a grammatically correct recurrence label

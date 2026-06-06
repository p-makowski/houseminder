# Appliance Detail Page — Plan Brief

> Full plan: `context/changes/appliance-detail-page/plan.md`

## What & Why

The appliance detail page is currently read-only — users can see their maintenance tasks but can't act on them. This change makes the page the primary place to manage schedules for a single appliance: mark tasks done, edit their intervals, delete them, and quickly see what's overdue vs upcoming without cross-referencing the dashboard.

## Starting Point

`show.blade.php` is 73 lines: a `mount()` that loads the appliance once, and a flat `@foreach` that renders tasks with no colors, no buttons, and no sort. All existing interactivity (mark-done, colors) lives in `dashboard.blade.php`, which this change will mirror for the per-appliance view.

## Desired End State

The detail page shows status-colored task cards (red/yellow/gray matching the dashboard exactly), a segmented sort control (Name / Due date / Frequency), and three inline actions per task: Mark done, Edit (expands to an inline form with all fields), and Delete (confirmation modal warning about service history loss). All actions reflect immediately via a `#[Computed]` sortedTasks property.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) |
| --- | --- | --- |
| Edit UX | Inline (card expands) | No existing modal-edit pattern for tasks; keeps the user on the page without a separate route |
| Edit fields | All — name, description, interval, anchor type, next_due_at | User wants full post-confirmation control over the schedule |
| Recalculation on save | Yes — from last_completed_at | Keeps the schedule consistent with the new interval without stale due dates |
| next_due_at override | User can set it explicitly | Provides escape hatch when auto-recalc isn't what the user wants |
| Mark done scope | Calendar AND metric tasks | RecordTaskCompletion already handles both; service history recorded either way |
| Sort UI | Segmented button group | Visible, stateless (no dropdown), consistent with dashboard's always-visible controls |
| Default sort | Due date ascending, metric tasks last | Most actionable order — overdue tasks surface first |
| Color thresholds | Dashboard-exact: overdue / ≤7 days / upcoming | Zero cognitive mismatch between pages; same color always means same urgency |
| Delete confirmation | Modal with service history warning | cascadeOnDelete is silent at the DB level; users need the warning |
| Interval unit switching | Same category only (calendar↔calendar, metric↔metric) | Cross-category switch flips which next_due field is authoritative — out of scope |
| Test depth | Key paths: mark-done, edit+recalc, delete, 403 guards | RecordTaskCompletion is already thoroughly covered in Dashboard tests |

## Scope

**In scope:**
- Status color coding (red/yellow/gray) matching dashboard
- Segmented sort controls (Name, Due date, Frequency)
- Mark done for all task types (calendar + metric)
- Inline task edit with all fields + recalculation
- Task delete with confirmation modal
- Feature tests for all three write paths

**Out of scope:**
- Cross-category interval_unit switching
- Service record history view
- Sort persistence across page reloads
- Bulk actions
- New routes or Volt pages

## Architecture / Approach

All changes are contained in the single `show.blade.php` Volt component. Task retrieval moves from `mount()` eager-load to a `#[Computed] sortedTasks()` property — this gives reactive refresh after every mutation without manual reload calls. Edit and delete state each occupy a single nullable int (`editingTaskId`, `deletingTaskId`). `RecordTaskCompletion` is reused directly; no new action classes introduced.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Status colors + sort | Colored cards, segmented sort, reactive collection | Interval normalization for sort may feel surprising if units differ |
| 2. Mark done | Button on every task, service record created | None — direct reuse of existing action |
| 3. Delete with confirmation | Modal, cascade delete, 403 guard | Ensure PHP-level ownership guard fires before DB delete |
| 4. Inline edit | All fields editable, next_due_at recalculation | Most complex: edit state properties + validation + recalc branches |
| 5. Feature tests | 3 new test classes, full suite green | Recalc test requires time-freezing to be deterministic |

**Prerequisites:** Phases 1–4 must each pass automated verification before the next begins.
**Estimated effort:** ~1–2 sessions across 5 phases.

## Open Risks & Assumptions

- If a task has `last_completed_at = null` and `anchor_date = null`, recalculation falls back to `now()` — this is a minor surprise but acceptable for an edge case (newly added task never marked done)
- The interval normalization for "Frequency" sort (days×1, weeks×7, months×30, years×365) is approximate — a "1 month" task won't sort identically to a "30 day" task. Acceptable for a UI sort; not a business logic concern

## Success Criteria (Summary)

- All three write paths (mark-done, edit, delete) work on the running app with correct DB updates and no console errors
- Status colors and sort match expected behavior across task types (calendar + metric, all statuses)
- No regressions on the dashboard or other appliance pages

---
date: 2026-06-07T12:00:01+00:00
researcher: p-makowski
git_commit: 554cb3408a2ed145fcd10922a5c79dbe927f7e91
branch: main
repository: houseminder
topic: "Service card layout redesign and DRY unification across appliance detail and dashboard"
tags: [research, codebase, service-cards, livewire, blade, maintenance-tasks, dashboard]
status: complete
last_updated: 2026-06-07
last_updated_by: p-makowski
---

# Research: Service card layout redesign and DRY unification

**Date**: 2026-06-07T12:00:01+00:00
**Researcher**: p-makowski
**Git Commit**: 554cb3408a2ed145fcd10922a5c79dbe927f7e91
**Branch**: main
**Repository**: houseminder

## Research Question

On service cards in appliance detail view, "Edit" and "Delete" buttons should be moved from upper right corner to bottom right corner. After "Every X months" string there should be information about when the service was executed last time. When this is done then cards on dashboards should look the same, preferably reuse the same components (DRY).

## Summary

Both the appliance detail and dashboard pages have their own inline Blade markup for maintenance task cards — no shared component exists today. The card layouts differ in significant ways (layout axis, fields shown, buttons present). The `last_completed_at` field is already available on every `MaintenanceTask` record with no extra queries needed. The plan should extract a shared anonymous Blade component that both pages use, parameterized for the differences (show appliance name, show edit/delete, show draft badge). No schema or query changes are required.

## Detailed Findings

### Appliance Detail Card (current)

**File:** `resources/views/livewire/pages/appliances/show.blade.php` — Volt inline component

The same card template is repeated verbatim across **five sections** (overdue, due this week, this month, upcoming, manual tracking). The card structure is:

```html
<div class="bg-white border border-{color}-200 rounded-md p-4">
  <!-- header row: title left, buttons right -->
  <div class="flex justify-between items-start">
    <div>
      <h3 class="font-medium text-gray-900">{{ $task->name }}</h3>
      @if(!$task->is_confirmed)
        <span class="text-xs text-amber-600 border border-amber-200 bg-amber-50 rounded px-1.5 py-0.5 mt-0.5 inline-block">Draft</span>
      @endif
    </div>
    <!-- buttons in UPPER-RIGHT — to be moved to bottom-right -->
    <div class="flex items-center gap-3">
      <span class="text-xs text-{color}-600">Due {{ $task->next_due_at->format('M j, Y') }}</span>
      <button wire:click="markDone(...)">Mark done</button>  <!-- indigo -->
      <button wire:click="startEdit(...)">Edit</button>       <!-- gray -->
      <button wire:click="confirmDelete(...)">Delete</button> <!-- red -->
    </div>
  </div>
  <!-- optional description -->
  @if($task->description)
    <p class="text-sm text-gray-500 mt-1">{{ $task->description }}</p>
  @endif
  <!-- interval footer — "last executed" info to be added after this -->
  <p class="text-xs text-gray-400 mt-2">
    Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}
  </p>
</div>
```

**Edit/Delete button locations** (upper-right flex div):
- `show.blade.php:322-323` — Overdue section
- `show.blade.php:362-363` — Due this week
- `show.blade.php:402-403` — This month
- `show.blade.php:442-443` — Upcoming
- `show.blade.php:477-478` — Manual tracking (no Mark done; Edit/Delete only)

**"Every X months" line locations:**
- `show.blade.php:329` — Overdue
- `show.blade.php:369` — Due this week
- `show.blade.php:409` — This month
- `show.blade.php:449` — Upcoming
- `show.blade.php:484` — Manual tracking

### Dashboard Card (current)

**File:** `resources/views/livewire/pages/dashboard.blade.php` — Volt inline component

```html
<div class="bg-white border border-{color}-200 rounded-md px-4 py-3 flex justify-between items-center">
  <div>
    <p class="font-medium text-gray-900">{{ $task->appliance->name }} — {{ $task->name }}</p>
    <p class="text-sm text-{color}-600">Due {{ $task->next_due_at->format('M j, Y') }}</p>
    <p class="text-sm text-gray-500">
      Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}
    </p>
  </div>
  <button wire:click="markDone(...)">Mark done</button>
</div>
```

Dashboard card locations: `dashboard.blade.php:109-118` (overdue section example); pattern repeats for each section.

### Side-by-side diff of card features

| Feature | Appliance Detail | Dashboard |
|---|---|---|
| Layout axis | Vertical (`items-start`) | Horizontal (`items-center`) |
| Padding | `p-4` | `px-4 py-3` |
| Appliance name prefix | No | Yes (`Appliance — Task`) |
| Due date | In upper-right row | Below name (left stack) |
| Description | Yes (if present) | No |
| Draft badge | Yes (if unconfirmed) | No (only confirmed tasks shown) |
| Mark done button | Yes (calendar tasks) | Yes |
| Edit button | Yes (upper-right) | No |
| Delete button | Yes (upper-right) | No |
| Interval text | `text-xs text-gray-400` | `text-sm text-gray-500` |
| Last executed | Not shown | Not shown |

### Last Executed Data Availability

**`MaintenanceTask::last_completed_at`** — nullable datetime column, directly on the `maintenance_tasks` table:
- Migration: `database/migrations/2026_06_01_000005_create_maintenance_tasks_table.php:21`
- Model fillable: `app/Models/MaintenanceTask.php:21`
- Model cast: `app/Models/MaintenanceTask.php:65`
- Written by `RecordTaskCompletion`: `app/Actions/RecordTaskCompletion.php:30`

**No additional queries or eager-loading required.** Every task object fetched by the Livewire computed properties already has `last_completed_at`. Display is as simple as:

```blade
@if($task->last_completed_at)
  · Last done {{ $task->last_completed_at->diffForHumans() }}
@else
  · Never done
@endif
```

The `serviceRecords()` relationship (`HasMany` to `service_records` table with `completed_at` column) is available for audit history but is not needed for displaying "last executed."

### Shared Component Opportunity

No shared card component exists today — the finding was confirmed both by codebase exploration and in the archived `dashboard-this-month-section/research.md` ("No shared task-card Blade component"). The identical visual pattern is replicated:
- 5 times in `show.blade.php` (one per section)
- 4-5 times in `dashboard.blade.php` (one per section)

A single anonymous Blade component at `resources/views/components/maintenance-task-card.blade.php` could absorb all repetition. Parameters needed to cover both contexts:

| Prop | Type | Purpose |
|---|---|---|
| `$task` | MaintenanceTask | The task model |
| `$color` | string | Status color key (red/yellow/blue/gray) — determines border and date text |
| `$showApplianceName` | bool | Prefix with `$task->appliance->name` (dashboard only) |
| `$showEditDelete` | bool | Show Edit and Delete buttons (appliance detail only) |
| `$showDraftBadge` | bool | Show amber Draft badge (appliance detail only) |
| `$showDescription` | bool | Show description text (appliance detail only) |
| `$showMarkDone` | bool | Show Mark done button (false for metric tasks) |
| `$onMarkDone` | string | Wire action string for mark done |
| `$onEdit` | string | Wire action string for edit (appliance detail only) |
| `$onDelete` | string | Wire action string for delete (appliance detail only) |

However, Edit/Delete wire actions (`wire:click="startEdit($task->id)"`, `wire:click="confirmDelete($task->id)"`) are component-specific method calls — a shared Blade component would need to either accept them as raw strings/slots, or the page would pass pre-composed strings. The cleanest Blade approach is a `@props` anonymous component that accepts a `$task` and boolean flags, with slots for the action buttons.

### Proposed New Card Layout (target)

After the redesign:

```
┌──────────────────────────────────────────────────┐
│ Task name  [Draft]                               │  ← header (left only)
│   Description text if present                    │
│                                                  │
│ Every 3 months · Last done 2 months ago          │  ← interval + last-done row
│                                                  │
│ Due Feb 5, 2026    [Mark done]  [Edit] [Delete]  │  ← footer row (due + buttons right)
└──────────────────────────────────────────────────┘
```

On dashboard (no Edit/Delete, with appliance name):

```
┌──────────────────────────────────────────────────┐
│ Boiler — Annual service                          │
│ Due Feb 5, 2026                                  │
│ Every 12 months · Last done 8 months ago         │
│                                                  [Mark done] │
└──────────────────────────────────────────────────┘
```

## Code References

- `resources/views/livewire/pages/appliances/show.blade.php:308-330` — Overdue section card (read view)
- `resources/views/livewire/pages/appliances/show.blade.php:338-375` — Due this week card
- `resources/views/livewire/pages/appliances/show.blade.php:378-415` — This month card
- `resources/views/livewire/pages/appliances/show.blade.php:418-455` — Upcoming card
- `resources/views/livewire/pages/appliances/show.blade.php:458-490` — Manual tracking card
- `resources/views/livewire/pages/appliances/_edit-form.blade.php` — Inline edit form partial
- `resources/views/livewire/pages/dashboard.blade.php:94-214` — Dashboard section cards
- `app/Models/MaintenanceTask.php:21,65` — `last_completed_at` fillable + cast
- `app/Actions/RecordTaskCompletion.php:30` — writes `last_completed_at`
- `database/migrations/2026_06_01_000005_create_maintenance_tasks_table.php:21` — column definition

## Architecture Insights

1. **Volt inline components**: Both `show.blade.php` and `dashboard.blade.php` are Volt single-file components with PHP class at the top and Blade template below. Anonymous Blade components (`resources/views/components/`) are the natural extraction point — they don't need Livewire wiring themselves, they're just view helpers.

2. **Wire actions can't be parameterized cleanly as props**: `wire:click` is a Livewire directive that must reference the *parent component's* method. A shared Blade component can render the button but must receive the action descriptor from the parent — either as a string (`wire:click="{{ $onEdit }}"`) or as a named slot containing the button HTML. The slot approach is cleaner.

3. **Edit form is already a partial**: `_edit-form.blade.php` exists as a `@include`'d partial. It is not an anonymous component. It references `$task` and relies on parent Livewire properties (`$editName`, etc.). This can stay as-is.

4. **Color token passing**: Color varies per section (red/yellow/blue/gray). The parent section loop knows the color; the card component needs it. A simple `$color` string prop (`'red'`, `'yellow'`, `'blue'`, `'gray'`) and Tailwind class composition inside the component is the right pattern — but note that Tailwind's JIT purges class names not found as complete strings. All color variants must be present in the template as full class strings (e.g. `border-red-200`, `border-yellow-200`) not assembled via string interpolation.

5. **Dashboard vs appliance detail scope**: Dashboard uses the `calendar()` scope (`is_confirmed = true` only). Appliance detail uses raw `whereIn('interval_unit', ...)` to show drafts too. This difference is query-side, not card-side, so the shared card component doesn't need to know about it — the draft badge visibility is controlled by the `$showDraftBadge` prop.

6. **Metric tasks**: The Manual tracking section on appliance detail has no "Mark done" button but keeps Edit/Delete. Dashboard hides metric tasks entirely. The `$showMarkDone` prop handles this.

## Historical Context (from prior changes)

- `context/archive/2026-06-06-appliance-detail-page/plan.md` — Introduced the 5-section structure, Edit/Delete/Mark done buttons, inline edit form. Explicitly matched dashboard color contract. No shared component was planned.
- `context/archive/2026-06-06-dashboard-this-month-section/research.md:45-46` — Explicitly noted: "No shared task-card Blade component (no extraction of duplicate card markup)." The duplication was known and accepted at that point.
- `context/archive/2026-06-06-dashboard-this-month-section/plan.md` — Introduced the "This month" section and recurrence labels (`Str::plural()`). Section sort applies within sections, not globally.
- `context/archive/2026-06-06-dashboard-styling/change.md` — Restored color and spacing after a regression. Confirms colors are load-bearing for UX, not cosmetic.

## Related Research

- `context/archive/2026-06-06-appliance-detail-page/` — full change archive for appliance detail build
- `context/archive/2026-06-06-dashboard-this-month-section/` — full change archive for dashboard sections

## Open Questions

1. **Slot vs prop for wire actions**: Should the shared component accept action-button content via named slots (parent renders the buttons, passes them in), or accept wire-action strings as props and compose them internally? Slots are cleaner but more verbose at call sites. Strings are compact but mean the component knows Livewire method names.

2. **Dashboard layout difference**: The dashboard card is currently horizontal (`items-center`, one-liner) while appliance detail is vertical (`items-start`, stacked). After the redesign (Edit/Delete at bottom, last-done line added), the layouts converge more — but the dashboard still doesn't show Edit/Delete or description. Is the goal to make them visually identical, or just share the card's internal structure while allowing different padding/axis at the call site?

3. **"Never done" copy**: When `last_completed_at` is null, what should the card show? Options: nothing (omit the line), "Never done", or "Last done: —". Needs UX decision.

4. **Human-readable interval**: Currently "Every 3 months" uses raw `interval_unit`. After this change, should "last done 2 months ago" use Carbon's `diffForHumans()` (e.g. "2 months ago") or a formatted date (e.g. "Mar 5, 2025")? `diffForHumans()` is more conversational; formatted date is more precise.

# Service Cards Styling — Plan Brief

> Full plan: `context/changes/service-cards-styling/plan.md`
> Research: `context/changes/service-cards-styling/research.md`

## What & Why

Move the Edit/Delete buttons on maintenance task cards from the upper-right corner to the bottom-right footer, add "last done X ago" info after the "Every X months" interval line, and unify the appliance detail and dashboard card markup into a single shared Blade component (DRY). The cards in both views have been structurally identical but separately maintained since the appliance-detail-page feature; this change closes that duplication.

## Starting Point

Both `show.blade.php` (5 sections × inline card block) and `dashboard.blade.php` (4 sections × inline card block) duplicate the same card HTML. The `last_completed_at` datetime field is already on every `MaintenanceTask` record and fully loaded in all existing queries — no schema or query changes are needed.

## Desired End State

A single anonymous Blade component (`resources/views/components/maintenance-task-card.blade.php`) renders all task cards across both pages. Cards show a vertical layout with the task name at top, optional description, an interval + last-done meta row, and a footer with the due date on the left and action buttons on the right. Dashboard cards look the same but show the appliance name prefix and only the Mark done button.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Dashboard layout convergence | Converge to vertical (matching appliance detail) | True visual parity; the shared component then has one layout with optional fields, not two layout modes | Plan |
| "Never done" copy | Show `· Never done` when `last_completed_at` is null | Explicit signal to users that the task has never been recorded as complete | Plan |
| Last-done date format | `diffForHumans()` with formatted date in `title` attribute | Relative text is scannable; tooltip gives precision without cluttering the card | Plan |
| Wire action API | Named `$actions` slot | Component stays wire-agnostic; caller owns button HTML and `wire:click` semantics | Plan |
| Tailwind color safety | PHP lookup map with complete class literal strings | JIT scanner can't find dynamically assembled class names; lookup map is the standard safe pattern | Research |

## Scope

**In scope:**
- New `maintenance-task-card` anonymous Blade component
- Refactor of 5 card blocks in `show.blade.php`
- Refactor of 4-5 card blocks in `dashboard.blade.php`
- "Last done" display on all cards (both pages)
- Edit/Delete button relocation to footer row (appliance detail only)

**Out of scope:**
- Edit/Delete buttons on dashboard
- Inline edit form (`_edit-form.blade.php`) — unchanged
- Database schema, migrations, or query changes
- Section sort logic, section counts, or section headers
- `RecordTaskCompletion` action

## Architecture / Approach

An anonymous Blade component (`@props` + named slot) wraps all card HTML. Color-variant classes are resolved inside the component via a PHP array lookup (all class strings appear as literals for Tailwind JIT safety). `wire:click` buttons live in a named `$actions` slot so the component never touches Livewire method names — the parent Volt component passes them in. No Livewire component class changes required.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Create component | `maintenance-task-card.blade.php` with correct layout, color map, last-done display, and `$actions` slot | Getting the Tailwind color map wrong causes invisible styling regressions |
| 2. Refactor show.blade.php | 5 card blocks replaced; appliance detail has correct new layout | Metric task (manual tracking) section may need minor bespoke handling for `next_due_at_value` |
| 3. Refactor dashboard.blade.php | 4+ card blocks replaced; dashboard converges to vertical layout | Mark done wire:click must be preserved exactly; layout change will be visible to users |

**Prerequisites:** None — everything needed is already in the codebase.
**Estimated effort:** ~1 session across 3 phases (mostly mechanical substitution after the component is right)

## Open Risks & Assumptions

- The metric section (manual tracking) in `show.blade.php` (lines 458-490) may display `next_due_at_value` data that doesn't map to any component prop. Implementer should inspect this section before writing the Phase 2 metric call site and include any metric reading in the actions slot if needed.
- Dashboard tests may be view-level or feature-level — if they assert on the old horizontal card HTML structure, they'll need updating in Phase 3.

## Success Criteria (Summary)

- Edit/Delete buttons appear in the bottom-right footer on appliance detail, not the upper right
- "Every X months · Last done X ago" (or "· Never done") appears on every card on both pages
- `php artisan test` and `composer phpstan` pass after each phase

# Service Cards Styling — Implementation Plan

## Overview

Redesign maintenance task cards across the appliance detail page and dashboard: move Edit/Delete buttons to the bottom-right footer, add "last done" info after the interval line, and eliminate markup duplication by extracting a single shared anonymous Blade component that both pages use.

## Current State Analysis

Both `resources/views/livewire/pages/appliances/show.blade.php` and `resources/views/livewire/pages/dashboard.blade.php` contain inline task card markup — identical in structure, different in which fields and buttons appear. The card template is repeated 5 times in `show.blade.php` (once per urgency section) and 4-5 times in `dashboard.blade.php`. No shared component exists; the duplication was an explicit decision deferred in `dashboard-this-month-section`.

Current `show.blade.php` card layout:
- Header row (`flex justify-between items-start`): task name + draft badge LEFT; **due date + Mark done + Edit + Delete RIGHT** (buttons upper-right — the thing to fix)
- Body: optional description
- Footer: "Every X months" interval text only

Target layout (both pages after this change):
- Header: task name (+ optional appliance name prefix, + optional draft badge)
- Body: optional description
- Meta row: "Every X months · Last done 3 months ago" (or "· Never done")
- Footer row: "Due [date]" LEFT | action buttons RIGHT ← buttons move here

`$task->last_completed_at` (nullable Carbon datetime) is already fetched with every task query. No schema or query changes needed.

## Desired End State

A single `resources/views/components/maintenance-task-card.blade.php` anonymous component renders every task card across both pages. Cards on the appliance detail page show name, optional description, interval + last-done line, and a footer with due date left and Mark done / Edit / Delete right. Dashboard cards show the same vertical layout with appliance name prefix and only the Mark done button. The edit-mode card (inline edit form) is unchanged.

### Key Discoveries

- `$task->last_completed_at` — `app/Models/MaintenanceTask.php:21,65` — fillable + datetime cast; already loaded on every task, no eager-load change needed
- Edit/Delete button locations in `show.blade.php`: lines 322-323, 362-363, 402-403, 442-443, 477-478
- "Every X months" locations in `show.blade.php`: lines 329, 369, 409, 449, 484
- Dashboard card markup: `dashboard.blade.php:109-118` repeating per section
- `_edit-form.blade.php` — existing `@include`'d partial for inline editing; stays completely unchanged
- Tailwind JIT purges dynamically composed class names — all color class variants must appear as complete string literals inside the component

## What We're NOT Doing

- Not adding Edit or Delete buttons to the dashboard
- Not changing how tasks are queried or eager-loaded
- Not modifying the inline edit form (`_edit-form.blade.php`)
- Not changing section sort logic or section structure
- Not altering any database schema or migrations
- Not changing the `RecordTaskCompletion` action

## Implementation Approach

Three sequential phases: create the component, refactor the appliance detail, refactor the dashboard. Each phase is independently testable. The shared component is pure Blade — no Livewire wiring — with a named `$actions` slot so each calling Livewire component retains full ownership of its `wire:click` method references.

## Critical Implementation Details

**Tailwind JIT color safety**: The component accepts `$color` as a string prop (`'red'`, `'yellow'`, `'blue'`, `'gray'`). All class strings containing the color token must appear as complete string literals in the component file — never assembled via interpolation (`"border-{{ $color }}-200"` is invisible to the JIT scanner and will be purged). Use a PHP lookup map at the top of the component:

```php
@php
$colorClasses = [
    'red'    => ['border' => 'border-red-200',    'date' => 'text-red-600'],
    'yellow' => ['border' => 'border-yellow-200', 'date' => 'text-yellow-600'],
    'blue'   => ['border' => 'border-blue-200',   'date' => 'text-blue-600'],
    'gray'   => ['border' => 'border-gray-200',   'date' => 'text-gray-500'],
];
$c = $colorClasses[$color] ?? $colorClasses['gray'];
@endphp
```

**Named slot for actions**: `wire:click` directives must reference the *parent Livewire component's* methods. The `maintenance-task-card` component has no Livewire context of its own. Buttons belong in a named `$actions` slot rendered by the caller. Reference it in the component via `{{ $actions }}`. If no slot is passed, Blade renders it as an empty string (safe — no isset guard needed).

---

## Phase 1: Create maintenance-task-card anonymous Blade component

### Overview

Create the new shared card component in isolation. This phase produces the canonical card layout: header (name + optional badges), optional description, meta row (interval + last-done), footer row (due date left + actions slot right).

### Changes Required

#### 1. New anonymous Blade component

**File**: `resources/views/components/maintenance-task-card.blade.php`

**Intent**: Define the card layout shared by both appliance detail and dashboard. Accept `$task`, `$color`, and three boolean display flags via `@props`. Render the card with the new vertical layout, including the named `$actions` slot in the footer.

**Contract**:

Props declaration:
```php
@props([
    'task',
    'color' => 'gray',
    'showDraftBadge'    => false,
    'showDescription'   => false,
    'showApplianceName' => false,
])
```

- `$color` → `'red' | 'yellow' | 'blue' | 'gray'`; maps to border/date-text classes via the lookup map above
- `$showApplianceName` → when true, render title as `{{ $task->appliance->name }} — {{ $task->name }}`
- `$showDraftBadge` → when true, show amber "Draft" badge when `!$task->is_confirmed`
- `$showDescription` → when true, render `$task->description` below the header when non-null
- Last-done meta: `@if($task->last_completed_at)` → render `<span title="{{ $task->last_completed_at->format('M j, Y') }}">Last done {{ $task->last_completed_at->diffForHumans() }}</span>`; `@else` → render the string `Never done`
- Footer due date: render only when `$task->next_due_at` is non-null (metric tasks have null `next_due_at`); when null, render an empty spacer `<span></span>` to keep the actions right-aligned
- `{{ $actions }}` → named slot; renders action buttons passed by the caller

### Success Criteria

#### Automated Verification

- PHPStan passes: `composer phpstan`

#### Manual Verification

- Component file exists at `resources/views/components/maintenance-task-card.blade.php`
- Temporarily use the component for one card in the overdue section of `show.blade.php` and visually confirm: title, description, interval + last-done line (with tooltip on hover), and action buttons all render in the correct positions

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to Phase 2.

---

## Phase 2: Refactor show.blade.php to use the component

### Overview

Replace all 5 repeated card blocks in `show.blade.php` with `<x-maintenance-task-card>` invocations. The edit form block (`@if($editingTaskId === $task->id)` → `@include('.../_edit-form')`) is not a card and stays completely unchanged.

### Changes Required

#### 1. Overdue section card block (show.blade.php:308-330)

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Replace the inline overdue card div with the component. The due date span currently in the upper-right row is now rendered inside the component's footer by the component itself — remove it from the actions slot.

**Contract**:
```blade
<x-maintenance-task-card
    :task="$task"
    color="red"
    :showDraftBadge="true"
    :showDescription="true"
>
    <x-slot:actions>
        {{-- preserve existing Mark done, Edit, Delete button HTML/wire:click/styling verbatim --}}
    </x-slot:actions>
</x-maintenance-task-card>
```

#### 2. Due this week section card block (show.blade.php:338-375)

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Same substitution as overdue section. Pass `color="yellow"`.

#### 3. This month section card block (show.blade.php:378-415)

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Same substitution. Pass `color="blue"`.

#### 4. Upcoming section card block (show.blade.php:418-455)

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Same substitution. Pass `color="gray"`.

#### 5. Manual tracking section card block (show.blade.php:458-490)

**File**: `resources/views/livewire/pages/appliances/show.blade.php`

**Intent**: Metric tasks — `color="gray"`, `showDraftBadge=true`, `showDescription=true`. The actions slot carries Edit and Delete only (no Mark done). `$task->next_due_at` is null — the component renders no due date in the footer, which is correct.

**Contract**: Inspect lines 458-490 before writing this call site. The current metric section may display `next_due_at_value` or a metric reading (km/hours) that the component doesn't know about. If so, include that display text in the actions slot alongside the Edit/Delete buttons.

### Success Criteria

#### Automated Verification

- PHPStan passes: `composer phpstan`
- Full test suite passes: `php artisan test`

#### Manual Verification

- All 5 sections display cards with the new layout
- Edit/Delete buttons appear in the **bottom-right footer row**, not the upper right
- "Every X months · Last done X ago" renders correctly; hovering the "Last done" text shows the formatted date as a tooltip
- Tasks with `last_completed_at = null` show "· Never done"
- Draft badge appears on unconfirmed tasks
- Description renders when present
- Inline edit form still opens and saves correctly (Edit button → form → Save/Cancel)
- Mark done works and `next_due_at` advances correctly
- Sort controls still apply within each section

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to Phase 3.

---

## Phase 3: Refactor dashboard.blade.php to use the component

### Overview

Replace dashboard card markup with the same component. Dashboard cards converge to the vertical layout, gain the "last done" line, and lose nothing visible (they had no Edit/Delete, description, or draft badge).

### Changes Required

#### 1. Dashboard section card blocks (dashboard.blade.php ~lines 109-214)

**File**: `resources/views/livewire/pages/dashboard.blade.php`

**Intent**: Replace all dashboard card divs (overdue/red, this week/yellow, this month/blue, upcoming/gray) with `<x-maintenance-task-card>`. Pass `showApplianceName=true`. The actions slot carries only the Mark done button.

**Contract**:
```blade
<x-maintenance-task-card
    :task="$task"
    color="red"
    :showApplianceName="true"
>
    <x-slot:actions>
        {{-- preserve existing Mark done button HTML/wire:click/styling verbatim --}}
    </x-slot:actions>
</x-maintenance-task-card>
```

The due date was previously in the left info stack; it now moves into the component's footer row — remove it from the call site. The outer `flex justify-between items-center` wrapper div is replaced by the component's own container — remove it. Color string literals per section: `"red"`, `"yellow"`, `"blue"`, `"gray"`.

### Success Criteria

#### Automated Verification

- PHPStan passes: `composer phpstan`
- Full test suite passes: `php artisan test`

#### Manual Verification

- Dashboard shows cards in a vertical stack layout (not the previous horizontal strip)
- Appliance name prefix renders correctly (`Boiler — Annual service`)
- "Last done X ago" appears with formatted-date tooltip; tasks never done show "· Never done"
- Mark done still works from the dashboard and refreshes the section
- No visual regression in section headers, counts, or page spacing

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful.

---

## Testing Strategy

### Automated Tests

- PHPStan at each phase: `composer phpstan` (always via composer — never `./vendor/bin/phpstan analyse` directly)
- Full suite at phases 2 and 3: `php artisan test`

### Manual Testing Steps

1. Visit an appliance detail page that has tasks in multiple urgency sections
2. Confirm Edit/Delete buttons are in the bottom footer row, not upper right
3. Confirm "Every X months · Last done X ago" appears; hover the text to see the formatted date tooltip
4. Find a task with no completion history — confirm "· Never done" displays
5. Open the inline edit form on a task — verify it still works, saves, and auto-closes
6. Mark a task done — verify the card moves to the correct section and `next_due_at` advances
7. Visit the dashboard — confirm vertical card layout, appliance name prefix, last-done info
8. Mark a task done from the dashboard — verify it works and the section refreshes

## References

- Research: `context/changes/service-cards-styling/research.md`
- Prior card layout history: `context/archive/2026-06-06-appliance-detail-page/plan.md`
- Prior DRY decision deferred: `context/archive/2026-06-06-dashboard-this-month-section/research.md:45-46`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Create maintenance-task-card anonymous Blade component

#### Automated

- [x] 1.1 PHPStan passes: `composer phpstan` — a718d5e

#### Manual

- [ ] 1.2 Component file exists and a test card renders with the correct layout visually

### Phase 2: Refactor show.blade.php to use the component

#### Automated

- [x] 2.1 PHPStan passes: `composer phpstan` — 2c60218
- [x] 2.2 Full test suite passes: `php artisan test` — 2c60218

#### Manual

- [ ] 2.3 All 5 sections display cards with the new layout
- [ ] 2.4 Edit/Delete buttons appear in the bottom-right footer row
- [ ] 2.5 "Last done X ago" renders after the interval line; tooltip shows formatted date on hover
- [ ] 2.6 Tasks with null last_completed_at show "· Never done"
- [ ] 2.7 Inline edit form still opens and saves correctly
- [ ] 2.8 Mark done still works and advances next_due_at
- [ ] 2.9 Sort controls still apply within each section

### Phase 3: Refactor dashboard.blade.php to use the component

#### Automated

- [x] 3.1 PHPStan passes: `composer phpstan`
- [x] 3.2 Full test suite passes: `php artisan test`

#### Manual

- [ ] 3.3 Dashboard cards render in vertical stack layout
- [ ] 3.4 Appliance name prefix renders correctly
- [ ] 3.5 "Last done X ago" and "· Never done" render correctly
- [ ] 3.6 Mark done works from the dashboard

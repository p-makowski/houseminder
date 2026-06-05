# Appliance CRUD Implementation Plan

## Overview

Implement the three missing CRUD operations for appliances: index (list), edit, and delete. The creation wizard (S-01) and show page already exist. All new code follows the established Volt-only, inline-authorization, household-scoped pattern.

## Current State Analysis

The app has `appliances.create` (4-step wizard) and `appliances.show` (read-only). No list, edit, or delete exists. All existing appliance code is Livewire Volt full-page components. Household isolation is enforced with inline `abort_if()` in each component's `mount()` — no policies, no custom middleware. The `x-modal` Alpine.js component and all form components (`x-text-input`, `x-input-label`, `x-input-error`, `x-primary-button`, `x-secondary-button`, `x-danger-button`) are already in place and used throughout the app.

## Desired End State

A logged-in household can open "My Appliances" from the nav, see all their appliances sorted by urgency (most overdue first), click through to edit any appliance's name/model/type/purchase date, and delete an appliance with a confirmation modal that shows the appliance name and task count. The existing create wizard and show page are unchanged.

### Key Discoveries

- `resources/views/livewire/pages/appliances/create.blade.php` — contains the two-tier type combobox, type creation logic, and `confirm()` transaction; edit reuses the combobox pattern
- `resources/views/livewire/layout/navigation.blade.php:35–40` — existing `x-nav-link` pattern to follow for the new "My Appliances" link
- `resources/views/livewire/profile/delete-user-form.blade.php` — the exact delete modal + `x-danger-button` + `$dispatch('open-modal', …)` pattern to follow
- `tests/Feature/Appliances/ApplianceTestCase.php` — base test class with household setup; all new test files extend this
- `database/migrations/2026_06_01_000004_create_appliances_table.php:appliance_type_id` — `restrictOnDelete` FK means deleting a type used by appliances will fail at DB level; appliance deletion itself cascades to maintenance_tasks and service_records
- Lesson: `model` field is required in all write paths — edit form must validate it
- Lesson: ApplianceType list must use the two-tier query — `ApplianceType::whereNull('household_id')->orWhere('household_id', $householdId)` — never `$household->applianceTypes()`

## What We're NOT Doing

- No re-generation of AI maintenance suggestions when editing an appliance
- No changes to existing maintenance tasks when the appliance type is changed (edit saves appliance fields only)
- No soft-delete — deletion is hard and permanent, matching PRD FR-009
- No per-type delete blocking in the UI (the DB `restrictOnDelete` FK on appliance_type_id handles it at the DB level)
- No sorting controls on the index (fixed sort: overdue-first, then due-soon, then alpha)
- No pagination on the index (household appliance counts are expected to remain small in v1)

## Implementation Approach

Three sequential phases, each independently testable. Phase 1 (index + nav) is self-contained. Phase 2 (edit) builds on the index by providing a target for the "Edit" link on each card. Phase 3 (delete) extends the edit component rather than adding a new file, keeping delete scoped to a single well-authorized context.

## Critical Implementation Details

**Index sort query**: `withCount` with scoped closures is the correct approach for sorting by overdue count while also loading due-soon and upcoming counts in a single query. The `whereNotNull('next_due_at')` filter is required — metric-unit tasks (`hours`, `km`) use `next_due_at_value` instead and must not appear in calendar-based counts (see `lessons.md` on `interval_unit` duality).

**Type-change warning**: Store `$originalTypeId` in `mount()` and compare to `$selectedTypeId` reactively. The notice is display-only — it does not block saving. This mirrors the PRD intent: edit updates appliance details; tasks are managed separately.

**Delete task count**: Load `$taskCount` once in `mount()` as `$appliance->maintenanceTasks()->count()`. Do not reload it on delete — the count is informational for the modal and does not need to be live.

---

## Phase 1: Appliance Index Page

### Overview

Creates the appliance list page — the navigation hub for all appliance CRUD — and adds the "My Appliances" nav link. Each card shows appliance name, type, and task status counts (overdue / due soon / upcoming) sorted by urgency.

### Changes Required

#### 1. New route — appliances.index

**File**: `routes/web.php`

**Intent**: Register the index route before the existing `appliances/{appliance}` route to avoid slug collision.

**Contract**: `Volt::route('appliances', 'pages.appliances.index')->middleware(['auth', 'verified'])->name('appliances.index')`

#### 2. New Volt component — index page

**File**: `resources/views/livewire/pages/appliances/index.blade.php`

**Intent**: List all household appliances with task status counts, sorted so the most-overdue appliance appears first. Show an empty state when none exist. Provide an "Add Appliance" button linking to `appliances.create`.

**Contract**: `mount()` fetches the household via `Auth::user()->households()->first()`, aborts 403 if none, then queries:

```php
Appliance::where('household_id', $household->id)
    ->with('applianceType')
    ->withCount([
        'maintenanceTasks as overdue_count' => fn($q) => $q->where('next_due_at', '<', now())->whereNotNull('next_due_at'),
        'maintenanceTasks as due_soon_count' => fn($q) => $q->whereBetween('next_due_at', [now(), now()->addDays(30)])->whereNotNull('next_due_at'),
        'maintenanceTasks as upcoming_count' => fn($q) => $q->where('next_due_at', '>', now()->addDays(30))->whereNotNull('next_due_at'),
    ])
    ->orderByDesc('overdue_count')
    ->orderByDesc('due_soon_count')
    ->orderBy('name')
    ->get()
```

Each card links to `appliances.show`. Uses `#[Layout('layouts.app')]`. The edit link is added in Phase 2 Change 3 once the route exists.

#### 3. Navigation — add "My Appliances" link

**File**: `resources/views/livewire/layout/navigation.blade.php`

**Intent**: Add a "My Appliances" nav link alongside the existing "Add Appliance" link, in both the desktop and mobile nav blocks.

**Contract**: Desktop block uses `x-nav-link` (follow lines 35–40); mobile block uses `x-responsive-nav-link` (follow line 92). Both use `:active="request()->routeIs('appliances.index')"` and `wire:navigate`.

#### 4. New test file — index

**File**: `tests/Feature/Appliances/ApplianceIndexTest.php`

**Intent**: Verify the index loads appliances for the authenticated household and rejects access to a different household's appliances with 403.

**Contract**: Extends `ApplianceTestCase`. Two tests: (1) authenticated user sees their appliance names on the index page; (2) a user authenticated as a second household gets HTTP 200 but sees only their own appliances — none of the first household's appliances appear in the response.

### Success Criteria

#### Automated Verification

- Feature tests pass: `php artisan test --filter=ApplianceIndexTest`

#### Manual Verification

- "My Appliances" nav link appears on desktop and mobile
- Index page loads and shows all household appliances
- Cards display name, type, and task counts (overdue / due soon / upcoming)
- Appliances with overdue tasks appear before those without
- Empty state renders when no appliances exist
- "Add Appliance" button links to the create wizard
- Each card's "Edit" link navigates to the edit page (Phase 2 — can verify link target now; full test after Phase 2)

**Implementation Note**: After Phase 1 automated tests pass, verify the manual criteria above before proceeding to Phase 2.

---

## Phase 2: Appliance Edit Page

### Overview

Creates the edit form for updating an appliance's name, model, purchase date, and type. Shows an inline notice if the user changes the type (tasks are not affected). Redirects to `appliances.show` on success.

### Changes Required

#### 1. New route — appliances.edit

**File**: `routes/web.php`

**Intent**: Register the edit route. Must be placed before the bare `appliances/{appliance}` show route.

**Contract**: `Volt::route('appliances/{appliance}/edit', 'pages.appliances.edit')->middleware(['auth', 'verified'])->name('appliances.edit')`

#### 2. New Volt component — edit page

**File**: `resources/views/livewire/pages/appliances/edit.blade.php`

**Intent**: Pre-fill a form with the appliance's current values. Allow editing name, model, purchase date, and type. Show an inline notice when the selected type differs from the original. Save updates the appliance record only — no task modifications. Redirect to `appliances.show` on success.

**Contract**:
- `mount(Appliance $appliance)`: verify ownership (`abort_if($appliance->household_id !== $household->id, 403)`), populate `$name`, `$model`, `$purchaseDate`, `$typeSearch`, `$selectedTypeId`, store `$originalTypeId = $appliance->appliance_type_id`, load `$allTypes` using the two-tier query.
- Type combobox: same `selectType(int $id, string $name)` + Alpine.js combobox pattern as `create.blade.php`. `$typeSearch` drives the visible input; `$selectedTypeId` is the resolved FK.
- Type-change notice: rendered when `$selectedTypeId !== $originalTypeId` (both non-null). Display-only, does not block saving.
- `save()`: validate `name` required/string/max:255, `model` required/string/max:255, `purchaseDate` nullable/date, `typeSearch` required/string/max:255. Resolve or create the ApplianceType (same create-or-find logic as `create.blade.php:confirm()`). Update the appliance. Redirect to `route('appliances.show', $appliance)` with `navigate: true`.

#### 3. Wire edit links from index

**File**: `resources/views/livewire/pages/appliances/index.blade.php`

**Intent**: Ensure the "Edit" link on each index card points to `appliances.edit`.

**Contract**: `route('appliances.edit', $appliance)` with `wire:navigate` on each card action link.

#### 4. New test file — edit

**File**: `tests/Feature/Appliances/ApplianceEditTest.php`

**Intent**: Verify the edit form saves updated fields correctly and rejects cross-household access.

**Contract**: Extends `ApplianceTestCase`. Two tests: (1) authenticated user can update an appliance's name and model and the DB record reflects the change; (2) attempting to edit an appliance belonging to a different household returns 403.

### Success Criteria

#### Automated Verification

- Feature tests pass: `php artisan test --filter=ApplianceEditTest`

#### Manual Verification

- Edit page loads pre-filled with current appliance values
- All fields are editable; `model` is required (blank submit shows validation error)
- Type combobox works — system and custom types appear; selecting a different type shows the inline notice
- Saving with valid data updates the record and redirects to the show page
- Cancelling (browser back / nav link) does not persist changes

**Implementation Note**: After Phase 2 automated tests pass, verify the manual criteria above before proceeding to Phase 3.

---

## Phase 3: Appliance Delete

### Overview

Adds a delete action to the edit page, guarded by an Alpine.js confirmation modal showing the appliance name and task count. Deletion is hard and immediate; the user is redirected to the index afterwards.

### Changes Required

#### 1. Delete method and modal — edit page

**File**: `resources/views/livewire/pages/appliances/edit.blade.php`

**Intent**: Allow the user to permanently delete the appliance from the edit page, after confirming in a modal that names the appliance and states how many maintenance tasks (and their service history) will be lost.

**Contract**:
- Add `$taskCount` property, set in `mount()` as `$appliance->maintenanceTasks()->count()`.
- Add `delete()` method: re-verify household ownership (`abort_if`), call `$appliance->delete()`, redirect to `route('appliances.index')` with `navigate: true`.
- Blade: add a `x-danger-button` in the edit page header/footer that dispatches `$dispatch('open-modal', 'confirm-appliance-delete')`. The `x-modal` component contains a confirmation message — "Delete [name]? This will permanently delete [N] maintenance task(s) and all service history. This action cannot be undone." — with a cancel (`x-secondary-button`) and confirm (`x-danger-button` with `wire:submit` or `wire:click="delete"`).
- Modal pattern from `resources/views/livewire/profile/delete-user-form.blade.php`.

#### 2. New test file — delete

**File**: `tests/Feature/Appliances/ApplianceDeleteTest.php`

**Intent**: Verify that an appliance can be deleted by its household owner and that cross-household deletion is blocked.

**Contract**: Extends `ApplianceTestCase`. Two tests: (1) calling the delete action removes the appliance record from the DB and its maintenance tasks (cascade verified); (2) calling delete on an appliance from a different household returns 403 and leaves the record intact.

### Success Criteria

#### Automated Verification

- Feature tests pass: `php artisan test --filter=ApplianceDeleteTest`

#### Manual Verification

- Delete button is visible on the edit page
- Clicking delete opens the modal showing the correct appliance name and task count
- Cancelling the modal leaves the appliance unchanged
- Confirming deletes the appliance and redirects to the index
- Deleted appliance no longer appears on the index
- Navigating directly to the deleted appliance's show/edit URL returns a 404 or 403

**Implementation Note**: After Phase 3 automated tests pass, verify the manual criteria above. Full CRUD is complete when all three phases pass both automated and manual verification.

---

## Testing Strategy

### Feature Tests

All tests extend `tests/Feature/Appliances/ApplianceTestCase.php` which handles user + household setup and authentication.

- `ApplianceIndexTest` — index loads, appliances visible, cross-household isolation
- `ApplianceEditTest` — edit saves, cross-household 403
- `ApplianceDeleteTest` — delete removes record + cascade, cross-household 403

### Manual Testing Sequence

1. Add 2–3 appliances with the existing create wizard
2. Open "My Appliances" — verify cards, counts, sort order
3. Edit one appliance — change name, model, and type; verify notice appears; save and check show page
4. Delete one appliance — verify modal, confirm, verify redirect and index update
5. Attempt to navigate to the deleted appliance's URL — verify 404/403

## References

- Research: `context/changes/appliance-crud/research.md`
- S-01 wizard (type combobox + confirm pattern): `resources/views/livewire/pages/appliances/create.blade.php`
- Delete modal pattern: `resources/views/livewire/profile/delete-user-form.blade.php`
- Modal component: `resources/views/components/modal.blade.php`
- Base test class: `tests/Feature/Appliances/ApplianceTestCase.php`
- Lessons: `context/foundation/lessons.md`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Appliance Index Page

#### Automated

- [ ] 1.1 Feature tests pass: `php artisan test --filter=ApplianceIndexTest`

#### Manual

- [ ] 1.2 "My Appliances" nav link appears on desktop and mobile
- [ ] 1.3 Index page loads and shows all household appliances
- [ ] 1.4 Cards display name, type, and task counts (overdue / due soon / upcoming)
- [ ] 1.5 Appliances with overdue tasks appear before those without
- [ ] 1.6 Empty state renders when no appliances exist
- [ ] 1.7 "Add Appliance" button links to the create wizard

### Phase 2: Appliance Edit Page

#### Automated

- [ ] 2.1 Feature tests pass: `php artisan test --filter=ApplianceEditTest`

#### Manual

- [ ] 2.2 Edit page loads pre-filled with current appliance values
- [ ] 2.3 `model` field required — blank submit shows validation error
- [ ] 2.4 Changing type shows inline notice; saving updates record and redirects to show page
- [ ] 2.5 Type combobox shows system and custom types; selecting a different type shows the inline notice
- [ ] 2.6 Cancelling (browser back / nav link) does not persist changes

### Phase 3: Appliance Delete

#### Automated

- [ ] 3.1 Feature tests pass: `php artisan test --filter=ApplianceDeleteTest`

#### Manual

- [ ] 3.2 Delete button visible on edit page
- [ ] 3.3 Modal shows correct appliance name and task count
- [ ] 3.4 Cancel leaves appliance unchanged; confirm deletes and redirects to index
- [ ] 3.5 Deleted appliance no longer appears on index
- [ ] 3.6 Navigating directly to the deleted appliance's show/edit URL returns a 404 or 403

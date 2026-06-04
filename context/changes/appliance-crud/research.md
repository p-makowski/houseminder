---
date: 2026-06-04T00:00:00+00:00
researcher: p-makowski
git_commit: bee2d781e96a0d7698b481a4810a1f2f27e5d0f0
branch: main
repository: houseminder
topic: "Appliance CRUD — existing code, patterns, UI conventions, and auth/isolation"
tags: [research, codebase, appliances, crud, livewire, volt, household-isolation]
status: complete
last_updated: 2026-06-04
last_updated_by: p-makowski
---

# Research: Appliance CRUD

**Date**: 2026-06-04
**Researcher**: p-makowski
**Git Commit**: bee2d781e96a0d7698b481a4810a1f2f27e5d0f0
**Branch**: main
**Repository**: houseminder

## Research Question

What currently exists for appliances, what's missing for full CRUD, and what patterns (routing, components, UI, auth) must new code follow to stay consistent?

## Summary

The app already has a full 4-step appliance creation wizard and a read-only show page. **Three operations are missing: list (index), edit, and delete.** All existing appliance code uses Livewire Volt full-page components — no traditional controllers. Household scoping is enforced inline via `abort_if()` in each component's `mount()` or action. Two critical lessons from `lessons.md` apply to every write path: the two-tier ApplianceType query and the required `model` field validation.

---

## Detailed Findings

### What Already Exists

| Operation | Route name | Component file |
|-----------|-----------|----------------|
| Create (4-step wizard) | `appliances.create` | `resources/views/livewire/pages/appliances/create.blade.php` |
| Show (read-only) | `appliances.show` | `resources/views/livewire/pages/appliances/show.blade.php` |
| **List** | ❌ missing | — |
| **Edit** | ❌ missing | — |
| **Delete** | ❌ missing | — |

### Data Model

**`app/Models/Appliance.php`**
- Fillable: `household_id`, `appliance_type_id`, `name`, `model`, `purchase_date`, `is_plan_confirmed`
- Casts: `purchase_date` → date, `is_plan_confirmed` → boolean
- Relationships: `household()` BelongsTo, `applianceType()` BelongsTo, `maintenanceTasks()` HasMany

**`app/Models/ApplianceType.php`**
- Fillable: `household_id` (nullable — null = system type), `name`
- Relationships: `household()` BelongsTo, `appliances()` HasMany
- 13 system types seeded (`household_id = null`): Refrigerator, Washing Machine, Dryer, Dishwasher, HVAC, Water Heater, Oven/Range, Microwave, Vacuum Cleaner, Car/Vehicle, Lawn Mower, Generator, Other

**`app/Models/Household.php`**
- Fillable: `name`
- Relevant relationships: `appliances()` HasMany, `applianceTypes()` HasMany
- ⚠️ `applianceTypes()` returns only household-scoped types — system types invisible here (see Lessons)

**`database/migrations/2026_06_01_000004_create_appliances_table.php`**
- `household_id` FK → households (cascadeOnDelete)
- `appliance_type_id` FK → appliance_types (restrictOnDelete — can't delete a type while appliances use it)
- `name` string NOT NULL, `model` string NOT NULL, `purchase_date` date nullable
- `is_plan_confirmed` boolean default false

### S-01 Create Wizard (existing)

`resources/views/livewire/pages/appliances/create.blade.php` — single Volt file, ~540 lines.

**Step 1** (lines 245–322): name, model, type combobox. Alpine.js combobox for type search. Validates all three as required/max:255.

**Step 2** (lines 326–432): AI suggestions from `app/Actions/GenerateMaintenancePlan.php` via Prism/Claude Sonnet 4.5. Editable task cards.

**Step 3** (lines 436–500): Optional service backdating per task.

**Step 4** (lines 504–537): Summary + confirm.

**`confirm()` method** (lines 153–241): DB transaction. Creates/finds ApplianceType, creates Appliance, creates MaintenanceTasks, creates ServiceRecords for backdates. Redirects to `appliances.show`.

### Show Page (existing)

`resources/views/livewire/pages/appliances/show.blade.php` (73 lines): loads appliance with applianceType + maintenanceTasks. Authorization in `mount()` via `abort_if(!$household || $appliance->household_id !== $household->id, 403)`.

---

## Routing & Component Conventions

**`routes/web.php`** — Volt routes exclusively:
```php
Volt::route('appliances/create', 'pages.appliances.create')
    ->middleware(['auth', 'verified'])
    ->name('appliances.create');

Volt::route('appliances/{appliance}', 'pages.appliances.show')
    ->middleware(['auth', 'verified'])
    ->name('appliances.show');
```

New routes to add:
- `appliances.index` → `pages.appliances.index`
- `appliances/{appliance}/edit` → `pages.appliances.edit`
- Delete handled as a Livewire action inside index or show (no separate route)

Component files live at: `resources/views/livewire/pages/appliances/{action}.blade.php`

All routes need `->middleware(['auth', 'verified'])`.

---

## Auth & Household Isolation

**No policies, no custom middleware.** Isolation is enforced component-by-component.

**The pattern** (used in create.blade.php:35, show.blade.php:16, RecordTaskCompletion.php:16):
```php
$household = Auth::user()->households()->first();
abort_if(!$household, 403);
// then check ownership
abort_if($appliance->household_id !== $household->id, 403);
```

**User → Household**: Many-to-many via `household_user` pivot with `role` column. `households()->first()` gets the active household.

**On create**: always set `household_id => $household->id`.

**For list queries**: scope to household — `Appliance::where('household_id', $household->id)->get()` or use `$household->appliances`.

**ApplianceType scoping** (lessons.md rule): never use `$household->applianceTypes()` alone. Always:
```php
ApplianceType::whereNull('household_id')->orWhere('household_id', $householdId)->orderBy('name')->get()
```

---

## UI & View Layer

**Layout**: `layouts/app.blade.php`. Pages use `#[Layout('layouts.app')]` attribute on Volt component and optionally `<x-slot name="header">` for the page title.

**Reusable form components** (all in `resources/views/components/`):

| Component | Usage |
|-----------|-------|
| `x-text-input` | `wire:model="field"`, `border-gray-300 focus:border-indigo-500 rounded-md shadow-sm` |
| `x-input-label` | `for="field" :value="__('Label')"` |
| `x-input-error` | `:messages="$errors->get('field')"` |
| `x-primary-button` | Main submit actions |
| `x-secondary-button` | Cancel / back actions |
| `x-danger-button` | Delete / destructive actions |
| `x-action-message` | Success toast — `on="event-name"` auto-fades after 2s |
| `x-modal` | Alpine.js modal, trigger via `$dispatch('open-modal', 'name')` |

**Delete confirmation pattern** (from `profile/delete-user-form.blade.php`):
```blade
<x-danger-button x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-appliance-delete')">
    Delete
</x-danger-button>
<x-modal name="confirm-appliance-delete" focusable>
    <form wire:submit="delete" class="p-6">
        <!-- confirmation text + cancel + confirm buttons -->
    </form>
</x-modal>
```

**Success feedback pattern**:
```php
$this->dispatch('appliance-saved');
```
```blade
<x-action-message on="appliance-saved">Saved.</x-action-message>
```

**Card/list item pattern** (from show.blade.php:48–72):
```blade
<div class="border border-gray-200 rounded-md p-4">
    <div class="flex justify-between items-start">
        <h3 class="font-medium text-gray-900">{{ $appliance->name }}</h3>
        <span class="text-xs text-gray-500">{{ $appliance->applianceType->name }}</span>
    </div>
</div>
```

**Tailwind palette**: indigo-500/600 for primary, gray-50–900 for neutrals, red-600 for danger, green-600 for success. Forms use `space-y-6`, max-width `max-w-2xl` for forms.

**Navigation** (`resources/views/livewire/layout/navigation.blade.php:35–40`): add new nav links using `x-nav-link` with `:active="request()->routeIs('appliances.*')"`.

---

## Code References

- `app/Models/Appliance.php` — full model, fillable, casts, relationships
- `app/Models/ApplianceType.php` — system vs household-scoped types
- `app/Models/Household.php` — household relationships
- `database/migrations/2026_06_01_000004_create_appliances_table.php` — schema
- `database/migrations/2026_06_01_000003_create_appliance_types_table.php` — ApplianceType schema
- `resources/views/livewire/pages/appliances/create.blade.php` — S-01 wizard (CREATE)
- `resources/views/livewire/pages/appliances/show.blade.php` — SHOW
- `app/Actions/GenerateMaintenancePlan.php` — AI plan generation
- `app/Actions/RecordTaskCompletion.php` — household auth pattern in an Action
- `resources/views/components/modal.blade.php` — Alpine.js modal
- `resources/views/livewire/profile/delete-user-form.blade.php` — delete confirmation pattern
- `routes/web.php` — current Volt routes
- `database/seeders/ApplianceTypeSeeder.php` — 13 seeded system types
- `database/factories/ApplianceFactory.php` — factory for tests
- `tests/Feature/Appliances/ApplianceTestCase.php` — base test class with household setup

---

## Architecture Insights

1. **Volt-only**: No traditional controllers for CRUD. Everything is a Livewire Volt full-page component. New appliance pages must follow this pattern.

2. **Inline authorization**: No policies directory. Every component/action checks `abort_if(!$household || $record->household_id !== $household->id, 403)` itself.

3. **ApplianceType `restrictOnDelete`**: The FK from `appliances` to `appliance_types` is `restrictOnDelete`. Deleting an appliance with tasks cascades to tasks (via `appliances` → `maintenance_tasks` cascade). Deleting a type used by any appliance will fail at the DB level — no special UI needed but worth knowing.

4. **`is_plan_confirmed` flag**: Appliances created via the wizard have `is_plan_confirmed = true`. The index/list view may want to show a "setup incomplete" badge for any appliance where this is false (unlikely in current flow, but possible via factory/seed).

5. **Edit complexity**: The edit form is simpler than create — no AI step, no backdating. Just name, model, purchase_date, and type. But type change needs the two-tier query pattern and must not break existing maintenance tasks.

---

## Lessons from `lessons.md` That Apply to CRUD

1. **Two-tier ApplianceType query** (lessons.md:6–10): any edit form showing a type picker must use `ApplianceType::whereNull('household_id')->orWhere('household_id', $householdId)`. Never `$household->applianceTypes()`.

2. **`model` field required on all write paths** (lessons.md:12–16): edit form must include `'model' => ['required', 'string', 'max:255']`.

3. **Validate user input before AI prompts** (lessons.md:18–23): not directly applicable to list/edit/delete, but relevant if edit ever re-triggers AI suggestions.

4. **`interval_unit` determines which `next_due` field is authoritative** (lessons.md:25–31): not directly applicable to appliance CRUD (no task writes), but relevant if edit cascades to task recalculation.

---

## Open Questions

1. **Edit → maintenance tasks**: Should editing appliance name/model/type trigger a re-generation of AI suggestions, or is editing tasks out of scope for this change?
2. **Delete cascade UX**: Deleting an appliance cascades to its maintenance tasks and service records. Should the delete confirmation modal show a count of tasks that will be deleted?
3. **Index page scope**: Should the index list only confirmed appliances (`is_plan_confirmed = true`) or all? Probably all, but worth confirming.
4. **Navigation change**: Should "Add Appliance" in the nav be replaced with "Appliances" pointing to the new index, with an "Add" button on the index page?

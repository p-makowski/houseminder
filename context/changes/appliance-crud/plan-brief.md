# Appliance CRUD — Plan Brief

> Full plan: `context/changes/appliance-crud/plan.md`
> Research: `context/changes/appliance-crud/research.md`

## What & Why

Add the three missing CRUD operations — list (index), edit, and delete — to complete appliance management. The creation wizard (S-01) and read-only show page already exist; this change makes appliances fully manageable without workarounds.

## Starting Point

`appliances.create` (4-step wizard) and `appliances.show` (read-only) are in place. All appliance code is Livewire Volt full-page components with inline household `abort_if()` authorization. No policies, no custom middleware exist.

## Desired End State

A logged-in household can open "My Appliances" from the nav, see all their appliances sorted by urgency (most-overdue first), click through to edit name/model/type/purchase date, and permanently delete an appliance after confirming in a modal that states the appliance name and task count.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Edit → maintenance tasks | Tasks untouched; show type-change notice | PRD FR-008 scopes edit to appliance details, not task management | Plan |
| Index card content | Name, type, task counts by status | Matches dashboard information density without duplicating it | Plan |
| Index sort order | Overdue count desc → due-soon desc → alpha | Surfaces actionable appliances first without user-configurable sorting | Plan |
| Delete location | Edit page only | Keeps delete in an already-authorized, already-loaded context | Plan |
| Delete confirmation | Modal with appliance name + task count | PRD FR-009 requires explicit confirmation; count shows data loss scope | Plan |
| Navigation | Keep "Add Appliance" + add "My Appliances" | No regression for direct-add users; index becomes the hub | Plan |
| Testing scope | Happy path + household 403 guard per operation | Covers the critical isolation requirement without over-engineering | Plan |
| ApplianceType query | Two-tier: `whereNull('household_id')->orWhere('household_id', $id)` | Lessons.md rule — `$household->applianceTypes()` misses all 13 system types | Research |
| `model` validation | `required` on edit form | Lessons.md rule — DB NOT NULL without model-layer guard yields raw DB exception | Research |

## Scope

**In scope:**
- `appliances.index` — list all household appliances with task status counts
- `appliances.edit` — edit name, model, purchase date, type with inline type-change notice
- Delete action on edit page with Alpine.js confirmation modal
- "My Appliances" nav link
- Feature tests (happy path + 403) for each operation

**Out of scope:**
- Re-generating AI maintenance suggestions on edit
- Modifying maintenance tasks when type changes
- Soft-delete or data export
- Index sorting controls or pagination
- Per-type delete blocking in the UI

## Architecture / Approach

Three new Volt full-page components following the established pattern: `#[Layout('layouts.app')]`, `mount()` with household `abort_if()` guard, `$this->validate()` in action methods, `redirect(route(...), navigate: true)` on success. The type combobox on the edit page reuses the Alpine.js combobox from the create wizard verbatim. Delete is a method on the edit component — no separate route. All tests extend `ApplianceTestCase`.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Appliance Index | List page + nav link + urgency-sorted cards | `withCount` closures with `whereNotNull` filter needed for calendar/metric task distinction |
| 2. Appliance Edit | Edit form + type-change notice + save → show | Two-tier type query and `model` required validation must both be correct |
| 3. Appliance Delete | Delete method + confirmation modal on edit page | Task count must be loaded in `mount()` before the modal can render correctly |

**Prerequisites:** S-01 create wizard and show page merged and working (already on `main`).
**Estimated effort:** ~1–2 sessions across 3 phases.

## Open Risks & Assumptions

- The index sort query uses `withCount` with multiple scoped closures in one pass — validate this works correctly in the SQLite test environment (it should, but worth checking on first test run).
- `restrictOnDelete` on `appliance_type_id` means if a household's custom type is somehow deleted separately, existing appliances would lose their type FK integrity — not a concern for this change, but worth noting for future type-management work.

## Success Criteria (Summary)

- "My Appliances" index loads, shows cards sorted by urgency, and links to edit
- Edit form pre-fills, validates, saves, and shows the type-change notice when applicable
- Delete modal shows the correct appliance name and task count; confirming removes the record and redirects to index

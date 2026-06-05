<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Appliance CRUD Implementation Plan

- **Plan**: context/changes/appliance-crud/plan.md
- **Scope**: All Phases (1–3)
- **Date**: 2026-06-05
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical  4 warnings  5 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | WARNING |

## Grounding

7/7 plan files verified ✓ | All 16 Progress rows [x] ✓ | 6/6 automated tests passed ✓

## Findings

### F1 — save() lacks a transaction boundary

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: edit.blade.php:54–84
- **Detail**: `save()` ran `ApplianceType::firstOrCreate()` then `$this->appliance->update()` without `DB::transaction()`. A crash between the two could orphan a newly-created ApplianceType row. `create.blade.php:confirm()` wraps both in a transaction.
- **Fix**: Wrapped type-resolution + update in `DB::transaction(fn() => ...)`, added `DB` facade import.
- **Decision**: FIXED

### F2 — Delete modal diverges from reference pattern (no form wrapper)

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: edit.blade.php:199–222
- **Detail**: Modal body used a bare `<div>` with `wire:click="delete"`. Reference pattern (`delete-user-form.blade.php`) uses `<form wire:submit="...">` for submit-on-Enter UX.
- **Fix**: Wrapped modal body in `<form wire:submit.prevent="delete">`, changed danger button to `type="submit"`, added `type="button"` to cancel.
- **Decision**: FIXED

### F3 — No test for cross-household ApplianceType injection in save()

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Appliances/ApplianceEditTest.php
- **Detail**: `save()` guards against a crafted `selectedTypeId` pointing to another household's private ApplianceType (edit.blade.php:69 — 403 abort). Guard was correct but untested.
- **Fix**: Added `test_save_with_cross_household_type_id_returns_403()` — sets `selectedTypeId` to a private type from another household, asserts `assertForbidden()`.
- **Decision**: FIXED

### F4 — Cross-household delete test covers only mount() guard, not delete() re-auth

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Appliances/ApplianceDeleteTest.php:32–48
- **Detail**: The cross-household test only tripped `mount()` (403 there). `delete()` has its own re-authorization check (edit.blade.php:89–91) with zero test coverage.
- **Fix**: Added `test_delete_re_auth_blocks_after_household_access_revoked()` — mounts successfully, then detaches user from household, calls `delete()`, asserts 403 and record intact.
- **Decision**: FIXED

### F5 — $taskCount is a stale snapshot

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: edit.blade.php:37
- **Detail**: Count is cosmetic (modal warning only). If tasks are added between mount() and delete(), the modal understates the impact. Acceptable per the plan ("count is informational, not live"). Cascade handles actual deletion regardless.
- **Decision**: SKIPPED

### F6 — ApplianceEditTest save() test has no assertHasNoErrors()

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Appliances/ApplianceEditTest.php:25–33
- **Detail**: mount() pre-populates $typeSearch from existing type so validation passes — test is not a false-positive. But adding `->assertHasNoErrors()` would make this explicit.
- **Decision**: SKIPPED

### F7 — Whitespace-only typeSearch passes 'required' validation

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: edit.blade.php:60
- **Detail**: `'typeSearch' => ['required', 'string', 'max:255']` passes on "   ". Same gap exists in create.blade.php — out of scope for this change.
- **Decision**: SKIPPED

### F8 — service_records cascade not asserted in ApplianceDeleteTest

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Appliances/ApplianceDeleteTest.php:28–30
- **Detail**: maintenance_tasks cascade is asserted; service_records two-level cascade (appliance → task → record) is not. Migration cascade is correct but untested.
- **Decision**: SKIPPED

### F9 — Extra root <div> wrapper in edit.blade.php

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: edit.blade.php:110–111
- **Detail**: Bare `<div>` wrapper added to satisfy Livewire's single-root requirement (x-modal sibling). Works but adds unnecessary nesting vs. create.blade.php pattern.
- **Decision**: SKIPPED

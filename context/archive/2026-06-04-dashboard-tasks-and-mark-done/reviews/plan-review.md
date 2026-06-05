<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Dashboard Tasks and Mark Done

- **Plan**: `context/changes/dashboard-tasks-and-mark-done/plan.md`
- **Mode**: Deep (two passes)
- **Date**: 2026-06-04
- **Verdict**: SOUND
- **Findings**: 0 critical, 2 warnings (pass 1, all fixed), 5 observations (all fixed)

## Verdicts

| Dimension | Verdict |
|---|---|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | PASS |
| Plan Completeness | PASS |

## Grounding

5/5 paths ✓, Volt import at routes/web.php:6 ✓, scopes absent (expected — new additions), brief↔plan ✓. Route form mismatch (`Route::view()` vs described closure) caught in pass 2 and fixed.

## Findings

### Pass 1 findings (all FIXED)

### F1 — Null dereference in RecordTaskCompletion ownership guard

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Blind Spots
- **Location**: Phase 1 — RecordTaskCompletion action, Contract block
- **Detail**: Plan had `abort_if($task->appliance->household_id !== $user->households()->first()->id, 403)`. `households()->first()` returns nullable — `->id` on null throws TypeError before abort_if evaluates. PHPStan level 6 would flag this and block criterion 1.2.
- **Fix**: Two-step null-safe pattern matching `create.blade.php:169-170`.
- **Decision**: FIXED

### F2 — $householdId source unspecified in markDone() contract

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Completeness
- **Location**: Phase 2 — markDone() contract
- **Detail**: Plan used `$householdId` without specifying source. Risk: implementer stores it as a public Livewire property (tamperable).
- **Fix**: Explicit re-resolution requirement added; must NOT be stored as public property.
- **Decision**: FIXED

### F3 — Brief shows `calendar()` in markDone() fetch; plan body omitted it

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Plan Completeness
- **Location**: Phase 2 — markDone() contract
- **Detail**: Without `calendar()` in the fetch, markDone() could be called on metric task IDs via Livewire manipulation.
- **Fix**: `calendar()` added to markDone() fetch scope.
- **Decision**: FIXED

### F4 — markDone() query refresh pattern unspecified; duplication risk

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Plan Completeness
- **Location**: Phase 2 — markDone() contract
- **Detail**: No guidance on extracting shared `loadTasks()` method — would cause four-query duplication.
- **Fix**: `loadTasks()` private method guidance added to contract.
- **Decision**: FIXED

### F5 — Test assertion mechanism: redirect from `auth`, not `verified`

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Plan Completeness
- **Location**: Phase 2 — Test contract, first test case
- **Detail**: Cosmetic — the `auth` middleware handles the /login redirect, not `verified`.
- **Fix**: Test case description updated to name the `auth` middleware.
- **Decision**: FIXED

### Pass 2 findings (all FIXED)

### F6 — Route current-state description wrong + leading-slash mismatch

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Plan Completeness
- **Location**: Phase 2 — Route conversion, Contract block
- **Detail**: Plan described a "closure route" but actual is `Route::view('dashboard', 'dashboard')`. Proposed replacement used `/dashboard` (leading slash) while codebase convention omits it.
- **Fix**: Updated Contract to reference `Route::view()` as before-state; changed to `Volt::route('dashboard', ...)` without leading slash.
- **Decision**: FIXED

### F7 — mount() chained ->first()->id without explicit null guard

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Plan Completeness
- **Location**: Phase 2 — mount() contract
- **Detail**: mount() described chained `->first()->id` with only a parenthetical hint about aborting; same class of issue as F1, already fixed in RecordTaskCompletion but missed in mount().
- **Fix**: mount() updated to show explicit two-step null-safe pattern matching RecordTaskCompletion and the codebase convention.
- **Decision**: FIXED

<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Dashboard Tasks and Mark Done

- **Plan**: `context/changes/dashboard-tasks-and-mark-done/plan.md`
- **Mode**: Deep
- **Date**: 2026-06-04
- **Verdict**: SOUND (after triage fixes)
- **Findings**: 0 critical, 2 warnings, 3 observations

## Verdicts

| Dimension | Verdict |
|---|---|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | WARNING |
| Plan Completeness | WARNING |

## Grounding

5/5 paths ✓, Volt import at routes/web.php:6 ✓, scopes absent (expected — new additions), brief↔plan ⚠️ minor inconsistency on markDone() scope (fixed in triage)

## Findings

### F1 — Null dereference in RecordTaskCompletion ownership guard

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Blind Spots
- **Location**: Phase 1 — RecordTaskCompletion action, Contract block
- **Detail**: Plan had `abort_if($task->appliance->household_id !== $user->households()->first()->id, 403)`. `households()->first()` returns `TModel|null` — calling `->id` on null throws TypeError before abort_if evaluates. PHPStan level 6 with Larastan will flag both `auth()->user()` (nullable) and `->first()` (nullable model). Existing pattern at `create.blade.php:169-170` guards with a prior `abort_if(!$household, ...)`.
- **Fix**: Two-step null-safe pattern — `$household = $user->households()->first(); abort_if(!$household || $task->appliance->household_id !== $household->id, 403);`
- **Decision**: FIXED — plan updated with two-step guard pattern

### F2 — $householdId source unspecified in markDone() contract

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Completeness
- **Location**: Phase 2 — Dashboard Volt component, markDone() contract
- **Detail**: Plan used `$householdId` as a local variable in markDone() without specifying its source. An implementer could store it as a public Livewire property (serialised to client, tamperable) rather than re-resolving from `Auth::user()`. Codebase convention (`create.blade.php:169`) re-resolves inside each action method.
- **Fix**: Add explicit note — `$householdId` resolved fresh via `Auth::user()->households()->first()` inside markDone(), must NOT be stored as a public Livewire property.
- **Decision**: FIXED — markDone() contract updated with explicit re-resolution requirement

### F3 — Brief shows `calendar()` in markDone() fetch; plan body omitted it

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 2 — markDone() contract; plan-brief Architecture section
- **Detail**: Brief showed `MaintenanceTask::calendar()->forHousehold($id)->findOrFail($taskId)`; plan body had `MaintenanceTask::forHousehold($householdId)->findOrFail($taskId)`. Without `calendar()`, Livewire manipulation could call markDone() on a metric task ID (would create a spurious ServiceRecord).
- **Fix**: Add `calendar()` to the markDone() fetch scope.
- **Decision**: FIXED — bundled into F2 fix; markDone() now uses `calendar()->forHousehold()`

### F4 — markDone() query refresh pattern unspecified; duplication risk

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 2 — markDone() contract
- **Detail**: Plan said markDone() "re-runs all four collection queries" but didn't mention extracting a private `loadTasks()` method. Without guidance, implementer would likely duplicate four queries.
- **Fix**: Add `loadTasks()` private method guidance to the contract.
- **Decision**: FIXED — bundled into F2 fix; markDone() contract now specifies `loadTasks()` extraction

### F5 — Test assertion mechanism: redirect comes from `auth`, not `verified`

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 2 — Test contract, first test case
- **Detail**: Test "unauthenticated GET /dashboard redirects to /login" is correct and will pass. The redirect is from the `auth` middleware; the `verified` middleware only redirects authenticated-but-unverified users to `/verify-email`. Cosmetic description issue.
- **Fix**: Clarify test case description to name the `auth` middleware.
- **Decision**: FIXED — test case description updated in plan

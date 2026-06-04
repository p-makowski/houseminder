<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: First Appliance + AI Plan (S-01)

- **Plan**: context/changes/first-appliance-ai-plan/plan.md
- **Scope**: All phases (1–5, full plan)
- **Date**: 2026-06-04
- **Verdict**: APPROVED (post-triage)
- **Findings**: 2 critical  2 warnings  4 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS (fixed) |
| Architecture | PASS |
| Pattern Consistency | PASS (fixed) |
| Success Criteria | PASS |

## Findings

### F1 — ApplianceType ownership not validated in confirm()

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: resources/views/livewire/pages/appliances/create.blade.php:156
- **Detail**: confirm() called ApplianceType::findOrFail($this->selectedTypeId) with no ownership check. $selectedTypeId is a public property — any user can set it in the browser to an arbitrary ID.
- **Fix**: Added `abort_if($type->household_id !== null && $type->household_id !== $household->id, 403)` after findOrFail.
- **Decision**: FIXED

### F2 — No validation of $tasks/$backdates before DB write in confirm()

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: resources/views/livewire/pages/appliances/create.blade.php:173
- **Detail**: $tasks and $backdates are public Livewire properties freely manipulable in the browser. confirm() wrote them to DB without validation — invalid interval_unit, interval_value ≤ 0, malformed dates, over-long strings all possible.
- **Fix (A)**: Added $this->validate() at top of confirm() covering tasks.*.name/interval_value/interval_unit/anchor_type/description and backdates.*.date/notes. Also updated TaskEditingTest to set a name for the manually added task.
- **Decision**: FIXED via Fix A

### F3 — AiFailureTest binds a Closure via $this->instance() — fragile

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Feature/Appliances/AiFailureTest.php:33
- **Detail**: $this->instance(GenerateMaintenancePlan::class, Closure) stored a raw Closure as the container binding — semantically wrong and fragile under typed injection.
- **Fix**: Replaced with $this->mock() using Mockery shouldReceive('__invoke') expectations.
- **Decision**: FIXED

### F4 — Step 4→3 back-navigation leaves $backdates stale

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: resources/views/livewire/pages/appliances/create.blade.php:66
- **Detail**: prevStep() did not reinitialize $backdates. Implicit invariant that the only path to step 3 is through advanceFromStep2().
- **Fix**: Added `abort_if(count($this->backdates) !== count($this->tasks), 422)` at top of confirm() to make the invariant explicit.
- **Decision**: FIXED

### F5 — protected $householdId set in mount() but never hydrated across requests

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: resources/views/livewire/pages/appliances/create.blade.php:33
- **Detail**: Livewire does not hydrate protected properties. $householdId is set in mount() but only used there; confirm() re-fetches the household independently. Dead weight and misleading.
- **Decision**: SKIPPED

### F6 — retryFetch() adds aiLoading/aiError reset not described in plan

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: resources/views/livewire/pages/appliances/create.blade.php (retryFetch)
- **Detail**: Plan stated "calls fetchSuggestions() directly." Implementation correctly resets aiError and aiLoading before delegating. No defect — undocumented addendum.
- **Decision**: SKIPPED

### F7 — navigation.blade.php missing declare(strict_types=1)

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: resources/views/livewire/layout/navigation.blade.php:1
- **Detail**: Pre-existing gap — all other Volt components have declare(strict_types=1).
- **Fix**: Added declare(strict_types=1) after <?php.
- **Decision**: FIXED

### F8 — Carbon::parse($backdate['date']) without validation

- **Severity**: 🔵 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: resources/views/livewire/pages/appliances/create.blade.php:178
- **Detail**: Malformed date string would throw inside the DB transaction. Covered by the backdates.*.date => nullable|date rule added in F2.
- **Decision**: FIXED via F2

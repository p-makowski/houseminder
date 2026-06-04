<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: First Appliance + AI Plan (S-01)

- **Plan**: context/changes/first-appliance-ai-plan/plan.md
- **Scope**: All Phases (1–5 of 5)
- **Date**: 2026-06-04
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical | 3 warnings | 5 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Findings

### F1 — protected $householdId: non-persisted Livewire property

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: create.blade.php:33 (removed)
- **Detail**: Livewire does not serialise protected properties — field was zeroed on every request. mount() set it but confirm() re-fetched from Auth. A future dev using $this->householdId in a new method would silently read 0, bypassing household scoping.
- **Fix Applied**: Removed the protected property; inlined as local variable in mount() only. confirm() already re-fetched correctly from Auth.
- **Decision**: FIXED via Fix A

### F2 — addTask() has no ceiling

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: create.blade.php:138–147
- **Detail**: addTask() appended without a ceiling, allowing unbounded task arrays via the wire protocol.
- **Fix Applied**: Added `if (count($this->tasks) >= 20) { return; }` guard.
- **Decision**: FIXED

### F3 — Test setup copy-pasted in all four test classes

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Pattern Consistency
- **Location**: tests/Feature/Appliances/
- **Detail**: User + Household factory + attach() setup duplicated verbatim in every test class.
- **Fix Applied**: Extracted `ApplianceTestCase` base class with shared `setUp()` method. All four test classes now extend it.
- **Decision**: FIXED via Fix A

### F4 — backdates.*.metric has no numeric validation rule

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: create.blade.php:153–161
- **Detail**: service_records.metric_reading is a double column. A non-numeric string would throw a QueryException (500) rather than a validation error.
- **Fix Applied**: Added `'backdates.*.metric' => ['nullable', 'numeric']` to confirm() validation rules.
- **Decision**: FIXED

### F5 — prevStep() has no lower-bound guard

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: create.blade.php:65–69
- **Detail**: prevStep() callable via wire protocol; at step 1 yields $step = 0 (renders nothing).
- **Fix Applied**: Added `if ($this->step > 1)` guard.
- **Decision**: FIXED

### F6 — x-init AI trigger: double-fire theoretical, plan intent correct

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: create.blade.php:325
- **Detail**: Plan deliberately chose Alpine x-init over wire:init. Double-fire risk is theoretical and not practically triggerable in S-01 (synchronous PHP call, no interactive elements in spinner state).
- **Decision**: SKIPPED

### F7 — Prompt injection: low exploitability, mitigated by structured output

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: GenerateMaintenancePlan.php:20–22
- **Detail**: User-supplied strings interpolated verbatim into LLM prompt. Control characters not stripped.
- **Fix Applied**: Added `preg_replace('/[\x00-\x1F\x7F]/u', '', ...)` to strip control characters from all three input strings.
- **Decision**: FIXED

### F8 — backdates.*.skip missing boolean validation rule

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: create.blade.php:153–161
- **Detail**: All other backdates.* fields validated in confirm() but skip was missing. Skip affects ServiceRecord creation.
- **Fix Applied**: Added `'backdates.*.skip' => ['boolean']` to confirm() validation rules.
- **Decision**: FIXED

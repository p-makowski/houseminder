<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Add New Maintenance Task from Appliance Page

- **Plan**: context/changes/appliance-new-service-entry/plan.md
- **Scope**: All phases (1–4 of 4)
- **Date**: 2026-06-08
- **Verdict**: APPROVED (post-triage)
- **Findings**: 0 critical  1 warning  6 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING → PASS (F2, F3 fixed) |
| Scope Discipline | PASS |
| Safety & Quality | WARNING → PASS (F1, F6 fixed; F7 SKIPPED — untestable pattern) |
| Architecture | PASS |
| Pattern Consistency | WARNING → PASS (F4, F5 fixed) |
| Success Criteria | PASS |

## Findings

### F1 — Global table count assertions in test class

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: tests/Feature/Appliances/ApplianceShowTaskCreateTest.php:38, 62, 97
- **Detail**: Three assertions used global table counts (ServiceRecord::count(), ServiceRecord::first(), MaintenanceTask::count()) instead of appliance-scoped lookups. Safe with RefreshDatabase today, but silently breaks if any test pre-seeds a record.
- **Fix**: Replaced with `$task->serviceRecords()->count()`, `$task->serviceRecords()->first()`, and `$this->appliance->maintenanceTasks()->count()` / whereHas-scoped ServiceRecord count.
- **Decision**: FIXED

### F2 — Missing component state reset assertions in test 1

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: tests/Feature/Appliances/ApplianceShowTaskCreateTest.php:23-39
- **Detail**: Plan contract for test 1 listed `assertSet('addingTask', false)` and `assertSet('addName', '')` after save. Both were absent.
- **Fix**: Added the two component state assertions to the Volt::test chain.
- **Decision**: FIXED

### F3 — Auth test method name differs from plan

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: tests/Feature/Appliances/ApplianceShowTaskCreateTest.php:101
- **Detail**: Method was `test_unauthorized_user_cannot_access_appliance` instead of `test_unauthorized_user_cannot_create_task`.
- **Fix**: Renamed to match the plan.
- **Decision**: FIXED

### F4 — Metric reading input uses type="text" instead of type="number"

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: resources/views/livewire/pages/appliances/_add-form.blade.php:81
- **Detail**: addLastMetric used `type="text"` while addIntervalValue in the same form uses `type="number"`. No numeric keyboard on mobile; inconsistent.
- **Fix**: Changed to `type="number" step="any"`.
- **Decision**: FIXED

### F5 — Save button missing wire:loading.attr="disabled"

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: resources/views/livewire/pages/appliances/_add-form.blade.php:95
- **Detail**: No double-submit guard on Save. Mark Done buttons in the same file all use `wire:loading.attr="disabled"`.
- **Fix**: Added `wire:loading.attr="disabled"` and `disabled:opacity-50` to the Save button.
- **Decision**: FIXED

### F6 — Metric task: ServiceRecord.completed_at = today but task.last_completed_at = null

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: resources/views/livewire/pages/appliances/show.blade.php:313, 333
- **Detail**: When a metric task was saved with a metric reading but no date, `last_completed_at` on the task was null but `ServiceRecord.completed_at` was `Carbon::today()`. The wizard behaves the same way (intentional mirror), but the inconsistency could surprise future features that derive "last done" from the task row.
- **Fix**: Changed `'last_completed_at' => $hasDate ? $anchor : null` to `'last_completed_at' => $hasDate ? $anchor : ($hasMetric ? Carbon::today() : null)` so task and ServiceRecord stay in sync.
- **Decision**: FIXED

### F7 — Missing saveNewTask authorization test

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: tests/Feature/Appliances/ApplianceShowTaskCreateTest.php:101-108
- **Detail**: No test verifying a foreign user cannot call saveNewTask directly. Attempted fix revealed this is not directly testable with Livewire test helpers: calling a method on a component that 403d during mount() produces "Invalid Livewire snapshot structure" — Livewire re-runs mount() on every request, so the existing mount-level 403 test covers this.
- **Decision**: SKIPPED — untestable via current Livewire test helpers; mount() guard is sufficient.

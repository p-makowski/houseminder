<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Dashboard Tasks and Mark Done

- **Plan**: context/changes/dashboard-tasks-and-mark-done/plan.md
- **Scope**: Phase 1 of 2
- **Date**: 2026-06-05
- **Verdict**: APPROVED (after fixes)
- **Findings**: 0 critical  0 warnings  0 observations (all triaged)

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Findings

### F1 — Single-household ->first() authz assumption

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Actions/RecordTaskCompletion.php:18–19
- **Detail**: `$user->households()->first()` silently picks one household. Correct under the current single-household-per-user invariant but becomes a bug if multi-household support is added. Already documented in lessons.md and accepted as a known limitation.
- **Fix A ⭐ Applied**: Accept the known risk — lessons.md rule covers the fix path.
- **Decision**: ACCEPTED (documented in lessons.md — "Ownership guards assume single household per user")

### F2 — isToday() without frozen time — potential midnight flake

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: tests/Feature/Dashboard/RecordTaskCompletionTest.php:34, 50
- **Detail**: `->isToday()` assertions without freezing the clock — fails if test straddles midnight.
- **Fix**: Added `$this->freezeTime()` to `DashboardTestCase::setUp()` so all subclass tests run with a stable clock.
- **Decision**: FIXED

### F3 — No duplicate-completion guard on the action

- **Severity**: 💬 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Actions/RecordTaskCompletion.php
- **Detail**: Two rapid invocations would create two ServiceRecord rows and advance next_due_at twice.
- **Fix**: Added `wire:loading.attr="disabled"` and `disabled:opacity-50` to all three Mark done buttons in the dashboard template.
- **Decision**: FIXED

### F4 — 403 abort test doesn't assert status code value

- **Severity**: 💬 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Dashboard/RecordTaskCompletionTest.php:151
- **Detail**: `expectException(HttpException::class)` passes for any HTTP exception. Note: `expectExceptionCode(403)` does not work because `HttpException::getCode()` is always 0; the HTTP status is in `getStatusCode()`.
- **Fix**: Replaced `expectException` pattern with try/catch that asserts `$e->getStatusCode() === 403`.
- **Decision**: FIXED

### F5 — No test for user with zero households

- **Severity**: 💬 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Dashboard/RecordTaskCompletionTest.php
- **Detail**: The `abort_if(!$household ...)` branch — user with no household membership — had no test coverage.
- **Fix**: Added `test_aborts_403_when_user_has_no_household` — creates a householdless user, invokes the action, asserts `$e->getStatusCode() === 403`.
- **Decision**: FIXED

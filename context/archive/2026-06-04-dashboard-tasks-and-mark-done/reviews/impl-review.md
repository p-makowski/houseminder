<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Dashboard Tasks and Mark Done

- **Plan**: context/changes/dashboard-tasks-and-mark-done/plan.md
- **Scope**: All Phases (1–2 of 2)
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

### F1 — Fragile unauthenticated-redirect test

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Dashboard/DashboardPageTest.php:14
- **Detail**: test_unauthenticated_user_is_redirected_to_login called auth()->logout() to undo actingAs() from DashboardTestCase::setUp(). If setUp() ever changed, the test would silently pass as authenticated.
- **Fix**: Extracted into standalone DashboardGuestTest extending TestCase (not DashboardTestCase), with its own RefreshDatabase — no dependency on setUp() actingAs() internals.
- **Decision**: FIXED

### F2 — Public Collection properties serialize full Eloquent models to DOM

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: resources/views/livewire/pages/dashboard.blade.php:12–15
- **Detail**: $overdue, $dueThisWeek, $upcoming, $metric were public Livewire properties — serialised into wire:snapshot on every render.
- **Fix A ⭐ Applied**: Converted to #[Computed] methods. Values evaluated on template access, never written to snapshot. Template references updated to $this->overdue etc.
- **Decision**: FIXED via Fix A

### F3 — Two separate now() calls in whereBetween

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: resources/views/livewire/pages/dashboard.blade.php — dueThisWeek()
- **Detail**: whereBetween used [now(), now()->addDays(7)] — two separate Carbon instances could straddle a midnight boundary.
- **Fix**: Captured $now = now() once at top of dueThisWeek() and reused for both bounds.
- **Decision**: FIXED (applied together with F2 refactor)

### F4 — No test that metric task has no Mark done button

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Dashboard/DashboardPageTest.php
- **Detail**: test_metric_task_appears_in_manual_tracking_section asserted task name visible but not absence of the button.
- **Fix**: Added ->assertDontSee('Mark done') to the metric task test.
- **Decision**: FIXED

### F5 — No IDOR write-action test for markDone

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Dashboard/DashboardPageTest.php
- **Detail**: Read isolation was tested but write-action rejection was not. The calendar()->forHousehold() scope guard was correct but untested.
- **Fix**: Added test_mark_done_rejects_foreign_household_task — calls markDone with a foreign task ID, catches ModelNotFoundException (scope guard proof), asserts no ServiceRecord created.
- **Decision**: FIXED

### F6 — markDone test doesn't assert task moves out of overdue section

- **Severity**: 💬 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Dashboard/DashboardPageTest.php:103
- **Detail**: test_mark_done_creates_service_record_and_updates_task verified DB side-effects but not re-render outcome.
- **Fix**: Added ->assertSee('No overdue tasks.') to the Volt::test chain — confirms the overdue bucket is empty after the mark-done re-render.
- **Decision**: FIXED

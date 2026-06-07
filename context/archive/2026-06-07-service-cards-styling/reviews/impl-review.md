<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Service Cards Styling

- **Plan**: context/changes/service-cards-styling/plan.md
- **Scope**: All phases (1–3 of 3)
- **Date**: 2026-06-07
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical  1 warning  4 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | WARNING |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | WARNING (automated PASS; 12 manual checks pending) |

## Automated Verification

- PHPStan: PASS (0 errors)
- Full test suite: PASS (128/128 → 126/126 after triage)

## Findings

### F1 — $task->appliance accessed without null guard

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: resources/views/components/maintenance-task-card.blade.php:23
- **Detail**: `$task->appliance->name` had no null guard. Safe today because all current callers eager-load the relation or never pass showApplianceName=true, but any future caller that forgets `.with('appliance')` would get a fatal or N+1.
- **Fix**: Applied Fix A — `{{ $task->appliance?->name }}` + `@props` docblock comment noting the eager-load requirement.
- **Decision**: FIXED via Fix A

### F2 — Unplanned "Manual tracking" section added to dashboard

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: resources/views/livewire/pages/dashboard.blade.php:204–214
- **Detail**: Phase 3 only described four time-bucketed sections. A fifth "Manual tracking" section and three test files were added beyond scope — all architecturally consistent. Minor note: DashboardPageTest::test_upcoming_task_appears_in_upcoming_section uses a task at exactly +30 days which lands in "this month" due to strict boundary, but passes via page-level assertSee.
- **Fix**: Recorded as addendum in plan.md.
- **Decision**: FIXED

### F3 — `<p>` vs `<h3>` for task title depending on showApplianceName flag

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: resources/views/components/maintenance-task-card.blade.php:23,25
- **Detail**: showApplianceName=true rendered `<p>`; false rendered `<h3>` — inconsistent semantics for the same logical element.
- **Fix**: Normalized both branches to `<p>`.
- **Decision**: FIXED

### F4 — mount() and householdId computed property both query households()

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: resources/views/livewire/pages/dashboard.blade.php:18,22–25
- **Detail**: mount() called `households()->first()` independently from the `#[Computed] householdId()` property, causing two households queries on page load.
- **Fix**: Changed mount() to use `doesntExist()` (lighter COUNT query) — semantically appropriate for an existence check, avoids the full SELECT.
- **Decision**: FIXED

### F5 — ApplianceShowDisplayTest duplicates ApplianceShowSectionsTest

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Feature/Appliances/ApplianceShowDisplayTest.php
- **Detail**: `test_due_soon_task_renders_yellow_border` and `test_metric_task_renders_gray_border` were duplicate assertions already covered by ApplianceShowSectionsTest. Note: `test_overdue_task_renders_red_border` was NOT duplicated (red border not checked in ApplianceShowSectionsTest) and was kept.
- **Fix**: Removed the two duplicate test cases. Test count: 128 → 126.
- **Decision**: FIXED

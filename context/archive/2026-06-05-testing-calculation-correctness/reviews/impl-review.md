<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Phase 1–5 — Calculation Correctness

- **Plan**: context/changes/testing-calculation-correctness/plan.md
- **Scope**: All phases (full plan)
- **Date**: 2026-06-06
- **Verdict**: APPROVED
- **Findings**: 0 critical  0 warnings  4 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Notes

Phase 1 had a prior impl-review (`reviews/impl-review-phase-1.md`) that triggered a triage moving the helper from `MaintenanceTask::calculateNextDueAt()` to `app/Support/CalendarInterval.php`. That drift is accepted and documented in `context/foundation/lessons.md`. This review covers the full plan including Phase 1 in its final state.

Automated checks:
- Full suite: 83/83 PASS
- PHPStan (changed files: `app/Support/`, `app/Actions/`, blade): exit 0, no violations
- Pint: 9 pre-existing violations in `appliance-crud` files; zero violations in files changed by this implementation

## Findings

### F1 — §6.1 run commands use suite paths, not class filters

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: context/foundation/test-plan.md §6.1
- **Detail**: Plan specified `composer test --filter MaintenanceTaskCalculationTest` and `--filter WizardCalculationTest`. §6.1 documented suite-level `php artisan test tests/Unit/` and `php artisan test tests/Feature/Appliances/` instead.
- **Fix**: Replace suite-path commands with `--filter ClassName` in §6.1.
- **Decision**: FIXED

### F2 — fixed_calendar test intent undocumented in test file

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Feature/Dashboard/RecordTaskCompletionTest.php:137
- **Detail**: Four `_with_fixed_calendar_anchor` tests assert identical results to `from_last_done` equivalents — intentional per plan — but no inline comment explained WHY.
- **Fix**: Add one-line comment: `// anchor_type does not affect mark-done arithmetic by design — fixed_calendar tasks advance from completion time, same as from_last_done.`
- **Decision**: FIXED

### F3 — Extra test test_does_not_mutate_anchor (benign)

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: tests/Unit/Support/CalendarIntervalTest.php:57
- **Detail**: A 6th test method beyond the plan's 5-method contract. Guards against `->copy()` being removed from `CalendarInterval`. High-value purity guarantee.
- **Decision**: SKIPPED (beneficial addition, keep as-is)

### F4 — no-backdate anchor today() vs now() correlation implicit

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Feature/Appliances/WizardCalculationTest.php:16
- **Detail**: Comment above no-backdate group didn't explain that `confirm()` falls back to `Carbon::today()` as anchor — making the `Carbon::today()` in test assertions non-obvious.
- **Fix**: Extend the existing comment to state the fallback explicitly.
- **Decision**: FIXED

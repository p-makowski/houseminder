# Phase 1 — Calculation Correctness Tests for `next_due_at` — Plan Brief

> Full plan: `context/changes/testing-calculation-correctness/plan.md`
> Research: `context/changes/testing-calculation-correctness/research.md`

## What & Why

Prove that `next_due_at` is always the exact expected date after wizard `confirm()` and after mark-done, for all 4 calendar units × both anchor types. The wizard `confirm()` path currently has zero assertions on `next_due_at`; the mark-done path is well-tested for `from_last_done` but has no `fixed_calendar` tests. The calculation logic is also duplicated in two files with no shared abstraction — a silent divergence risk if one file gets a future fix the other doesn't.

## Starting Point

Two identical `match(interval_unit)` blocks independently compute `next_due_at` — one in `create.blade.php:confirm()` (wizard), one in `RecordTaskCompletion:__invoke()` (mark-done). `RecordTaskCompletion` is covered for `from_last_done` × 4 units with exact frozen-time assertions; the wizard has no `next_due_at` assertions at all. `ApplianceTestCase` (wizard base class) does not freeze time; `DashboardTestCase` (mark-done base class) does.

## Desired End State

`MaintenanceTask::calculateNextDueAt(Carbon $anchor, string $unit, int $value): Carbon` exists as the single canonical calculation. Both production files call it. A unit test suite proves the helper is correct in isolation. Twelve wizard integration tests cover all 4 units × 3 anchor scenarios. Four new mark-done tests cover `fixed_calendar`. `ApplianceTestCase` freezes time. `test-plan.md §6.1` documents the extraction + unit-test pattern for future use.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Extract or test inline? | Extract to `MaintenanceTask::calculateNextDueAt()` | Eliminates silent-divergence risk and enables a true unit test layer — §6.1 can document a unit-test pattern, not just integration | Plan |
| `freezeTime` in wizard tests | Add globally to `ApplianceTestCase::setUp()` | Consistent with `DashboardTestCase` pattern; all future wizard tests inherit deterministic time automatically | Plan |
| Wizard test entry point | Set Volt state directly + dispatch `confirm` (no AI step) | Focused on arithmetic; avoids coupling the calculation test to `Prism::fake()` churn | Plan |
| `RecordTaskCompletion` fixed_calendar | Add 4 tests | Closes the coverage matrix gap; documents the "anchor_type is ignored at mark-done" decision as an assertion | Plan |
| New wizard test file | `WizardCalculationTest.php` (new) | Separates calculation correctness from happy-path count assertions in `AddApplianceWizardTest` | Plan |

## Scope

**In scope:**
- Extract `MaintenanceTask::calculateNextDueAt()` static helper; refactor both production files to use it
- `ApplianceTestCase::freezeTime()` global addition
- Unit tests for the extracted helper (5 tests)
- Wizard `confirm()` integration tests: 4 units × 3 anchor scenarios (12 tests)
- `RecordTaskCompletion` `fixed_calendar` integration tests: 4 units (4 tests)
- `test-plan.md §6.1` cookbook fill-in

**Out of scope:**
- `km` metric task coverage
- AI generation step (`Prism::fake()` edge cases) — Phase 3
- CI gate wiring — Phase 4
- Full wizard happy-path re-test

## Architecture / Approach

A static helper on `MaintenanceTask` centralises the four-arm `match` block. It takes a Carbon anchor, unit string, and integer value; returns a Carbon result; throws `InvalidArgumentException` on non-calendar units. `RecordTaskCompletion`'s `default` arm (which preserves `next_due_at` for metric tasks) is left in place — only the 4 calendar-unit arms are replaced. The wizard's `default` arm is removed entirely (the helper's exception is the correct failure mode there). Tests are split into a unit layer (no DB, plain PHPUnit) for the pure function and an integration layer (Volt + DB) for both wiring paths.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Extract helper | `MaintenanceTask::calculateNextDueAt()` live; both production files use it | Existing tests break if default arms not handled correctly |
| 2. freezeTime + unit tests | `ApplianceTestCase` freezes time; 5 unit tests on the pure helper | `test_happy_path_creates_appliance_and_tasks` fails if freeze ordering is wrong |
| 3. Wizard integration tests | 12 tests covering all wizard `confirm()` calculation paths | Volt state setup is under-specified; implementer must trace `confirm()` state reads |
| 4. RTC `fixed_calendar` tests | 4 tests closing the mark-done coverage matrix | Minimal risk — follows existing test pattern exactly |
| 5. §6.1 cookbook | `test-plan.md §6.1` filled in; Phase 1 marked complete | Content quality only — must be self-contained for future readers |

**Prerequisites:** Phase 1 (extraction) must complete before Phases 3 and 4. Phase 2 (`freezeTime`) must complete before Phase 3 (wizard tests need frozen time).

**Estimated effort:** ~2 sessions across 5 phases. Phase 3 is the longest — 12 test methods with Volt state setup to trace.

## Open Risks & Assumptions

- The Volt state structure that `confirm()` reads (task array shape, `backdate` property) must be traced from `create.blade.php:153–241` and the existing `AddApplianceWizardTest` before writing Phase 3 tests. The plan specifies intent and the test matrix; the exact `->set()` calls are for the implementer to derive.
- If `calculateNextDueAt()` ends up needing `use Illuminate\Support\Carbon` rather than `Carbon\Carbon`, PHPStan may flag the alias — resolve at extraction time.

## Success Criteria (Summary)

- `composer test` passes with 21 new tests green (5 unit + 12 wizard + 4 RTC) and zero regressions
- `next_due_at` for every combination in the coverage matrix is proven by an exact-date assertion, not just "is in the future"
- `test-plan.md §6.1` is self-contained and citable by future developers and agents

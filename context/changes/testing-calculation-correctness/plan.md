# Phase 1 — Calculation Correctness Tests for `next_due_at` — Implementation Plan

## Overview

Prove that `next_due_at` is always the exact expected date after wizard `confirm()` and after mark-done, for all 4 calendar units × both anchor types. The calculation is currently duplicated in two files with no shared abstraction; this plan extracts it to a single static helper, then writes unit tests for the helper and integration tests for both code paths.

## Current State Analysis

Two independent `match(interval_unit)` blocks compute `next_due_at`:

- **Wizard path**: `resources/views/livewire/pages/appliances/create.blade.php:confirm()` (lines 197–207). Uses a user-supplied backdate as anchor, or `Carbon::today()` when no backdate is provided.
- **Mark-done path**: `app/Actions/RecordTaskCompletion.php:__invoke()` (lines 21–37). Always uses `now()` as anchor.

The blocks are functionally identical — four arms, same `addDays/Weeks/Months/Years` calls — but copy-pasted, not extracted. `anchor_type` is a storage concern only: it controls which field (`anchor_date` or `last_completed_at`) is written, but the arithmetic is the same for both types.

**Coverage gap**: `RecordTaskCompletion` is fully covered for `from_last_done` × 4 units with frozen-time exact assertions. The wizard `confirm()` path has zero assertions on `next_due_at` in any existing test. `RecordTaskCompletion` × `fixed_calendar` has zero tests (identical arithmetic to `from_last_done`, but the path is unproven).

`ApplianceTestCase` does not freeze time; `DashboardTestCase` does.

## Desired End State

`MaintenanceTask::calculateNextDueAt()` exists as the single canonical implementation of the calendar-unit interval arithmetic. Both production files call it. All existing tests still pass, and the following new tests are green:

- `tests/Unit/Models/MaintenanceTaskCalculationTest.php` — 5 tests proving the helper returns exact Carbon dates for all 4 calendar units plus an `InvalidArgumentException` on an unknown unit.
- `tests/Feature/Appliances/WizardCalculationTest.php` — 12 tests proving the wizard `confirm()` path produces the exact expected `next_due_at` for 4 units × 3 anchor scenarios; anchor storage fields are also asserted for `fixed_calendar` and `from_last_done` variants.
- `tests/Feature/Dashboard/RecordTaskCompletionTest.php` — 4 new tests proving `fixed_calendar` tasks produce `now() + interval` (same arithmetic as `from_last_done`), closing the coverage matrix.

`ApplianceTestCase::setUp()` freezes time. `context/foundation/test-plan.md §6.1` is filled in with the unit-test pattern and the `Volt::test` integration-test pattern.

### Key Discoveries

- `anchor_type` does not affect the arithmetic in either path — it was deliberately designed this way (see research §8 and archive `dashboard-tasks-and-mark-done/plan.md`). Both paths advance from the relevant base date.
- The wizard's `default` arm in its `match` falls through to `addMonths`; `RecordTaskCompletion`'s `default` arm preserves `next_due_at` for metric tasks. These are different defaults — the extracted helper must throw on unknown units, and only the `RecordTaskCompletion` `default` arm stays in place after refactoring.
- `DashboardTestCase::setUp()` pattern: `parent::setUp()` → `$this->freezeTime()` → fixture creation. The new `ApplianceTestCase` change must follow the same ordering so that factory timestamps are also frozen.
- `tests/Unit/ExampleTest.php` extends `PHPUnit\Framework\TestCase` directly — this is the project convention for unit tests with no Laravel bootstrapping.

## What We're NOT Doing

- `km` metric task coverage — excluded from Phase 1 per `test-plan.md §3`.
- Testing the AI generation step (`GenerateMaintenancePlan`) — that is Phase 3 (`Prism::fake()` with edge cases).
- Re-testing the wizard happy-path task count — `AddApplianceWizardTest` already covers this.
- Full wizard happy-path flow through the AI step — wizard tests here invoke `confirm()` directly with pre-built state; no `Prism::fake()` needed.
- Wiring CI gates — Phase 4.

## Implementation Approach

1. Extract `MaintenanceTask::calculateNextDueAt()` — eliminates duplication and enables unit testing.
2. Add `freezeTime()` to `ApplianceTestCase` + write unit tests for the extracted helper.
3. Write wizard integration tests (`WizardCalculationTest.php`).
4. Add `fixed_calendar` integration tests to `RecordTaskCompletionTest.php`.
5. Fill in `test-plan.md §6.1` cookbook.

## Critical Implementation Details

**Extraction default-arm contract**: `RecordTaskCompletion`'s `default` arm (`default => $task->next_due_at`) must remain in place after refactoring — it preserves `next_due_at` for metric tasks that reach the action via paths that bypass `scopeCalendar`. The extracted helper is NOT called in the default arm. Only the 4 calendar-unit arms are replaced with a single call to `MaintenanceTask::calculateNextDueAt()`.

**Wizard default arm**: The wizard currently has `default => $anchorDate->copy()->addMonths(...)`. After extraction this arm is removed — the helper throws on unknown units, which is acceptable because the wizard validates `interval_unit` before calling `confirm()`. The wizard never receives metric task data through its normal AI-generated flow.

**`freezeTime()` placement**: Must be called immediately after `parent::setUp()` and before any factory calls, so factory timestamps (`created_at`, `updated_at`, and any `now()`-based defaults) are also frozen.

---

## Phase 1: Extract `MaintenanceTask::calculateNextDueAt()` Static Helper

### Overview

Add a pure static method to the `MaintenanceTask` model that encapsulates the calendar-unit interval arithmetic. Replace the two inline `match` blocks in production code with calls to this method.

### Changes Required

#### 1. `MaintenanceTask` model — add static helper

**File**: `app/Models/MaintenanceTask.php`

**Intent**: Add a public static method that computes the next-due Carbon date from a known anchor, an interval unit, and an interval value. Making it static and pure (no DB access, no model-instance state) allows it to be unit-tested without a database.

**Contract**: `public static function calculateNextDueAt(Carbon $anchor, string $unit, int $value): Carbon` — implements the four-arm `match($unit)` using `addDays/Weeks/Months/Years` on `$anchor->copy()` and throws `\InvalidArgumentException` on any unit not in `['days', 'weeks', 'months', 'years']`. Returns the computed Carbon instance.

---

#### 2. `RecordTaskCompletion` — replace inline match arms

**File**: `app/Actions/RecordTaskCompletion.php`

**Intent**: Replace the four calendar-unit arms in the inline `match($task->interval_unit)` block (lines 31–37) with a single call to `MaintenanceTask::calculateNextDueAt($completedAt, $task->interval_unit, (int)$task->interval_value)`. The `default` arm that preserves `$task->next_due_at` for metric tasks must remain unchanged.

**Contract**: After this change, lines 31–37 collapse to:
```php
$task->next_due_at = match ($task->interval_unit) {
    'days', 'weeks', 'months', 'years' => MaintenanceTask::calculateNextDueAt(
        $completedAt, $task->interval_unit, (int) $task->interval_value
    ),
    default => $task->next_due_at,
};
```

---

#### 3. Wizard `confirm()` — replace inline match arms

**File**: `resources/views/livewire/pages/appliances/create.blade.php`

**Intent**: Replace the four-arm `match($task['interval_unit'])` block (lines 201–207) with a single call to `MaintenanceTask::calculateNextDueAt($anchorDate, $task['interval_unit'], (int)$task['interval_value'])`. The `default` arm (which currently falls through to `addMonths`) is removed; the helper's `InvalidArgumentException` is the correct failure mode for an unrecognised unit at this point.

**Contract**: Lines 201–207 collapse to a single assignment. The `$anchorDate` selection logic (lines 197–199) and the anchor-type storage routing (lines 209–220) are unchanged.

---

### Success Criteria

#### Automated Verification

- PHPStan at level 6: `./vendor/bin/phpstan analyse` — no new violations
- Code style: `./vendor/bin/pint --test` — no violations
- All existing tests pass: `composer test`

#### Manual Verification

- Dashboard shows the same due dates as before the extraction (no observable behavior change)
- Tinker confirms the extracted helper: `MaintenanceTask::calculateNextDueAt(Carbon\Carbon::today(), 'months', 6)->toDateString()` returns `today + 6 months`

**Implementation Note**: After completing Phase 1 and all automated verification passes, pause here for manual confirmation before proceeding.

---

## Phase 2: Add `freezeTime()` to `ApplianceTestCase` + Unit Tests for the Extracted Helper

### Overview

Extend `ApplianceTestCase` to freeze time (consistent with `DashboardTestCase`), then write a unit test file for `MaintenanceTask::calculateNextDueAt()` — the first true unit test in this project for business logic extracted from a Livewire component.

### Changes Required

#### 1. `ApplianceTestCase` — add `freezeTime()`

**File**: `tests/Feature/Appliances/ApplianceTestCase.php`

**Intent**: Add `$this->freezeTime()` to `setUp()` immediately after `parent::setUp()` and before fixture creation, so all wizard integration tests get a deterministic `now()`.

**Contract**: The `setUp()` body becomes: `parent::setUp()` → `$this->freezeTime()` → existing user/household/pivot/actingAs setup unchanged. The existing `AddApplianceWizardTest::test_happy_path_creates_appliance_and_tasks` must still pass.

---

#### 2. Unit test file for the extracted helper

**File**: `tests/Unit/Models/MaintenanceTaskCalculationTest.php` (new)

**Intent**: Prove that `MaintenanceTask::calculateNextDueAt()` returns the exact expected Carbon date for all 4 calendar units with a known anchor, and throws `\InvalidArgumentException` on an unknown unit.

**Contract**: Extends `PHPUnit\Framework\TestCase` directly (no Laravel bootstrapping — follows the convention in `tests/Unit/ExampleTest.php`). Uses `Carbon\Carbon::parse('2024-01-15')` as the fixed anchor throughout, so expected values are constant regardless of test-run date. Five test methods:

| Method | anchor | unit | value | expected |
|---|---|---|---|---|
| `test_calculates_next_due_at_for_days` | 2024-01-15 | days | 30 | 2024-02-14 |
| `test_calculates_next_due_at_for_weeks` | 2024-01-15 | weeks | 2 | 2024-01-29 |
| `test_calculates_next_due_at_for_months` | 2024-01-15 | months | 6 | 2024-07-15 |
| `test_calculates_next_due_at_for_years` | 2024-01-15 | years | 1 | 2025-01-15 |
| `test_throws_on_unknown_unit` | 2024-01-15 | hours | 1000 | `InvalidArgumentException` |

Each test calls `MaintenanceTask::calculateNextDueAt($anchor, $unit, $value)` and asserts `$result->toDateString() === $expected` (or `expectException` for the last case).

---

### Success Criteria

#### Automated Verification

- `composer test --filter ApplianceTestCase` — no failures (existing wizard tests unchanged)
- `composer test --filter MaintenanceTaskCalculationTest` — all 5 new tests pass
- PHPStan + Pint — no violations

#### Manual Verification

- Existing `test_happy_path_creates_appliance_and_tasks` still passes after `ApplianceTestCase` change

**Implementation Note**: After Phase 2 passes, pause for manual confirmation before proceeding.

---

## Phase 3: Integration Tests — Wizard `confirm()` Path

### Overview

Create `WizardCalculationTest.php` with 12 test methods proving the wizard `confirm()` method produces the exact expected `next_due_at` for all 4 calendar units across 3 anchor scenarios. Tests invoke `confirm` by setting Volt component state directly and dispatching the action — no Prism AI step involved.

### Changes Required

#### 1. `WizardCalculationTest.php` — new integration test file

**File**: `tests/Feature/Appliances/WizardCalculationTest.php` (new)

**Intent**: Prove the wizard's anchor-selection and interval arithmetic produces exact `next_due_at` values, and that anchor-type storage fields (`anchor_date`, `last_completed_at`) are correctly set for each anchor scenario.

**Contract**: Extends `ApplianceTestCase`. Uses `Volt::test('pages/appliances/create')` with pre-built `tasks` state (mirroring the format the AI step would produce) and `backdate` state set to a known past date (2024-01-15). Calls `confirm` via `->call('confirm')` without going through the AI generation step. After `confirm`, queries the created `MaintenanceTask` from the database and asserts on `next_due_at`, `anchor_date`, and `last_completed_at`.

The test setup (which Volt state properties to set, what a minimal valid `tasks` entry looks like) must be derived from reading `create.blade.php:confirm()` and mirroring the fixture pattern from `AddApplianceWizardTest::test_happy_path_creates_appliance_and_tasks`.

**Test matrix — 12 methods:**

*No-backdate scenario* (`backdate` absent / `$hasDate = false`, anchor = `Carbon::today()` = frozen now):
- `test_confirm_next_due_at_for_days_with_no_backdate` → `next_due_at = now()->addDays(N)->toDateString()`
- `test_confirm_next_due_at_for_weeks_with_no_backdate`
- `test_confirm_next_due_at_for_months_with_no_backdate`
- `test_confirm_next_due_at_for_years_with_no_backdate`

*`from_last_done` + backdate* (anchor = 2024-01-15; assert `last_completed_at` is set, `anchor_date` is null):
- `test_confirm_next_due_at_for_days_with_from_last_done_anchor`
- `test_confirm_next_due_at_for_weeks_with_from_last_done_anchor`
- `test_confirm_next_due_at_for_months_with_from_last_done_anchor`
- `test_confirm_next_due_at_for_years_with_from_last_done_anchor`

*`fixed_calendar` + backdate* (anchor = 2024-01-15; assert `anchor_date` is set to 2024-01-15, `last_completed_at` is null):
- `test_confirm_next_due_at_for_days_with_fixed_calendar_anchor`
- `test_confirm_next_due_at_for_weeks_with_fixed_calendar_anchor`
- `test_confirm_next_due_at_for_months_with_fixed_calendar_anchor`
- `test_confirm_next_due_at_for_years_with_fixed_calendar_anchor`

For the backdate scenarios, the expected `next_due_at` is `Carbon::parse('2024-01-15')->addDays/Weeks/Months/Years(N)->toDateString()` — a constant, not relative to `now()`.

---

### Success Criteria

#### Automated Verification

- `composer test --filter WizardCalculationTest` — all 12 tests pass
- PHPStan + Pint — no violations
- Full suite `composer test` — no regressions

#### Manual Verification

- Tests pass reliably across multiple runs (frozen time, no flakiness from `now()`)
- Anchor storage fields are correct: `fixed_calendar` tasks have `anchor_date` set; `from_last_done` + backdate tasks have `last_completed_at` set

**Implementation Note**: Pause after Phase 3 for manual confirmation before proceeding.

---

## Phase 4: Integration Tests — `RecordTaskCompletion` `fixed_calendar` Path

### Overview

Add 4 test methods to the existing `RecordTaskCompletionTest.php` proving that `fixed_calendar` tasks produce `next_due_at = now() + interval` — identical arithmetic to `from_last_done`, documenting the architectural decision as a test assertion rather than just a code comment.

### Changes Required

#### 1. `RecordTaskCompletionTest.php` — add 4 `fixed_calendar` test methods

**File**: `tests/Feature/Dashboard/RecordTaskCompletionTest.php`

**Intent**: Add one test method per calendar unit, each creating a `MaintenanceTask` with `anchor_type='fixed_calendar'` via the factory, invoking `RecordTaskCompletion`, and asserting `next_due_at == now() + interval`. The expected assertions are identical to the existing `from_last_done` tests because `anchor_type` does not affect the mark-done calculation.

**Contract**: Four new test methods following the exact pattern of existing tests (`test_recalculates_next_due_at_for_days` etc.), differing only in the factory call adding `'anchor_type' => 'fixed_calendar'`. `DashboardTestCase::freezeTime()` already handles deterministic time — no additional setup needed. Methods:
- `test_recalculates_next_due_at_for_days_with_fixed_calendar_anchor`
- `test_recalculates_next_due_at_for_weeks_with_fixed_calendar_anchor`
- `test_recalculates_next_due_at_for_months_with_fixed_calendar_anchor`
- `test_recalculates_next_due_at_for_years_with_fixed_calendar_anchor`

---

### Success Criteria

#### Automated Verification

- `composer test --filter RecordTaskCompletionTest` — all 9 tests pass (5 existing + 4 new)
- PHPStan + Pint — no violations

#### Manual Verification

- No observable change to dashboard mark-done behavior

**Implementation Note**: Pause after Phase 4 for manual confirmation before proceeding to the cookbook update.

---

## Phase 5: Update `test-plan.md §6.1` Cookbook

### Overview

Fill in the `§6.1 Adding a unit test for business logic` placeholder with the concrete pattern established by this phase. §6.1 becomes the canonical answer to "how do I add a unit test for pure calculation logic in this project?"

### Changes Required

#### 1. `test-plan.md §6.1` — replace placeholder

**File**: `context/foundation/test-plan.md`

**Intent**: Replace `TBD — see §3 Phase 1 (...)` under §6.1 with a self-contained cookbook entry documenting the two-layer pattern: (a) unit test for the extracted static helper; (b) integration test for the Livewire `confirm()` wiring.

**Contract**: §6.1 must document:
1. **When to extract**: Extract pure arithmetic to a static model method when the logic is shared between two or more call sites, or when the test must be a unit test (no DB needed).
2. **Unit test pattern**: Extend `PHPUnit\Framework\TestCase` (no Laravel). Use a fixed past date as anchor. Assert `->toDateString()` equality. Reference `tests/Unit/Models/MaintenanceTaskCalculationTest.php` as the canonical example.
3. **Integration wiring pattern**: Extend the relevant feature `TestCase` (e.g., `ApplianceTestCase`). Use `Volt::test('page/component')->set('property', value)->call('action')`. Query the created model and assert on persisted fields. Reference `tests/Feature/Appliances/WizardCalculationTest.php`.
4. **Run command**: `composer test --filter MaintenanceTaskCalculationTest` (unit) and `composer test --filter WizardCalculationTest` (integration).

---

### Success Criteria

#### Manual Verification

- §6.1 is self-contained: a future developer can add a calculation test by reading §6.1 alone, without consulting this plan
- The `test-plan.md §3 Phase 1` row `Status` can be updated to `complete` once all prior phases are done

---

## Testing Strategy

### Unit Tests

- `tests/Unit/Models/MaintenanceTaskCalculationTest.php` — 5 tests on the extracted static helper; no DB, no Livewire, fixed anchor date

### Integration Tests

- `tests/Feature/Appliances/WizardCalculationTest.php` — 12 tests covering wizard `confirm()` path (4 units × 3 anchor scenarios)
- `tests/Feature/Dashboard/RecordTaskCompletionTest.php` — 4 new tests for `fixed_calendar` anchor type in mark-done path

### Key Edge Cases Verified

- No-backdate wizard scenario uses `Carbon::today()` (frozen) as anchor — not `now()` (same when time is frozen, but explicit assertion proves the fallback path)
- `fixed_calendar` tasks in wizard: both `next_due_at` computation AND `anchor_date` storage field are asserted
- `fixed_calendar` in mark-done: assert `now() + interval`, proving anchor_type is ignored (by design)
- Unknown `interval_unit` in extracted helper: `InvalidArgumentException` is thrown

## References

- Research: `context/changes/testing-calculation-correctness/research.md`
- Test plan: `context/foundation/test-plan.md` — §3 Phase 1 row, §6.1 placeholder
- Wizard calculation code: `resources/views/livewire/pages/appliances/create.blade.php:153–241`
- Mark-done calculation code: `app/Actions/RecordTaskCompletion.php:14–41`
- Existing mark-done tests: `tests/Feature/Dashboard/RecordTaskCompletionTest.php:54–135`
- Existing wizard test: `tests/Feature/Appliances/AddApplianceWizardTest.php:17–65`
- Base class patterns: `tests/Feature/Dashboard/DashboardTestCase.php` (freezeTime), `tests/Feature/Appliances/ApplianceTestCase.php`
- Project unit test convention: `tests/Unit/ExampleTest.php`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Extract `MaintenanceTask::calculateNextDueAt()` Static Helper

#### Automated

- [x] 1.1 PHPStan level 6 — no new violations: `./vendor/bin/phpstan analyse` — 2fd8480
- [x] 1.2 Code style — no violations: `./vendor/bin/pint --test` — 2fd8480
- [x] 1.3 All existing tests pass: `composer test` — 2fd8480

#### Manual

- [x] 1.4 Dashboard shows same due dates as before extraction — 2fd8480
- [x] 1.5 Tinker confirms extracted helper returns correct date — 2fd8480

### Phase 2: Add `freezeTime()` to `ApplianceTestCase` + Unit Tests for Extracted Helper

#### Automated

- [ ] 2.1 Existing wizard test passes after `ApplianceTestCase` change: `composer test --filter ApplianceTestCase`
- [ ] 2.2 All 5 new unit tests pass: `composer test --filter MaintenanceTaskCalculationTest`
- [ ] 2.3 PHPStan + Pint — no violations

#### Manual

- [ ] 2.4 `test_happy_path_creates_appliance_and_tasks` still passes after `ApplianceTestCase` change

### Phase 3: Integration Tests — Wizard `confirm()` Path

#### Automated

- [ ] 3.1 All 12 wizard calculation tests pass: `composer test --filter WizardCalculationTest`
- [ ] 3.2 PHPStan + Pint — no violations
- [ ] 3.3 Full suite — no regressions: `composer test`

#### Manual

- [ ] 3.4 Tests pass reliably across multiple runs (no time-sensitivity)
- [ ] 3.5 Anchor storage fields verified correct for each anchor type

### Phase 4: Integration Tests — `RecordTaskCompletion` `fixed_calendar` Path

#### Automated

- [ ] 4.1 All 9 RTC tests pass (5 existing + 4 new): `composer test --filter RecordTaskCompletionTest`
- [ ] 4.2 PHPStan + Pint — no violations

#### Manual

- [ ] 4.3 No observable change to dashboard mark-done behavior

### Phase 5: Update `test-plan.md §6.1` Cookbook

#### Manual

- [ ] 5.1 §6.1 is self-contained and a future developer can follow it without reading this plan
- [ ] 5.2 `test-plan.md §3 Phase 1` status row updated to `complete`

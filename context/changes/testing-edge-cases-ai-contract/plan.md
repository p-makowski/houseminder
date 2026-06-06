# Phase 3 — Edge Cases + AI Contract Implementation Plan

## Overview

Prove dashboard date-boundary correctness (Risk #4), unconfirmed task filtering in all three calendar buckets (Risk #5), and AI contract error handling (Risk #6) with integration tests. Risk #6 requires two small production fixes before tests can assert correct behavior rather than documenting bugs.

## Current State Analysis

**Risk #4 (date boundaries):** Three calendar bucket queries are inline in `dashboard.blade.php` as `#[Computed]` properties. Operators: `overdue` → strict `<`; `dueThisWeek` → `whereBetween` (inclusive `>= now() AND <= now()+7d`); `upcoming` → strict `>`. Existing tests use `subDay()`, `addDays(3)`, `addDays(30)` — no exact boundary values tested. The `test-plan.md §2` Risk #4 guidance incorrectly states "task due exactly today → overdue"; the correct statement is "task due at exactly `now()` → dueThisWeek" (overdue is strict `<`).

**Risk #5 (unconfirmed tasks):** `is_confirmed = true` filter is in `scopeCalendar` and `scopeMetric` at the Eloquent query level — not in the Blade template. One test exists for the overdue path (`DashboardPageTest:test_unconfirmed_task_does_not_appear`). The dueThisWeek and upcoming paths are untested (same scope applies to all three, but no test proves it for those windows).

**Risk #6 (AI contract):** `GenerateMaintenancePlan::__invoke()` returns raw Prism output with no PHP-level field validation beyond `?? []`. When Prism returns zero tasks, `fetchSuggestions()` clears `$aiError` and sets `$tasks = []` — the user sees a blank step 2 with no error until the Next click. When Prism returns tasks with missing fields, the wizard proceeds to step 4 before validation fails. The `\Throwable` fallback in `fetchSuggestions()` (lines 118-120) is untested. Only the `PrismException` path has coverage.

## Desired End State

- `GenerateMaintenancePlan::__invoke()` validates each task has all four required fields (`name`, `description`, `interval_value`, `interval_unit`) and throws `\InvalidArgumentException` on failure.
- `fetchSuggestions()` in the wizard sets `$aiError` immediately when the action returns zero tasks — no blank step 2.
- `tests/Feature/Appliances/AiContractTest.php` proves three failure modes: zero-tasks immediate error, missing-fields error, and `\Throwable` fallback.
- `tests/Feature/Dashboard/DashboardBoundaryTest.php` proves exact boundary behavior at `now()` and `now()->addDays(7)` for all three calendar buckets.
- `DashboardPageTest.php` gains two new unconfirmed-task absence tests for the dueThisWeek and upcoming buckets.
- `test-plan.md §2` Risk #4 guidance is corrected to reflect the actual operators.
- `test-plan.md §6.2` is filled in with the `Prism::fake()` integration-test pattern.

### Key Discoveries

- `overdue` is strict `<`, so a task due at exactly `now()` is NOT overdue — it falls in `dueThisWeek`. The test-plan guidance contradicts this; the correction is required before boundary tests are written to avoid anchoring to the wrong assertion.
- Two independent `now()` calls (one per `#[Computed]` property) create a theoretical real-time race window at the exact instant. `freezeTime()` eliminates this in tests — both properties see the same frozen `now()`.
- `fetchSuggestions()` explicitly clears `$aiError` after calling the action (line ~113). The zero-tasks guard must be inserted before this clear and return early — otherwise the clear overwrites the newly set error.
- The `\Throwable` catch block already exists in `fetchSuggestions()` (lines 118-120); after adding field validation to the action, `\InvalidArgumentException` from the action will be handled by this block without any new catch needed.
- `Prism::fake()` is the correct tool for these tests (not action mocking), since the goal is to exercise the real action → component path. Follow the fixture pattern in `AddApplianceWizardTest:21`.

## What We're NOT Doing

- Testing the metric dashboard section for unconfirmed tasks — `scopeMetric` applies the same `is_confirmed` filter; the structural argument holds.
- Testing `km` metric tasks — excluded from Phase 3 per `test-plan.md §3`.
- Fixing the multi-step broken-cards UX when missing-field tasks reach the Blade — the action's new throw prevents this path from being reachable after Phase 1.
- Adding field validation to the Blade task card rendering — validation belongs at the action boundary.
- Wiring CI gates — Phase 4.

## Implementation Approach

1. Fix production code first (Phase 1) so Phase 2 tests assert correct behavior, not current bugs.
2. Write Risk #6 tests using `Prism::fake()` fixtures against the fixed behavior (Phase 2).
3. Write Risk #4 boundary tests and correct the test-plan guidance (Phase 3).
4. Write Risk #5 unconfirmed tests for dueThisWeek and upcoming (Phase 4).
5. Fill in §6.2 cookbook (Phase 5).

## Critical Implementation Details

**fetchSuggestions() fix placement**: The current code sequence is `$this->tasks = array_map(...)` → `$this->aiError = null` → `$this->aiLoading = false`. The zero-tasks guard must be inserted after the `array_map` assignment and before the `$this->aiError = null` line, with an early return that also sets `$this->aiLoading = false`. If the guard is inserted after the `null` clear, the error is immediately wiped.

**\Throwable block message**: Before writing Phase 2 tests, verify that the existing `\Throwable` catch block in `fetchSuggestions()` (lines 118-120) sets a non-empty, user-readable `$aiError` string. If the current block sets an empty string or null, update it to a descriptive message (e.g., `'An unexpected error occurred. Please try again.'`) — Phase 2 tests assert `$aiError` is non-empty.

---

## Phase 1: Risk #6 Production Fixes

### Overview

Add an immediate zero-tasks guard to `fetchSuggestions()` and PHP-level field validation to `GenerateMaintenancePlan`. Both are prerequisites for Phase 2 tests to assert correct rather than broken behavior.

### Changes Required

#### 1. Wizard — zero-tasks immediate error

**File**: `resources/views/livewire/pages/appliances/create.blade.php`

**Intent**: After `fetchSuggestions()` receives the action's result and assigns it to `$this->tasks`, check if the array is empty. If so, set `$this->aiError` with a user-facing message and return early — before the `$this->aiError = null` clear that currently follows. This eliminates the blank, featureless step 2 that appears when the AI generates no tasks.

**Contract**: Insert guard after `$this->tasks = array_map(...)` (line ~112) and before `$this->aiError = null` (line ~113). Guard sets `$this->aiError` to a descriptive string (e.g., `'No maintenance tasks were generated. Please try again.'`), sets `$this->aiLoading = false`, and returns. The subsequent `$this->aiError = null` line is only reached on a non-empty task result.

---

#### 2. Action — PHP field validation

**File**: `app/Actions/GenerateMaintenancePlan.php`

**Intent**: Before returning the Prism-provided task array, iterate over each task and verify it contains all four required fields: `name`, `description`, `interval_value`, `interval_unit`. Throw `\InvalidArgumentException` if any task is missing a required field. This makes the action contract explicit, prevents structurally broken tasks from reaching the wizard's Blade rendering, and makes the failure testable at the action level.

**Contract**: Validation is added between `$tasks = $response->structured['tasks'] ?? []` and the `return $tasks` statement. Throws `\InvalidArgumentException` (e.g., `'AI returned a task missing required fields.'`). The throw propagates to `fetchSuggestions()`'s existing `\Throwable` catch block, which surfaces a user-visible error.

---

### Success Criteria

#### Automated Verification

- All existing tests pass: `composer test`
- PHPStan level 6: `./vendor/bin/phpstan analyse` — no new violations
- Code style: `./vendor/bin/pint --test` — no violations

#### Manual Verification

- In a dev environment, confirm that triggering AI generation with a forced-empty response shows an error message immediately in step 2 (not a blank page)

**Implementation Note**: After Phase 1 passes automated checks, pause for manual confirmation before proceeding.

---

## Phase 2: Risk #6 Tests — AI Contract

### Overview

Create `AiContractTest.php` with three tests proving that each AI failure mode surfaces a non-empty `$aiError` in the wizard component.

### Changes Required

#### 1. New test file for AI contract failure modes

**File**: `tests/Feature/Appliances/AiContractTest.php` (new)

**Intent**: Prove three distinct AI failure modes each surface a user-facing `$aiError` in the wizard, using `Prism::fake()` with crafted `StructuredResponseFake` fixtures to exercise the real action → component path (not action mocks). Using `Prism::fake()` ensures the test covers the real `GenerateMaintenancePlan` deserialization and the new field validation code.

**Contract**: Extends `ApplianceTestCase` (user, household, appliance type, actingAs — frozen time inherited). Follows the `Prism::fake()` fixture setup pattern from `AddApplianceWizardTest:21`. Each test mounts the Volt component, sets the minimum required component properties that `fetchSuggestions()` reads (derive from `create.blade.php:fetchSuggestions()` — typically `applianceName`, `applianceModel`, `typeId`), calls `->call('fetchSuggestions')`, and asserts `aiError` is a non-empty string.

Three test methods:

| Method | `StructuredResponseFake` fixture | What it triggers |
|---|---|---|
| `test_zero_tasks_shows_immediate_error` | `structured: ['tasks' => []]` | zero-tasks guard in `fetchSuggestions()` |
| `test_missing_required_field_shows_error` | `structured: ['tasks' => [['description' => 'x', 'interval_value' => 3, 'interval_unit' => 'months']]]` (no `name` key) | `\InvalidArgumentException` from action validation → `\Throwable` catch |
| `test_throwable_fallback_shows_error` | `structured: ['tasks' => 'not-an-array']` | `\TypeError` or `\InvalidArgumentException` in action when iterating non-array → `\Throwable` catch |

For each, assert: `->assertSet('aiError', fn($v) => !empty($v))` or `->assertSee(...)` on the error message.

---

### Success Criteria

#### Automated Verification

- `composer test --filter AiContractTest` — all 3 tests pass
- PHPStan + Pint — no violations
- Full suite `composer test` — no regressions

#### Manual Verification

- Each test covers a distinct failure trigger (not three variants of the same code path)
- `aiError` is a user-readable string in all three cases (not an internal PHP exception message)

**Implementation Note**: Pause after Phase 2 for manual confirmation before proceeding.

---

## Phase 3: Risk #4 — Boundary Tests + Test-Plan Correction

### Overview

Correct the erroneous Risk #4 guidance in `test-plan.md §2` and add four boundary integration tests that prove exact operator semantics for all three calendar buckets.

### Changes Required

#### 1. Correct test-plan.md Risk #4 guidance

**File**: `context/foundation/test-plan.md`

**Intent**: Update the Risk #4 row in the `§4 Risk Response Guidance` table. The current "What would prove protection" text states "Task due exactly today → 'overdue'" — this is incorrect. The actual `overdue` operator is strict `<`, so a task due at exactly `now()` lands in `dueThisWeek`. The correction makes the quality contract accurate and prevents future tests from being written against the wrong boundary assumption.

**Contract**: In the §2 Risk #4 risk response guidance row, update "What would prove protection" to state the actual boundary semantics: "Task due at exactly `now()` → dueThisWeek (NOT overdue); task due at `now()->subSecond()` → overdue; task due at exactly `now()->addDays(7)` → dueThisWeek (NOT upcoming); task due at `now()->addDays(7)->addSecond()` → upcoming." Update "Must challenge" to reference the actual strict-`<` / inclusive-`whereBetween` / strict-`>` operators.

---

#### 2. New boundary test file

**File**: `tests/Feature/Dashboard/DashboardBoundaryTest.php` (new)

**Intent**: Prove exact boundary values at the overdue/dueThisWeek transition and the dueThisWeek/upcoming transition — the values most likely to regress if an operator is ever changed from strict to inclusive or vice versa.

**Contract**: Extends `DashboardTestCase` (which provides `freezeTime()` in setUp). Each test creates one confirmed calendar task with a specific `next_due_at`, renders the dashboard via `Volt::test`, and asserts both that the task appears in the expected bucket AND that it does not appear in the adjacent bucket. Derive the assertion pattern from `DashboardPageTest.php:20-57`. Use unique task names per test to prevent cross-test matches.

| Method | `next_due_at` | Expected bucket | Must NOT appear in |
|---|---|---|---|
| `test_task_due_exactly_now_is_in_due_this_week` | `now()` | dueThisWeek | overdue |
| `test_task_due_one_second_ago_is_overdue` | `now()->subSecond()` | overdue | dueThisWeek |
| `test_task_due_exactly_seven_days_from_now_is_in_due_this_week` | `now()->addDays(7)` | dueThisWeek | upcoming |
| `test_task_due_one_second_past_seven_days_is_upcoming` | `now()->addDays(7)->addSecond()` | upcoming | dueThisWeek |

---

### Success Criteria

#### Automated Verification

- `composer test --filter DashboardBoundaryTest` — all 4 tests pass
- PHPStan + Pint — no violations
- Full suite `composer test` — no regressions

#### Manual Verification

- Each test asserts BOTH presence in the expected bucket AND absence from the adjacent bucket
- `test-plan.md §2` Risk #4 row no longer contains the erroneous "today → overdue" statement

**Implementation Note**: Pause after Phase 3 for manual confirmation before proceeding.

---

## Phase 4: Risk #5 — Unconfirmed Tests for dueThisWeek + Upcoming

### Overview

Add two test methods to `DashboardPageTest.php` proving unconfirmed task filtering in the dueThisWeek and upcoming buckets.

### Changes Required

#### 1. Two new test methods in `DashboardPageTest.php`

**File**: `tests/Feature/Dashboard/DashboardPageTest.php`

**Intent**: Prove that unconfirmed tasks (`is_confirmed = false`) with `next_due_at` in the dueThisWeek and upcoming windows do not appear on the dashboard — closing the two untested rows in the Risk #5 coverage matrix. The existing test already proves the overdue path; these two close the remaining gap.

**Contract**: Two new test methods following the pattern of `test_unconfirmed_task_does_not_appear` (lines 75-86). Each seeds one `MaintenanceTask` with `is_confirmed = false` and a `next_due_at` well inside the target window, renders the dashboard, and asserts the task name is absent.

| Method | `next_due_at` | Window targeted |
|---|---|---|
| `test_unconfirmed_task_does_not_appear_in_due_this_week` | `now()->addDays(3)` | dueThisWeek |
| `test_unconfirmed_task_does_not_appear_in_upcoming` | `now()->addDays(30)` | upcoming |

---

### Success Criteria

#### Automated Verification

- `composer test --filter DashboardPageTest` — all tests pass (existing + 2 new)
- PHPStan + Pint — no violations

#### Manual Verification

- Tests pass reliably across multiple runs (frozen time via `DashboardTestCase`)

**Implementation Note**: Pause after Phase 4 for manual confirmation before proceeding to the cookbook.

---

## Phase 5: Update `test-plan.md §6.2` Cookbook

### Overview

Fill in the `§6.2` placeholder with the `Prism::fake()` integration-test pattern established by Phase 2.

### Changes Required

#### 1. `test-plan.md §6.2` — replace placeholder

**File**: `context/foundation/test-plan.md`

**Intent**: Replace `TBD — see §3 Phase 3 (...)` under §6.2 with a self-contained cookbook entry documenting how to use `Prism::fake()` with empty and malformed response fixtures to guard against AI contract drift. §6.2 becomes the canonical answer to "how do I add a test for a new Prism failure mode in this project?"

**Contract**: §6.2 must document:
1. **When to use `Prism::fake()` vs action mocking**: use `Prism::fake()` when the test must exercise the real `GenerateMaintenancePlan` action path (contract and field validation tests); use `$this->mock(GenerateMaintenancePlan::class)` only when testing the component's response to a specific action exception (e.g., `PrismException` retry logic).
2. **StructuredResponseFake fixture setup**: how to configure an empty task array, a missing-field task, and a non-iterable response to trigger each failure mode.
3. **Volt component call pattern**: `->call('fetchSuggestions')` and how to assert `aiError` state.
4. **Run command**: `composer test --filter AiContractTest`.
5. **Canonical example**: `tests/Feature/Appliances/AiContractTest.php`.

---

### Success Criteria

#### Manual Verification

- §6.2 is self-contained: a future developer can add a Prism failure-mode test by reading §6.2 alone, without consulting this plan
- `test-plan.md §3 Phase 3` status row can be updated to `complete` once all prior phases are done

---

## Testing Strategy

### Integration Tests

- `tests/Feature/Appliances/AiContractTest.php` — 3 tests (zero-tasks immediate error, missing-fields error, \Throwable fallback)
- `tests/Feature/Dashboard/DashboardBoundaryTest.php` — 4 tests (exact boundary at `now()` and `now()->addDays(7)` for overdue/dueThisWeek/upcoming)
- `tests/Feature/Dashboard/DashboardPageTest.php` — 2 new unconfirmed-task absence tests (dueThisWeek, upcoming windows)

### Key Edge Cases Verified

- Zero-tasks: `fetchSuggestions()` sets `$aiError` immediately — no blank step 2 state
- Missing-fields: action throws before returning broken tasks; `\Throwable` catch surfaces user error
- `\Throwable` fallback: non-array structured response triggers the fallback path
- Boundary `now()` exactly: lands in `dueThisWeek`, NOT `overdue` (strict `<` confirmed)
- Boundary `now()->addDays(7)` exactly: lands in `dueThisWeek`, NOT `upcoming` (strict `>` confirmed)
- Unconfirmed tasks in all three calendar windows: none appear in dashboard output

## References

- Research: `context/changes/testing-edge-cases-ai-contract/research.md`
- Test plan: `context/foundation/test-plan.md` — §3 Phase 3 row, §6.2 placeholder
- Dashboard bucket queries: `resources/views/livewire/pages/dashboard.blade.php:33-63`
- `fetchSuggestions()`: `resources/views/livewire/pages/appliances/create.blade.php:107-121`
- Action return + validation point: `app/Actions/GenerateMaintenancePlan.php:68`
- Existing bucket tests: `tests/Feature/Dashboard/DashboardPageTest.php:20-57`
- Existing unconfirmed test: `tests/Feature/Dashboard/DashboardPageTest.php:75-86`
- `Prism::fake()` fixture pattern: `tests/Feature/Appliances/AddApplianceWizardTest.php:21`
- Action mock pattern: `tests/Feature/Appliances/AiFailureTest.php:18`
- `DashboardTestCase` (freezeTime): `tests/Feature/Dashboard/DashboardTestCase.php`
- `ApplianceTestCase`: `tests/Feature/Appliances/ApplianceTestCase.php`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Risk #6 Production Fixes

#### Automated

- [x] 1.1 All existing tests pass: `composer test` — 8a14198
- [x] 1.2 PHPStan level 6 — no new violations: `./vendor/bin/phpstan analyse` — 8a14198
- [x] 1.3 Code style — no violations: `./vendor/bin/pint --test` — 8a14198

#### Manual

- [x] 1.4 Zero-tasks fix verified: forced empty AI response shows error immediately in step 2 — 8a14198

### Phase 2: Risk #6 Tests — AI Contract

#### Automated

- [x] 2.1 All 3 AI contract tests pass: `composer test --filter AiContractTest` — 67ced5c
- [x] 2.2 PHPStan + Pint — no violations — 67ced5c
- [x] 2.3 Full suite — no regressions: `composer test` — 67ced5c

#### Manual

- [x] 2.4 Each test covers a distinct failure trigger, not three variants of the same code path — 67ced5c
- [x] 2.5 `aiError` is a user-readable string in all 3 cases — 67ced5c

### Phase 3: Risk #4 Boundary Tests + Test-Plan Correction

#### Automated

- [x] 3.1 All 4 boundary tests pass: `composer test --filter DashboardBoundaryTest`
- [x] 3.2 PHPStan + Pint — no violations
- [x] 3.3 Full suite — no regressions: `composer test`

#### Manual

- [x] 3.4 Each boundary test asserts presence in expected bucket AND absence from adjacent bucket
- [x] 3.5 `test-plan.md §2` Risk #4 row no longer contains the erroneous "today → overdue" statement

### Phase 4: Risk #5 — Unconfirmed Tests

#### Automated

- [ ] 4.1 All DashboardPageTest tests pass: `composer test --filter DashboardPageTest`
- [ ] 4.2 PHPStan + Pint — no violations

#### Manual

- [ ] 4.3 Tests pass reliably across multiple runs (frozen time)

### Phase 5: §6.2 Cookbook

#### Manual

- [ ] 5.1 §6.2 is self-contained and citable without reading this plan
- [ ] 5.2 `test-plan.md §3 Phase 3` status row updated to `complete`

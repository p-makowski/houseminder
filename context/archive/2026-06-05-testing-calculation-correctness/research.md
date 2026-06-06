---
date: 2026-06-05T00:00:00+00:00
researcher: p-makowski
git_commit: 9bbb559077cf56c8deff9aaa22fdcf2902970e46
branch: main
repository: houseminder
topic: "next_due_at calculation correctness — wizard confirm() vs RecordTaskCompletion paths"
tags: [research, codebase, maintenance-tasks, next_due_at, calculation, wizard, record-task-completion]
status: complete
last_updated: 2026-06-05
last_updated_by: p-makowski
---

# Research: next_due_at Calculation Correctness

**Date**: 2026-06-05
**Researcher**: p-makowski
**Git Commit**: 9bbb559077cf56c8deff9aaa22fdcf2902970e46
**Branch**: main
**Repository**: houseminder

---

## Research Question

Risk #2 from `test-plan.md §2`: prove that given `anchor_date + interval_unit + interval_value`, the exact expected `next_due_at` is produced for all 4 calendar units (days, weeks, months, years) × both anchor types (from_last_done, fixed_calendar). Challenge whether `wizard confirm()` and `RecordTaskCompletion` share the calculation or duplicate it independently. Identify which code path is exercised by the existing tests and which is not.

---

## Summary

**The two paths duplicate the calculation independently — there is no shared helper.** Both use an identical `match(interval_unit)` block with `addDays/Weeks/Months/Years`, but on different base dates: the wizard uses a user-supplied anchor date (or `Carbon::today()` as fallback), while `RecordTaskCompletion` always uses `now()`.

**`anchor_type` does not affect the arithmetic.** It controls only which storage fields are set in the wizard (`anchor_date` for `fixed_calendar`; `last_completed_at` for `from_last_done`). At mark-done, both anchor types advance from `now()` — this is an explicit historical design decision (see §Historical Context).

**Test coverage is asymmetric.** `RecordTaskCompletion` is well-covered for all 4 calendar units with exact-date assertions and frozen time. The wizard `confirm()` path has **zero assertions on `next_due_at`** — the existing wizard test counts tasks created but never checks the computed value.

---

## Detailed Findings

### 1. Calculation Code — Wizard Path

**File**: `resources/views/livewire/pages/appliances/create.blade.php`
**Method**: `confirm()` — lines 153–241
**Anchor date selection** (lines 197–199):
```php
$anchorDate = $hasDate
    ? Carbon::parse($backdate['date'])
    : Carbon::today();
```
**Arithmetic** (lines 201–207):
```php
$nextDueAt = match ($task['interval_unit']) {
    'days'   => $anchorDate->copy()->addDays((int) $task['interval_value']),
    'weeks'  => $anchorDate->copy()->addWeeks((int) $task['interval_value']),
    'months' => $anchorDate->copy()->addMonths((int) $task['interval_value']),
    'years'  => $anchorDate->copy()->addYears((int) $task['interval_value']),
    default  => $anchorDate->copy()->addMonths((int) $task['interval_value']),
};
```
**anchor_type storage** (lines 209–220):
```php
$isFromLastDone  = $task['anchor_type'] === 'from_last_done';
$isFixedCalendar = $task['anchor_type'] === 'fixed_calendar';
// ...
'anchor_date'       => $isFixedCalendar ? $anchorDate : null,
'last_completed_at' => ($isFromLastDone && $hasDate) ? $anchorDate : null,
'next_due_at'       => $nextDueAt,   // same value regardless of anchor_type
```

**Key insight**: `anchor_type` switches which storage field is set, but `$nextDueAt` is the same expression for both types. The arithmetic is identical.

---

### 2. Calculation Code — Mark-Done Path

**File**: `app/Actions/RecordTaskCompletion.php`
**Method**: `__invoke()` — lines 14–41
**Base date** (line 21):
```php
$completedAt = now();
```
**Arithmetic** (lines 31–37):
```php
$task->next_due_at = match ($task->interval_unit) {
    'days'   => $completedAt->copy()->addDays((int) $task->interval_value),
    'weeks'  => $completedAt->copy()->addWeeks((int) $task->interval_value),
    'months' => $completedAt->copy()->addMonths((int) $task->interval_value),
    'years'  => $completedAt->copy()->addYears((int) $task->interval_value),
    default  => $task->next_due_at,   // metric tasks: preserve existing value
};
```

**Metric task guard**: `scopeCalendar` (`interval_unit IN ('days','weeks','months','years')`) is applied when the dashboard fetches the task before passing it to this action — metric task IDs cannot reach mark-done through normal Livewire flow.

**No `anchor_type` check**: The mark-done path never reads `anchor_type` or `anchor_date`. Both `from_last_done` and `fixed_calendar` tasks advance from `now()`.

---

### 3. Shared Helper vs Duplication

**Finding: no shared abstraction exists.**

The `match(interval_unit)` block is independently authored in both files. The logic is functionally identical (same four arms, same `addDays/Weeks/Months/Years` calls) but copy-pasted, not extracted. There is no `CalculateNextDueDate` service, trait, or model method.

**Implication for tests**: a unit test of an extracted pure function cannot exist yet. Tests must be integration-level (wizard test or action test) to exercise the actual code paths. If the plan decides to extract, a unit test layer becomes available.

**Duplication risk**: a future correction to one file's `match` (e.g., a leap-year edge case or timezone fix) may silently not be applied to the other. Tests for both paths are necessary to catch this divergence.

---

### 4. Fields Read / Written During Calculation

| Field | Wizard reads | Wizard writes | Mark-done reads | Mark-done writes |
|---|---|---|---|---|
| `interval_unit` | YES (match key) | — | YES (match key) | — |
| `interval_value` | YES (operand) | — | YES (operand) | — |
| `anchor_type` | YES (for storage routing) | — | NO | — |
| `anchor_date` | NO (not read back) | YES (fixed_calendar only) | NO | NO |
| `last_completed_at` | NO (not read back) | YES (from_last_done + backdate) | NO | YES (= now()) |
| `next_due_at` | — | YES (= computed value) | YES (default arm only, preserved) | YES (= now() + interval) |
| `next_due_at_value` | — | NOT SET by wizard | — | NOT touched |

---

### 5. Data Model Reference

**Migration**: `database/migrations/2026_06_01_000005_create_maintenance_tasks_table.php`
(addendum: `2026_06_04_090811_add_description_to_maintenance_tasks.php`)

Relevant columns:

| Column | Type | Nullable | Default |
|---|---|---|---|
| `interval_value` | unsignedInteger | NO | — |
| `interval_unit` | enum: days/weeks/months/years/hours/km | NO | — |
| `anchor_type` | enum: from_last_done/fixed_calendar | NO | `'from_last_done'` |
| `anchor_date` | date | YES | NULL |
| `last_completed_at` | dateTime | YES | NULL |
| `next_due_at` | dateTime | YES | NULL |
| `next_due_at_value` | double | YES | NULL |
| `is_confirmed` | boolean | NO | `false` |

No check constraints beyond enum definitions. `next_due_at_value` is authoritative for metric units; `next_due_at` is authoritative for calendar units (days/weeks/months/years) — per lessons.md rule.

**Factory defaults** (`database/factories/MaintenanceTaskFactory.php`):
```php
'interval_value' => 6,
'interval_unit'  => 'months',
'anchor_type'    => 'from_last_done',
'anchor_date'    => null,
'next_due_at'    => now()->addMonths(6),   // loose — not pinned to test time
```

The factory `next_due_at` uses `now()` without freezing, so factory-created tasks will have slightly different dates depending on when the test runs unless the test freezes time.

---

### 6. Existing Test Coverage Map

#### RecordTaskCompletionTest.php — `tests/Feature/Dashboard/RecordTaskCompletionTest.php`

Tests the action directly. Base class: `DashboardTestCase`, which calls `$this->freezeTime()` in `setUp()` — `now()` is deterministic.

| Test method | Line | interval_unit | anchor_type | Assertion on next_due_at | Result |
|---|---|---|---|---|---|
| `test_recalculates_next_due_at_for_days` | 54 | days | from_last_done (implicit) | Exact: `now()->addDays(30)->toDateString()` | ✅ covered |
| `test_recalculates_next_due_at_for_weeks` | 71 | weeks | from_last_done (implicit) | Exact: `now()->addWeeks(2)->toDateString()` | ✅ covered |
| `test_recalculates_next_due_at_for_months` | 87 | months | from_last_done (implicit) | Exact: `now()->addMonths(6)->toDateString()` | ✅ covered |
| `test_recalculates_next_due_at_for_years` | 103 | years | from_last_done (implicit) | Exact: `now()->addYear()->toDateString()` | ✅ covered |
| `test_does_not_change_next_due_at_for_metric_task` | 119 | hours | from_last_done (implicit) | Exact: preserved unchanged | ✅ covered |

**anchor_type**: all tests use the factory default (`from_last_done`). `fixed_calendar` is never passed to `RecordTaskCompletion` in any test.

#### DashboardPageTest.php — `tests/Feature/Dashboard/DashboardPageTest.php`

| Test method | Line | What it asserts about next_due_at |
|---|---|---|
| `test_mark_done_creates_service_record_and_updates_task` | 128 | Only asserts `last_completed_at->isToday()` — no assertion on `next_due_at` |

#### AddApplianceWizardTest.php — `tests/Feature/Appliances/AddApplianceWizardTest.php`

| Test method | Line | What it asserts about next_due_at |
|---|---|---|
| `test_happy_path_creates_appliance_and_tasks` | 17 | Asserts `assertSame(2, MaintenanceTask::count())` only — zero assertion on `next_due_at` values |

**Base class**: `ApplianceTestCase`. Does **NOT** call `freezeTime()`. Any wizard test that asserts on `next_due_at` will be time-sensitive unless either ApplianceTestCase is extended to freeze time or the test uses a fixed past anchor date and asserts relative to it.

---

### 7. Coverage Gaps — Precise Matrix

**Mark-done path (RecordTaskCompletion)**:

| interval_unit | from_last_done | fixed_calendar |
|---|---|---|
| days | ✅ | ❌ (no test) |
| weeks | ✅ | ❌ (no test) |
| months | ✅ | ❌ (no test) |
| years | ✅ | ❌ (no test) |
| hours (metric) | ✅ | ❌ (no test) |
| km (metric) | ❌ | ❌ |

Note: since `anchor_type` does not affect the mark-done calculation, `fixed_calendar` tests will produce identical arithmetic — but they don't exist to prove it.

**Wizard confirm() path**:

| interval_unit | from_last_done (any backdate) | fixed_calendar (any backdate) | No backdate (Carbon::today()) |
|---|---|---|---|
| days | ❌ | ❌ | ❌ |
| weeks | ❌ | ❌ | ❌ |
| months | ❌ (count only) | ❌ | ❌ |
| years | ❌ | ❌ | ❌ |

The happy-path wizard test creates tasks with `interval_unit='months'` and `anchor_type='from_last_done'` but never reads back `next_due_at`. No anchor type × unit combination has an assertion.

---

### 8. Architectural Decision: anchor_type at mark-done

From the `dashboard-tasks-and-mark-done` archive plan, the question was explicitly raised and resolved:

> "For `fixed_calendar` anchor_type, does mark-done reset `next_due_at` to `anchor_date + N intervals`, or advance from `now()`?"

**Resolution**: Both anchor types advance from `now()`. This is **intentional** — `fixed_calendar` only controls wizard storage behavior (recording the original fixed anchor), not how subsequent completions are calculated. `RecordTaskCompletion` is anchor-type-agnostic by design.

**Implication for tests**: Tests for `fixed_calendar` in `RecordTaskCompletion` should assert that `next_due_at == now() + interval` — the same as `from_last_done`. They are not testing different arithmetic; they are proving the path is not broken for `fixed_calendar` tasks.

---

## Code References

- `resources/views/livewire/pages/appliances/create.blade.php:197–207` — wizard anchor date selection + arithmetic match
- `resources/views/livewire/pages/appliances/create.blade.php:209–220` — anchor_type storage routing
- `app/Actions/RecordTaskCompletion.php:21` — `$completedAt = now()` base date
- `app/Actions/RecordTaskCompletion.php:31–37` — mark-done arithmetic match
- `app/Models/MaintenanceTask.php` — scopes: `scopeCalendar`, `scopeMetric`, `scopeForHousehold`
- `database/migrations/2026_06_01_000005_create_maintenance_tasks_table.php` — full schema
- `database/factories/MaintenanceTaskFactory.php` — defaults (next_due_at uses loose `now()`)
- `tests/Feature/Dashboard/RecordTaskCompletionTest.php:54–135` — well-covered mark-done unit tests
- `tests/Feature/Dashboard/DashboardTestCase.php:26` — `freezeTime()` call
- `tests/Feature/Appliances/AddApplianceWizardTest.php:17–65` — wizard happy path (no next_due_at assertions)
- `tests/Feature/Appliances/ApplianceTestCase.php` — base class, no freezeTime()

---

## Architecture Insights

1. **Duplication is the primary structural risk.** The `match(interval_unit)` calculation lives in two files with no shared abstraction. A silent divergence (e.g., one file gets a timezone fix, the other does not) is the most likely failure mode.

2. **The unit-vs-integration boundary is forced by the current structure.** Because the calculation is inline in a Livewire `confirm()` method, testing it requires a full Volt integration test (with database, Prism::fake(), and an authenticated session). Extracting to a static method would allow a pure unit test, but that is a refactoring step.

3. **Frozen time is already the standard for the mark-done path** (`DashboardTestCase::freezeTime()`). The wizard path (`ApplianceTestCase`) lacks this — any new wizard tests that assert on computed dates must either: (a) extend `ApplianceTestCase` to add `freezeTime()`, or (b) supply a known past anchor date and compare against a pre-computed expected value.

4. **anchor_type is a storage concern, not an arithmetic concern.** Tests that want to cover `fixed_calendar` in the wizard should assert `anchor_date` is populated correctly AND `next_due_at` equals `anchor_date + interval`. The arithmetic is the same; what changes is which field is stored.

---

## Historical Context (from prior changes)

- `context/archive/dashboard-tasks-and-mark-done/plan.md` — Established `RecordTaskCompletion` action contract, resolved the anchor_type-at-mark-done question explicitly (both types advance from `now()`), and added the `scopeCalendar` guard that prevents metric task IDs reaching the action.
- `context/archive/appliance-crud/` — Appliance CRUD context; no direct next_due_at calculation decisions.
- `context/foundation/lessons.md` — "MaintenanceTask: interval_unit determines which next_due field is authoritative" — hard constraint: branch on `interval_unit` before reading or writing any due field.

---

## Open Questions

1. **Extract or inline?** Should the calculation be extracted to a static helper (e.g., `MaintenanceTask::calculateNextDue(anchor, unit, value): Carbon`) before tests are written, or should tests be written against the current inline structure? Extraction makes unit testing cheaper but is a refactoring step that changes both production files. **Recommend: decide in `/10x-plan`.**

2. **`ApplianceTestCase::freezeTime()`**: Should the plan add `freezeTime()` to `ApplianceTestCase` globally, or should individual tests freeze time locally? Global freeze is simpler but may affect existing wizard tests that don't need it.

3. **Wizard `confirm()` test shape**: The wizard test requires a running Prism::fake() to complete the AI step before `confirm()` can be called. The test plan must decide whether to (a) skip the AI step and directly call confirm() with a pre-built payload, or (b) run the full happy-path including AI. Option (a) is cheaper and more focused on the calculation; option (b) is closer to the real flow.

4. **`km` metric tasks**: The `km` metric type exists in the enum and schema but has zero test coverage in any test file. The test plan does not include km coverage — this is acceptable per §7 (metric path is not in scope for Phase 1).

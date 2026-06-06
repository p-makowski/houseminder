---
date: 2026-06-06T06:25:22Z
researcher: claude-sonnet-4-6
git_commit: 416b2205f046bb02024ad1c60e12892853eed733
branch: main
repository: houseminder
topic: "Edge cases + AI contract — dashboard date boundaries, unconfirmed tasks, Prism failure modes"
tags: [research, dashboard, MaintenanceTask, Prism, wizard, date-boundaries, is_confirmed, ai-contract]
status: complete
last_updated: 2026-06-06
last_updated_by: claude-sonnet-4-6
---

# Research: Edge Cases + AI Contract (Phase 3)

**Date**: 2026-06-06T06:25:22Z
**Git Commit**: 416b2205f046bb02024ad1c60e12892853eed733
**Branch**: main
**Repository**: houseminder

## Research Question

Ground rollout Phase 3 of `context/foundation/test-plan.md`. Verify the exact code paths and boundary conditions for:
- Risk #4: Dashboard date-boundary operators (overdue / due-this-week / upcoming)
- Risk #5: Unconfirmed task (`is_confirmed = false`) filtering — query-level vs view-level
- Risk #6: AI contract — what happens when Prism returns zero tasks or a malformed response

---

## Summary

**Risk #4 (date boundaries):** Confirmed real and untested at exact boundary values. The three bucket queries are inline in the Volt component (not named scopes). Overdue uses strict `<`, due-this-week uses `whereBetween` (inclusive both ends, `>= now AND <= now+7d`), upcoming uses strict `>`. A task due exactly at `now()` lands in `dueThisWeek`, not `overdue`. A task due exactly at `now()->addDays(7)` lands in `dueThisWeek`, not `upcoming`. Existing tests use `subDay()`, `addDays(3)`, `addDays(30)` — no exact boundary values tested.

**Risk #5 (unconfirmed tasks):** Lower risk than the plan map implied. The `is_confirmed = true` filter is in `scopeCalendar` and `scopeMetric` on the model — query-level, not view-level. Every dashboard path goes through one of these scopes; there is no bypass. One test already exists (`test_unconfirmed_task_does_not_appear`). Residual gap: that test seeds only the overdue path; the dueThisWeek/upcoming paths are untested for this filter.

**Risk #6 (AI contract):** The most significant gap. `GenerateMaintenancePlan` performs zero PHP-level validation on the Prism response beyond `?? []`. Zero tasks returns silently — wizard shows blank step 2 with no error and no retry affordance; the user is only blocked at the Next click (deferred guard). Missing fields on a task are not caught until `confirm()` validation at step 4. The `\Throwable` fallback path is untested. Only the `PrismException` path has test coverage.

---

## Detailed Findings

### Risk #4 — Dashboard Date Boundary Operators

#### Bucket query chains (inline in Volt component)

**File**: `resources/views/livewire/pages/dashboard.blade.php`

All three calendar buckets are `#[Computed]` properties. Every one starts with `MaintenanceTask::calendar()->forHousehold($this->resolveHouseholdId())`. Date filters are **inline in the component**, not in named scopes on the model.

**`overdue` (lines 33–39):**
```php
->where('next_due_at', '<', now())
->orderBy('next_due_at')
```
Operator: **strict `<`**. A task due at exactly `now()` is NOT overdue.

**`dueThisWeek` (lines 43–52):**
```php
$now = now();
->whereBetween('next_due_at', [$now, $now->copy()->addDays(7)])
->orderBy('next_due_at')
```
Operator: **`>= $now AND <= $now->addDays(7)`** (Laravel `whereBetween` is inclusive on both bounds).
- A task due exactly at `now()` → lands in `dueThisWeek` (NOT overdue).
- A task due exactly at `now()->addDays(7)` → lands in `dueThisWeek` (NOT upcoming).

**`upcoming` (lines 55–63):**
```php
->where('next_due_at', '>', now()->addDays(7))
->orderBy('next_due_at')
```
Operator: **strict `>`**. A task due at exactly `now()->addDays(7)` is NOT upcoming — it falls in `dueThisWeek`.

#### Critical boundary semantics

| `next_due_at` value | Bucket |
|---|---|
| `now()->subMicrosecond()` | `overdue` |
| `now()` exactly | `dueThisWeek` (overdue is strict `<`) |
| `now()->addDays(7)` exactly | `dueThisWeek` (upcoming is strict `>`) |
| `now()->addDays(7)->addMicrosecond()` | `upcoming` |

The boundary between `overdue` and `dueThisWeek` is the current instant. The test plan note said "task due exactly today → overdue" — **this is wrong**. The actual behavior is: "task due at exactly `now()` → `dueThisWeek`." The plan guidance must be corrected. Only tasks with `next_due_at < now()` (i.e., strictly in the past) are overdue.

#### Two separate `now()` calls — race window

`overdue` calls `now()` and `dueThisWeek` calls `now()` independently (different `#[Computed]` methods). Under real-time conditions, a task with `next_due_at` exactly at the system clock could briefly appear in neither bucket (both strict comparisons miss it). Under `freezeTime()`, this race is eliminated — so the test environment does not expose this subtle double-evaluation issue.

#### What the model scope (`scopeCalendar`) does NOT do

`app/Models/MaintenanceTask.php`, lines 19–24:
```php
public function scopeCalendar(Builder $query): void
{
    $query->whereIn('interval_unit', ['days', 'weeks', 'months', 'years'])
          ->where('is_confirmed', true)
          ->whereNotNull('next_due_at');
}
```
`scopeCalendar` applies unit filtering, confirmed filtering, and null-due-date filtering. **No date boundaries**. All date comparisons are in the Volt component inline.

#### Existing test coverage

`tests/Feature/Dashboard/DashboardPageTest.php`:
- Overdue test: `next_due_at = now()->subDay()` — well inside the bucket.
- Due-this-week test: `next_due_at = now()->addDays(3)` — well inside.
- Upcoming test: `next_due_at = now()->addDays(30)` — well inside.

**No test pins `now()` exactly, `now()->addDays(7)` exactly, or any other exact boundary value.** Gap confirmed.

#### Test plan correction needed

The test plan §4 Risk Response Guidance for Risk #4 states: "Task due exactly today → 'overdue'". Based on the actual operators, this is incorrect — a task due at exactly `now()` falls into `dueThisWeek`. The correction: "overdue = `next_due_at < now()` (strict); dueThisWeek = `>= now() AND <= now()+7d` (inclusive both ends)."

---

### Risk #5 — Unconfirmed Task Filtering

#### Location of the filter

`app/Models/MaintenanceTask.php`, lines 19–24 (`scopeCalendar`) and lines 26–29 (`scopeMetric`):
```php
// scopeCalendar
->where('is_confirmed', true)

// scopeMetric
->where('is_confirmed', true)
```
Both scopes enforce `is_confirmed = true` at the **Eloquent query level** — not in Blade conditionals.

#### Coverage of every dashboard path

| Dashboard section | Scope used | `is_confirmed` enforced? |
|---|---|---|
| `overdue` | `calendar()` | Yes |
| `dueThisWeek` | `calendar()` | Yes |
| `upcoming` | `calendar()` | Yes |
| `metric` | `metric()` | Yes |
| `markDone()` lookup | `calendar()` | Yes |

There is **no bypass path**. The Blade template (`dashboard.blade.php` lines 81–175) iterates collections returned by computed properties without any `is_confirmed` conditional of its own.

#### Factory default

`database/factories/MaintenanceTaskFactory.php`, line 30:
```php
'is_confirmed' => false,
```
**The factory defaults to `is_confirmed = false`.** Any test creating a task via factory without explicit `'is_confirmed' => true` will produce an invisible (unconfirmed) task. This is a correct default for testing unconfirmed behaviour, but means all positive-coverage tests must override it.

#### Existing test coverage

`tests/Feature/Dashboard/DashboardPageTest.php`, lines 75–86:
```php
public function test_unconfirmed_task_does_not_appear(): void
```
Seeds one task with `is_confirmed = false`, `next_due_at = now()->subDay()` (would be overdue if confirmed), asserts `assertDontSee('Draft Task')`. This test exists and passes.

**Residual gap**: only the `overdue` path is tested for `is_confirmed = false`. No test seeds an unconfirmed task in the `dueThisWeek` window or `upcoming` window and asserts absence. However, because all three paths share the same `calendar()` scope and the filter is there (not per-bucket), the structural risk of a bypass in those windows is very low. Testing value is marginal but completable cheaply.

---

### Risk #6 — AI Contract: Prism Failure Modes

#### The Prism call and schema

`app/Actions/GenerateMaintenancePlan.php`, lines 24–66:

Inline `ObjectSchema` with a required `tasks` array. Each task item requires four fields: `name` (string), `description` (string), `interval_value` (number), `interval_unit` (string). The schema is passed to Prism's structured output call; it is **advisory prompt metadata**, not PHP-level validation.

Line 68:
```php
return $response->structured['tasks'] ?? [];
```
**This is the only post-response operation.** No field validation, no count check, no structural verification.

#### Failure mode analysis

| Input to action | `structured` key | Action return | Wizard state | User sees |
|---|---|---|---|---|
| Network/API error | (exception) | `PrismException` thrown | `$aiError` set, step 2, Retry shown | Error + Retry (correct) |
| Empty task array | `['tasks' => []]` | `[]` | `$tasks = []`, `$aiError = null` | **Blank step 2, no error, no Retry** |
| Missing `tasks` key | `[]` | `[]` | Same as above | Blank step 2, no error, no Retry |
| Task missing `name` | `[['description' => 'x', 'interval_value' => 3, 'interval_unit' => 'months']]` | partial array | Broken task cards shown | Broken UI, passes to step 3 |
| `structured['tasks']` = null | `['tasks' => null]` | `\TypeError` on `array_map` | `\Throwable` caught, generic error | Generic error (tested? No) |

#### Wizard control flow for zero tasks

`resources/views/livewire/pages/appliances/create.blade.php`, `fetchSuggestions()`, lines 107–121:

```php
$this->tasks = array_map(..., (new GenerateMaintenancePlan)(...));
$this->aiError = null;    // ← explicitly cleared
$this->aiLoading = false;
```

When action returns `[]`, `$this->tasks = []` and `$this->aiError = null`. The Blade template (lines 337–360) shows the task list section only when `count($this->tasks) > 0`. When both `aiLoading` and `aiError` are falsy and `tasks` is empty, the Blade renders **nothing** — an empty, featureless step 2.

**Deferred guard** in `advanceFromStep2()` (lines 86–99):
```php
if (empty($this->tasks)) {
    $this->aiError = 'At least one task is required before continuing.';
    return;
}
```
The user is blocked from advancing, but only when they click Next. The blank state has no immediate feedback or retry affordance.

#### Wizard control flow for partial/missing-field tasks

`advanceFromStep2()` only checks `empty($this->tasks)`. A task with `name = null` passes this guard. The broken task is shown in step 2 (blade renders whatever fields exist), advances to step 3 (backdate UI — no validation here), and is only caught at step 4 `confirm()`:
```php
$this->validate(['tasks' => ['required', 'array', 'min:1'], 'tasks.*.name' => ['required', ...]]);
```
The user can traverse three wizard steps with a structurally broken AI response before hitting a hard validation error.

#### Existing `Prism::fake()` coverage

| File | Fixture | Scenarios covered |
|---|---|---|
| `tests/Feature/Appliances/AddApplianceWizardTest.php:21` | `StructuredResponseFake` with 2 well-formed tasks | Happy path |
| `tests/Feature/Appliances/TaskEditingTest.php:20` | `StructuredResponseFake` with 2 well-formed tasks | Task editing/delete/add |
| `tests/Feature/Appliances/AiFailureTest.php:18` | Mock on `GenerateMaintenancePlan` class; first call throws `PrismException`, second returns 1 valid task | PrismException + retry |

`AiFailureTest` uses `$this->mock(GenerateMaintenancePlan::class)` — it tests at the action injection level, not via `Prism::fake()`. The `PrismException` path (network failure) is covered. The zero-tasks path, the missing-fields path, and the `\Throwable` fallback are not covered.

#### Oracle problem check

No existing test asserts AI output verbatim from the implementation. Fixture data in `AddApplianceWizardTest` and `TaskEditingTest` is independently specified. No oracle problem in the current suite.

---

## Code References

- `resources/views/livewire/pages/dashboard.blade.php:33-39` — `overdue` computed property, strict `<` operator
- `resources/views/livewire/pages/dashboard.blade.php:43-52` — `dueThisWeek` computed property, `whereBetween` inclusive bounds
- `resources/views/livewire/pages/dashboard.blade.php:55-63` — `upcoming` computed property, strict `>` operator
- `app/Models/MaintenanceTask.php:19-24` — `scopeCalendar` with `is_confirmed = true` filter
- `app/Models/MaintenanceTask.php:26-29` — `scopeMetric` with `is_confirmed = true` filter
- `app/Actions/GenerateMaintenancePlan.php:68` — sole post-response operation: `return $response->structured['tasks'] ?? []`
- `resources/views/livewire/pages/appliances/create.blade.php:107-121` — `fetchSuggestions()`: clears `$aiError` after action call
- `resources/views/livewire/pages/appliances/create.blade.php:86-99` — `advanceFromStep2()`: deferred empty-tasks guard
- `resources/views/livewire/pages/appliances/create.blade.php:115-118` — `PrismException` catch → sets `$aiError`
- `resources/views/livewire/pages/appliances/create.blade.php:118-120` — `\Throwable` fallback catch
- `tests/Feature/Dashboard/DashboardPageTest.php:20-31` — overdue bucket test (`subDay()`)
- `tests/Feature/Dashboard/DashboardPageTest.php:33-44` — dueThisWeek bucket test (`addDays(3)`)
- `tests/Feature/Dashboard/DashboardPageTest.php:46-57` — upcoming bucket test (`addDays(30)`)
- `tests/Feature/Dashboard/DashboardPageTest.php:75-86` — unconfirmed task absence test
- `tests/Feature/Appliances/AiFailureTest.php:18` — `PrismException` mock + retry
- `database/factories/MaintenanceTaskFactory.php:30` — factory default: `is_confirmed = false`

---

## Architecture Insights

### Boundary operator consistency

The three bucket operators are internally consistent: `< now()` (overdue), `>= now() AND <= now()+7d` (this week), `> now()+7d` (upcoming). There is no overlap and no gap — except at the exact microsecond boundary between overdue and this-week under real-time conditions. Under `freezeTime()` this is irrelevant to tests.

### `is_confirmed` as a model-scope concern, not a component concern

The filter is correctly placed on the model's scopes rather than in each component. This means it cannot be accidentally omitted when new dashboard queries are added — as long as they use `calendar()` or `metric()`. A new query that bypasses these scopes would be the only real Risk #5 failure scenario, and the existing test catches it for the overdue path.

### AI response validation gap — architectural decision needed

`GenerateMaintenancePlan` returns raw Prism structured output with no PHP-level validation. This is a deliberate simplicity trade-off: Prism's schema instructs the LLM to return valid structure. The risk is that LLM-level schema enforcement is probabilistic, not guaranteed. A field-level PHP validation guard in the action would make the contract explicit and testable. Without it, the wizard component must handle partial arrays — and currently does not for missing fields.

### `Prism::fake()` vs action mock — two testing strategies coexist

`AiFailureTest` uses `$this->mock(GenerateMaintenancePlan::class)` — faster and more precise for testing the action's specific failure mode, but does not exercise the real Prism deserialization. `AddApplianceWizardTest` uses `Prism::fake()` with a `StructuredResponseFake` — exercises the real action-to-component path. New failure-mode tests should use `Prism::fake()` with crafted `StructuredResponseFake` fixtures to exercise the real path, not just mock the action.

---

## Historical Context

- `context/archive/2026-06-04-dashboard-tasks-and-mark-done/plan.md` — Specifies the four dashboard sections, the `is_confirmed` enforcement requirement, the exact query chains (including the 7-day `whereBetween`), and test requirements. The test requirements specified in that plan used `subDay()`/`addDays(3)`/`addDays(30)` — relative offsets, not exact boundaries. This is the origin of the gap.
- `context/archive/2026-06-05-testing-authorization-depth/plan.md` — Covered Risks #1 and #3; no date boundary or AI contract coverage.
- `context/archive/2026-06-05-testing-calculation-correctness/` — Covered Risk #2 (`next_due_at` calculation correctness). Established `freezeTime()` as the canonical time-control mechanism via `ApplianceTestCase`.

---

## Test Plan Correction Required

The §4 Risk Response Guidance for Risk #4 states:
> "Task due exactly today → 'overdue'"

**This is incorrect.** The actual operator is `where('next_due_at', '<', now())` — strict less-than. A task due at exactly `now()` falls into `dueThisWeek` (lower bound is inclusive `>= now()`). The correct statement is:
- Overdue: `next_due_at < now()` (strictly in the past)
- dueThisWeek: `now() <= next_due_at <= now()+7d` (current instant through 7 days, inclusive)
- Upcoming: `next_due_at > now()+7d` (strictly beyond 7 days)

This correction should be backported to §2 Risk #4 guidance before planning begins.

---

## Open Questions

1. **Should `GenerateMaintenancePlan` add PHP-level field validation?** Currently the action returns raw Prism output. Adding a validation step (e.g., `array_filter` + field presence check) would make the contract explicit and testable at the unit level. For Phase 3, the plan should address whether to test the current behaviour (blank state, deferred guard) or also add a guard to the action.

2. **Should zero-tasks trigger an immediate error in `fetchSuggestions`?** Currently the wizard shows a blank, featureless step 2. The UX decision (immediate error vs deferred guard on Next click) affects what the test should assert. The plan needs to state which behaviour is the spec, not just verify current behaviour.

3. **`\Throwable` fallback — is it worth a dedicated test?** The fallback exists but is untested. A `Prism::fake()` fixture that triggers `array_map(null)` → `\TypeError` would cover it. Low priority but trivial to add.

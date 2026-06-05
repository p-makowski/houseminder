---
date: 2026-06-05T00:00:00+00:00
researcher: claude-sonnet-4-6
git_commit: f24ae88
branch: main
repository: houseminder
topic: "Authorization depth — household scope enforcement across all Volt components and IDOR on markDone"
tags: [research, authorization, household-scope, volt, idor, livewire, markDone, security]
status: complete
last_updated: 2026-06-05
last_updated_by: claude-sonnet-4-6
---

# Research: Authorization depth — household scope enforcement across all Volt components and IDOR on markDone

**Date**: 2026-06-05  
**Researcher**: claude-sonnet-4-6  
**Git Commit**: f24ae88  
**Branch**: main  
**Repository**: p-makowski/houseminder

---

## Research Question

Ground rollout Phase 2 of `context/foundation/test-plan.md`.

- **Risk #1**: Identify every Volt component that accepts an appliance/task route param, verify how `mount()` and action-level calls enforce household scope.
- **Risk #3**: Trace the dashboard `markDone()` fetch path — does the Volt component enforce household scope before passing to `RecordTaskCompletion`, or does it trust the action's guard?

---

## Summary

**Risk #1**: Five Volt pages exist. Two accept `Appliance` route params (`edit`, `show`). Both use Laravel implicit model binding (unscoped) followed by `abort_if($appliance->household_id !== $household->id, 403)` in `mount()`. No global scopes exist on any model — all isolation is manual. `edit.blade.php` is covered by cross-household tests; **`show.blade.php` has no cross-household test** — this is the primary gap for Risk #1.

**Risk #3**: The `markDone()` action in `dashboard.blade.php` fetches the task through a household-scoped query (`MaintenanceTask::calendar()->forHousehold($household->id)->findOrFail($taskId)`). A foreign task ID causes `findOrFail` to throw `ModelNotFoundException` before the action is called — no `ServiceRecord` is created. The action has a redundant `abort_if` guard as a second layer. **Critical test plan correction**: Risk #3 describes the component as returning HTTP 403, but the implementation returns 404 (ModelNotFoundException from scoped findOrFail). The existing Volt test for this case catches the exception in a try/catch without calling `$this->fail()` if no exception is thrown — the assertion is only `assertDatabaseMissing`, which is the sole reliable guard.

---

## Detailed Findings

### A. Volt Component Inventory — Resource ID Scope

#### Routes with resource IDs (household-scoped check required)

| Component | Route | Mount param | Fetch strategy | Ownership check |
|---|---|---|---|---|
| `pages.appliances.edit` | `/appliances/{appliance}/edit` | `Appliance $appliance` (implicit binding) | Unscoped `findOrFail` by Laravel routing → `abort_if` in `mount()` | Lines 28–29 in `edit.blade.php` |
| `pages.appliances.show` | `/appliances/{appliance}` | `Appliance $appliance` (implicit binding) | Unscoped `findOrFail` by Laravel routing → `abort_if` in `mount()` | Line 17 in `show.blade.php` |

#### Routes without resource IDs (no cross-household risk in `mount()`)

| Component | Route | Notes |
|---|---|---|
| `pages.appliances.index` | `/appliances` | Uses `Appliance::where('household_id', $household->id)` — strongest pattern |
| `pages.appliances.create` | `/appliances/create` | Creation form; no resource ID; `confirm()` re-checks household |
| `pages.dashboard` | `/dashboard` | No resource ID in `mount()`; `markDone()` handles IDs separately (see §B) |
| Auth pages | `/login`, `/register`, etc. | No household resources |
| Profile components | (embedded) | No household resources |

**No `#[Url]` properties or `$queryString` bindings exist** on any resource IDs across all components.

---

### B. Authorization Pattern Analysis

Two patterns are used across the codebase:

#### Pattern 1 — Scoped query (strongest)

Used by `index.blade.php` (mount) and `dashboard.blade.php` (markDone):

```php
// index.blade.php:21-27
$this->appliances = Appliance::where('household_id', $household->id)
    ->with('applianceType')
    ->withCount([...])
    ->get();
```

```php
// dashboard.blade.php:25
$task = MaintenanceTask::calendar()->forHousehold($household->id)->findOrFail($taskId);
```

Foreign IDs never materialize as PHP objects. The DB query excludes them structurally.

#### Pattern 2 — Unscoped binding + `abort_if` (secure but weaker)

Used by `edit.blade.php` and `show.blade.php`:

```php
// edit.blade.php:25-29
public function mount(Appliance $appliance): void
{
    $household = Auth::user()->households()->first();
    abort_if(!$household, 403);
    abort_if($appliance->household_id !== $household->id, 403);
    // ...
}
```

```php
// show.blade.php:14-17
public function mount(Appliance $appliance): void
{
    $household = Auth::user()->households()->first();
    abort_if(!$household || $appliance->household_id !== $household->id, 403);
    // ...
}
```

Laravel's route model binding calls `Appliance::findOrFail($id)` **before** `mount()` — the model is materialized in PHP before the ownership check fires. There is no HTTP-level window between fetch and abort (everything is synchronous within the same PHP request), and no data is returned to the client before the 403. It is not an IDOR vulnerability. However, it is the pattern that requires testing — the protection is in PHP logic, not in the query layer, and it is not verified by any test for `show.blade.php`.

#### Action-level second guard — `RecordTaskCompletion`

```php
// app/Actions/RecordTaskCompletion.php:17-20
$task->loadMissing('appliance');
$household = $user->households()->first();
abort_if(! $household || $task->appliance->household_id !== $household->id, 403);
```

This is a **redundant, defense-in-depth guard**. In normal usage the Volt component's scoped `findOrFail` prevents a foreign task from ever reaching this line. The action uses `abort_if` (throws `HttpException`) — this is intentional and documented in `lessons.md` ("Action classes that use abort_if are coupled to HTTP context").

---

### C. markDone() Deep Trace — Full Attack Scenario

**Source**: `resources/views/livewire/pages/dashboard.blade.php:20-28`

```php
public function markDone(int $taskId): void
{
    $household = Auth::user()->households()->first();
    abort_if(! $household, 403);

    $task = MaintenanceTask::calendar()->forHousehold($household->id)->findOrFail($taskId);

    (new RecordTaskCompletion)($task, Auth::user());
}
```

The `forHousehold` scope (`app/Models/MaintenanceTask.php:32-35`):

```php
public function scopeForHousehold(Builder $query, int $householdId): void
{
    $query->whereHas('appliance', fn (Builder $q) => $q->where('household_id', $householdId));
}
```

**Attack path with foreign `$taskId`:**

1. `$household` resolves to the attacker's household.
2. `MaintenanceTask::calendar()->forHousehold($household->id)->findOrFail($taskId)` executes with `whereHas('appliance', fn($q) => $q->where('household_id', attacker_household_id))`.
3. The foreign task ID does not satisfy the `whereHas` — `findOrFail` throws `ModelNotFoundException`.
4. **In Livewire Volt `Volt::test()->call()`, `ModelNotFoundException` bubbles up as a thrown PHP exception, not as an HTTP 404 response.** The exception propagates to the test caller.
5. The action is never called. No `ServiceRecord` is written.

**MaintenanceTask has no direct `household_id` column** — household is resolved transitively through `appliance.household_id`. The `scopeForHousehold` bridge is the only mechanism for household-scoped task queries.

---

### D. Model Scope Analysis

**`app/Models/Appliance.php`**: No global scope. No `addGlobalScope()` in `booted()`. No `scopeForHousehold`. All household isolation is explicit and manual at every call site.

**`app/Models/MaintenanceTask.php`**: No global scope. Has `scopeCalendar`, `scopeMetric`, and `scopeForHousehold` as manual local scopes. Must be explicitly chained — no automatic filtering.

**`app/Models/Household.php`**: `appliances(): HasMany`. No task relationship on Household (tasks are accessed through `Household → appliances → maintenanceTasks`). No global scopes.

**`app/Providers/AppServiceProvider.php`**: Only `URL::forceScheme('https')` in production. No model observers, no globally registered scopes, no policy bindings.

**Consequence for tests**: There is no framework safety net. Every component or action that accesses a household-owned resource must explicitly apply the scope or the `abort_if` check. If a future refactor removes a `forHousehold()` call or an `abort_if`, no global scope catches it.

---

### E. Existing Test Coverage — Authorization

#### Tests that cover household authorization (Risk #1, Risk #3)

| Test file | Method | What it covers | Status |
|---|---|---|---|
| `tests/Feature/Appliances/ApplianceEditTest.php:53-67` | `test_editing_appliance_from_another_household_returns_403` | `edit.blade.php` mount() with foreign appliance → 403 | ✓ exists |
| `tests/Feature/Appliances/ApplianceDeleteTest.php:50-66` | `test_deleting_appliance_from_another_household_returns_403` | `edit.blade.php` delete() with foreign appliance → 403 | ✓ exists |
| `tests/Feature/Appliances/ApplianceEditTest.php:35-51` | `test_save_with_cross_household_type_id_returns_403` | `edit.blade.php` save() with foreign ApplianceType → 403 | ✓ exists |
| `tests/Feature/Appliances/ApplianceIndexTest.php:28-57` | `test_user_does_not_see_other_household_appliances` | `index.blade.php` scope filtering (no cross-household leakage) | ✓ exists |
| `tests/Feature/Dashboard/DashboardPageTest.php:104-126` | `test_mark_done_rejects_foreign_household_task` | `markDone()` foreign task — catches `ModelNotFoundException` + `assertDatabaseMissing` | ✓ exists, but weak (see gap below) |
| `tests/Feature/Dashboard/RecordTaskCompletionTest.php:205-223` | `test_aborts_403_when_user_has_no_household` | `RecordTaskCompletion` action — no-household case | ✓ exists |
| `tests/Feature/Dashboard/RecordTaskCompletionTest.php:225-246` | `test_aborts_403_for_task_belonging_to_different_household` | `RecordTaskCompletion` action — foreign household case | ✓ exists |

#### Authorization gaps

| Gap | Description | Risk |
|---|---|---|
| **`show.blade.php` — no cross-household test** | No test for `Volt::test('pages.appliances.show', ['appliance' => $foreignAppliance])->assertForbidden()`. The `abort_if` check in `mount()` is untested for the foreign-appliance case. | #1 |
| **`markDone()` test has silent non-assertion** | `DashboardPageTest:104-126` catches `ModelNotFoundException` but never calls `$this->fail()` if no exception is thrown. If the scope guard is removed, the test passes silently (only `assertDatabaseMissing` remains as protection). | #3 |

---

### F. Test Infrastructure for Phase 2

#### `ApplianceTestCase` (`tests/Feature/Appliances/ApplianceTestCase.php:20-29`)

```php
protected function setUp(): void
{
    parent::setUp();
    $this->freezeTime();
    $this->user = User::factory()->create();
    $this->household = Household::factory()->create();
    $this->user->households()->attach($this->household->id, ['role' => 'owner']);
    $this->actingAs($this->user);
}
```

Protected properties: `$user`, `$household`.

#### `DashboardTestCase` (`tests/Feature/Dashboard/DashboardTestCase.php:23-36`)

Same as ApplianceTestCase plus:
```php
$this->appliance = Appliance::factory()->create([
    'household_id' => $this->household->id,
]);
```

Protected properties: `$user`, `$household`, `$appliance`.

#### Second-household fixture pattern (used across existing cross-household tests)

```php
$otherHousehold = Household::factory()->create();
$otherAppliance = Appliance::factory()->create(['household_id' => $otherHousehold->id]);
// No pivot attachment needed — attacker is NOT a member of $otherHousehold
```

Route model binding for `show`/`edit` tests:
```php
Volt::test('pages.appliances.show', ['appliance' => $foreignAppliance])->assertForbidden();
```

`markDone()` foreign task fixture:
```php
$foreignTask = MaintenanceTask::factory()->create([
    'appliance_id' => $otherAppliance->id,
    'next_due_at'  => now()->subDay(),
    'interval_unit' => 'months',
    'interval_value' => 6,
    'is_confirmed' => true,
]);
```

---

## Code References

- `resources/views/livewire/pages/appliances/show.blade.php:14-21` — mount() with `abort_if` ownership check (no test for foreign appliance)
- `resources/views/livewire/pages/appliances/edit.blade.php:25-29` — mount() with `abort_if` ownership check
- `resources/views/livewire/pages/appliances/index.blade.php:15-31` — strongest pattern: scoped query in mount()
- `resources/views/livewire/pages/dashboard.blade.php:20-28` — markDone() with `forHousehold()->findOrFail()`
- `app/Models/MaintenanceTask.php:32-35` — `scopeForHousehold` via `whereHas('appliance', ...)`
- `app/Actions/RecordTaskCompletion.php:17-20` — redundant `abort_if` ownership guard
- `app/Models/Appliance.php` — no global scope; all isolation manual
- `app/Models/MaintenanceTask.php` — no global scope; no direct `household_id` column
- `tests/Feature/Appliances/ApplianceEditTest.php:53-67` — cross-household 403 on edit (reference pattern for show test)
- `tests/Feature/Dashboard/DashboardPageTest.php:104-126` — markDone foreign task (existing weak test)
- `tests/Feature/Dashboard/RecordTaskCompletionTest.php:225-246` — action-level 403 on foreign task

---

## Architecture Insights

1. **No framework safety net**: No global model scopes enforce household isolation. Every access point must manually apply `forHousehold()` or `abort_if()`. A future feature that queries `Appliance::find($id)` without a scope will silently bypass household isolation.

2. **Two protection tiers for markDone()**: The Volt component's `forHousehold()->findOrFail()` is the first and primary guard (produces 404 via ModelNotFoundException). The action's `abort_if` is a redundant second layer (would produce 403 if the action were called directly). The two layers produce different HTTP status codes — this is deliberate (404 is better for enumeration resistance at the component level).

3. **MaintenanceTask household path is indirect**: `maintenance_tasks` has no `household_id` column. Household scope must always go through the `appliance` relationship. The `scopeForHousehold` `whereHas` handles this correctly but adds a subquery. Any direct query on MaintenanceTask without chaining `.forHousehold()` is unscoped.

4. **Unscoped implicit binding is the current pattern for show/edit**: This means `abort_if` in `mount()` is load-bearing. It is not redundant. If it were removed, the Volt component would expose foreign appliance data — no other layer would catch it.

5. **`Volt::test()->call()` does not suppress exceptions**: `ModelNotFoundException` thrown inside a component action bubbles up to the test as a PHP exception. Tests must either catch it explicitly (and call `$this->fail()` on the non-exception path) or use `$this->expectException()`.

---

## Historical Context (from prior changes)

- `context/changes/testing-calculation-correctness/plan.md` — Phase 1 rollout. Established `ApplianceTestCase` with `freezeTime()` fixture pattern, `Volt::test()` integration test structure, and `DashboardTestCase` as separate base class. Reference for Phase 2 test structure.
- `context/archive/dashboard-tasks-and-mark-done/plan.md` — Implemented the `markDone()` action with double-layer ownership guard (component scoped query + action abort_if). Source for Risk #3 in the test plan.
- `context/archive/appliance-crud/plan.md` — Implemented show/edit pages with implicit model binding + `abort_if` pattern.

---

## Test Plan Corrections (for backport to §2)

The research reveals one correction to the Risk Response Guidance that should be backported before planning:

**Risk #3 — "What would prove protection" row:**

> Current: "Volt `markDone` call with a foreign task ID returns 403 and creates no `ServiceRecord`"

> Corrected: "Volt `markDone` call with a foreign task ID throws `ModelNotFoundException` (component's `forHousehold()->findOrFail()` excludes the foreign task — not a 403) and creates no `ServiceRecord`; if `RecordTaskCompletion` is called directly with a foreign task, it aborts 403."

**Why this matters for planning:** Tests using `->assertForbidden()` on the Volt component call would pass even if the scope guard were broken (because the action's `abort_if` would then fire and produce 403). The correct test must assert `ModelNotFoundException` is thrown AND that no `ServiceRecord` is written. The `->assertForbidden()` assertion cannot distinguish between the component guard and the action guard.

---

## Open Questions

None — all research questions fully answered. The plan can proceed with:

1. A new test class `tests/Feature/Appliances/ApplianceShowTest.php` for Risk #1 gap.
2. A strengthened `DashboardPageTest::test_mark_done_rejects_foreign_household_task()` for Risk #3 gap.
3. The test plan §2 Risk #3 response guidance correction before the plan is written.

# Authorization Depth — Implementation Plan

## Overview

Close the two household-authorization test gaps identified in Phase 2 of `context/foundation/test-plan.md`:

1. **Risk #1** — `pages.appliances.show` has no cross-household test. Its `abort_if` ownership guard is the sole protection and is currently untested.
2. **Risk #3** — `DashboardPageTest::test_mark_done_rejects_foreign_household_task` catches `ModelNotFoundException` silently (no `$this->fail()`), meaning a broken scope guard passes the test undetected.

Then fill in the §6.2 and §6.3 cookbook entries with the patterns this phase ships.

## Current State Analysis

`edit.blade.php` and `show.blade.php` share the same authorization pattern: Laravel implicit model binding resolves the `Appliance` from the route before `mount()` is called (no household scope in the query), then `mount()` fires `abort_if($appliance->household_id !== $household->id, 403)`. This `abort_if` is load-bearing — no model scope or middleware provides a fallback.

`edit.blade.php` is covered by `ApplianceEditTest:53-67`. `show.blade.php` has no equivalent test.

`DashboardPageTest:104-126` tests the `markDone()` guard by calling `Volt::test()->call('markDone', $foreignTask->id)` inside a bare try/catch. Because `Volt::test()->call()` surfaces `ModelNotFoundException` as a PHP exception (not an HTTP response), the catch block fires — but no assertion fails if the exception is *not* thrown. The `assertDatabaseMissing` at line 123 is the only real protection; if both the scope guard and the action guard were removed but a `ServiceRecord` still wasn't written for some other reason, the test would silently pass.

No global model scopes exist on `Appliance` or `MaintenanceTask`. All household isolation is manual at every call site.

## Desired End State

After this phase:

- `tests/Feature/Appliances/ApplianceShowTest.php` exists with two tests: a happy-path test confirming the owner can view the page, and a cross-household test confirming a foreign appliance is rejected with 403.
- `DashboardPageTest::test_mark_done_rejects_foreign_household_task` fails explicitly when `ModelNotFoundException` is not thrown — the silent-pass gap is closed.
- §6.2 and §6.3 in `context/foundation/test-plan.md` are filled in with the canonical second-household fixture pattern and Volt authorization assertion conventions.

### Key Discoveries

- `resources/views/livewire/pages/appliances/show.blade.php:14-17` — `mount()` ownership check; unscoped binding + `abort_if`
- `tests/Feature/Appliances/ApplianceEditTest.php:53-67` — canonical cross-household 403 test; reference for the new show test
- `tests/Feature/Dashboard/DashboardPageTest.php:104-126` — silent try/catch; the gap to fix
- `tests/Feature/Dashboard/RecordTaskCompletionTest.php:225-246` — correct try/catch+`$this->fail()` pattern already in use in the codebase
- `tests/Feature/Appliances/ApplianceTestCase.php:20-29` — base class; `ApplianceShowTest` extends this unchanged

## What We're NOT Doing

- No changes to production code — this phase is test-only.
- No new base test case class — `ApplianceShowTest` extends the existing `ApplianceTestCase` directly.
- No tests for `edit.blade.php`, `index.blade.php`, or `create.blade.php` — they are already covered.
- No tests for the `RecordTaskCompletion` action directly — `RecordTaskCompletionTest:225-246` already covers the action-level guard.
- No global model scope changes — the no-global-scope architecture is an accepted project constraint (documented in `context/foundation/lessons.md`).

## Implementation Approach

Three sequential sub-phases, ordered by risk priority then cookbook update:

1. New `ApplianceShowTest` class (Risk #1 — highest priority: entirely missing coverage).
2. Fix `DashboardPageTest` (Risk #3 — existing test has a silent non-assertion).
3. Write §6.2 and §6.3 cookbook entries (documentation of shipped patterns).

Each sub-phase is independently runnable and verifiable.

---

## Phase 1: ApplianceShowTest — Cross-Household Ownership on the Show Page

### Overview

Create `tests/Feature/Appliances/ApplianceShowTest.php`. Two tests: happy path (owner views appliance) and 403 path (foreign appliance is rejected). Follows the structure of `ApplianceEditTest` exactly.

### Changes Required

#### 1. New test class

**File**: `tests/Feature/Appliances/ApplianceShowTest.php`

**Intent**: Prove that `pages.appliances.show` renders correctly for the owner and returns 403 when a foreign appliance ID is passed. The `abort_if` in `mount()` is the sole guard; without this test a future removal of that line would go undetected.

**Contract**: Namespace `Tests\Feature\Appliances`. Extends `ApplianceTestCase`. Two test methods:

- `test_authenticated_user_can_view_appliance()` — creates an appliance in `$this->household`, mounts `pages.appliances.show` with it, asserts `assertOk()` and `assertSee($appliance->name)`.
- `test_viewing_appliance_from_another_household_returns_403()` — creates `$otherHousehold = Household::factory()->create()` and `$appliance = Appliance::factory()->create(['household_id' => $otherHousehold->id])` (no pivot attachment — the attacker is `$this->user` who is NOT a member of `$otherHousehold`), mounts `pages.appliances.show` with the foreign appliance, asserts `->assertForbidden()`.

Imports needed: `App\Models\Appliance`, `App\Models\Household`, `Livewire\Volt\Volt`.

### Success Criteria

#### Automated Verification

- PHPUnit passes for the new class: `php artisan test tests/Feature/Appliances/ApplianceShowTest.php`
- Full Appliances suite passes (no regressions): `php artisan test tests/Feature/Appliances/`
- PHPStan passes: `./vendor/bin/phpstan analyse`
- Pint passes: `./vendor/bin/pint --test`

#### Manual Verification

- Read both test methods and confirm: the happy-path fixture uses `$this->household->id`, the 403 fixture uses `$otherHousehold->id` without a pivot attachment.
- Temporarily remove the `abort_if` line from `show.blade.php:17`, run `php artisan test tests/Feature/Appliances/ApplianceShowTest.php`, verify the 403 test now fails — then restore the line. This proves the test catches the regression rather than passing vacuously.

**Implementation Note**: After completing this phase and automated verification passes, perform the manual regression check above before proceeding to Phase 2.

---

## Phase 2: Strengthen DashboardPageTest — Close the Silent Non-Assertion

### Overview

Edit the existing `test_mark_done_rejects_foreign_household_task()` in `DashboardPageTest.php`. Add `$this->fail()` as the first line after `Volt::test()->call('markDone', ...)`, so the test fails explicitly when `ModelNotFoundException` is NOT thrown. Retain `assertDatabaseMissing` as a defense-in-depth outcome assertion.

### Changes Required

#### 1. Fix silent try/catch in DashboardPageTest

**File**: `tests/Feature/Dashboard/DashboardPageTest.php`

**Intent**: The test currently catches `ModelNotFoundException` silently (no assertion that it was thrown). If the `forHousehold()` scope were removed from `markDone()`, `findOrFail` would succeed, the catch block would never execute, and the test would still pass (only `assertDatabaseMissing` would catch the failure — and only if a `ServiceRecord` actually got written). Adding `$this->fail()` immediately after the call closes this gap.

**Contract**: In `test_mark_done_rejects_foreign_household_task()`, replace the bare try block body:

```php
// BEFORE (lines 117-121):
try {
    Volt::test('pages.dashboard')->call('markDone', $foreignTask->id);
} catch (ModelNotFoundException $e) {
    // scope guard worked — foreign task not found in user's household
}

// AFTER:
try {
    Volt::test('pages.dashboard')->call('markDone', $foreignTask->id);
    $this->fail('ModelNotFoundException not thrown — forHousehold() scope guard may have been removed from markDone()');
} catch (ModelNotFoundException $e) {
    // correct: forHousehold()->findOrFail() blocked the foreign task
}
```

The `assertDatabaseMissing` block at lines 123-125 remains unchanged after the try/catch.

### Success Criteria

#### Automated Verification

- PHPUnit passes for the Dashboard suite: `php artisan test tests/Feature/Dashboard/DashboardPageTest.php`
- Full test suite passes (no regressions): `php artisan test`
- PHPStan passes: `./vendor/bin/phpstan analyse`

#### Manual Verification

- Temporarily remove `.forHousehold($household->id)` from `markDone()` in `dashboard.blade.php` and run `php artisan test tests/Feature/Dashboard/DashboardPageTest.php`. Verify `test_mark_done_rejects_foreign_household_task` now fails with the `$this->fail()` message. Restore the scope chain.

**Implementation Note**: After completing this phase and automated verification passes, perform the manual scope-removal check above before proceeding to Phase 3.

---

## Phase 3: §6.2 and §6.3 Cookbook Update

### Overview

Replace the two `TBD` placeholders in `context/foundation/test-plan.md` §6.2 and §6.3 with the patterns established by Phases 1 and 2. This makes the cookbook actionable for future contributors.

### Changes Required

#### 1. §6.2 — Adding an integration test for a Livewire Volt component

**File**: `context/foundation/test-plan.md`

**Intent**: Replace the §6.2 `TBD` placeholder with the canonical Volt component test pattern, using `ApplianceShowTest` as the reference. Cover both happy path and forbidden path.

**Contract**: §6.2 body should document:
- When to use: testing any Volt page component's rendering and ownership guard.
- Reference test: `tests/Feature/Appliances/ApplianceShowTest.php`.
- The `Volt::test('pages.appliances.show', ['appliance' => $appliance])` call signature — the second argument is the route model binding array (key = route param name, value = model instance).
- Key rules: extend the relevant base TestCase; pass the model via binding array; use `->assertOk()` + `->assertSee()` for happy path; use `->assertForbidden()` for the 403 path.
- Run command: `php artisan test tests/Feature/Appliances/`.

#### 2. §6.3 — Adding a test for a new household-scoped Volt component or action

**File**: `context/foundation/test-plan.md`

**Intent**: Replace the §6.3 `TBD` placeholder with the second-household fixture pattern, using both `ApplianceShowTest` (for mount-level 403) and the strengthened `DashboardPageTest` (for exception-path assertions) as references.

**Contract**: §6.3 body should document:
- When to use: testing household isolation — proving a resource from household B cannot be accessed by household A's user.
- The second-household fixture: `Household::factory()->create()` + resource factory with the foreign `household_id`. No pivot attachment needed (the test user is NOT a member of the foreign household).
- Two assertion styles depending on the guard type:
  - `abort_if` in `mount()` (implicit binding): `Volt::test(..., ['appliance' => $foreignAppliance])->assertForbidden()`
  - `forHousehold()->findOrFail()` in an action (scoped query): try/catch + `$this->fail()` + `assertDatabaseMissing` (because `ModelNotFoundException` bubbles up as a PHP exception in `Volt::test()->call()`, not as HTTP 404).
- Key rule: do NOT use `->assertForbidden()` for actions that use scoped `findOrFail()` — the HTTP status is 404 (not 403), and the test must assert the exception, not the status.
- Reference tests: `ApplianceShowTest::test_viewing_appliance_from_another_household_returns_403` and `DashboardPageTest::test_mark_done_rejects_foreign_household_task`.
- Run command: `php artisan test tests/Feature/`.

### Success Criteria

#### Automated Verification

- §6.2 and §6.3 no longer contain `TBD`: `grep -c 'TBD' context/foundation/test-plan.md` count decreases by 2.
- Pint passes on the test plan file (N/A — markdown, not PHP).

#### Manual Verification

- Read §6.2 and confirm: the `Volt::test(..., ['appliance' => $appliance])` call signature is documented, happy-path and 403-path patterns are both shown, run command is present.
- Read §6.3 and confirm: the second-household fixture (no pivot) is documented, the `abort_if` / scoped-`findOrFail` distinction is clear, the "do NOT use `assertForbidden()` for scoped `findOrFail()` paths" rule is stated explicitly.

---

## Testing Strategy

### Integration Tests

Phase 1 adds `ApplianceShowTest` — 2 new tests. Phase 2 strengthens 1 existing test. All are `Volt::test()` integration tests using the `RefreshDatabase` trait and in-memory SQLite (from `phpunit.xml`).

No unit tests needed — no pure functions are being tested.

### Manual Testing Steps

1. After Phase 1: remove `abort_if` from `show.blade.php:17`, run `ApplianceShowTest`, verify failure; restore.
2. After Phase 2: remove `.forHousehold($household->id)` from `dashboard.blade.php markDone()`, run `DashboardPageTest`, verify failure at `$this->fail()` message; restore.
3. After Phase 3: open `context/foundation/test-plan.md` and read §6.2 and §6.3 to confirm they are actionable for a developer unfamiliar with the codebase.

## References

- Research: `context/changes/testing-authorization-depth/research.md`
- Cross-household 403 pattern reference: `tests/Feature/Appliances/ApplianceEditTest.php:53-67`
- try/catch + `$this->fail()` reference: `tests/Feature/Dashboard/RecordTaskCompletionTest.php:225-246`
- Base test case: `tests/Feature/Appliances/ApplianceTestCase.php:20-29`
- Dashboard test to fix: `tests/Feature/Dashboard/DashboardPageTest.php:104-126`
- Component under test: `resources/views/livewire/pages/appliances/show.blade.php:14-17`
- Dashboard component markDone(): `resources/views/livewire/pages/dashboard.blade.php:20-28`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: ApplianceShowTest — Cross-Household Ownership on the Show Page

#### Automated

- [x] 1.1 PHPUnit passes for ApplianceShowTest: `php artisan test tests/Feature/Appliances/ApplianceShowTest.php` — 1daafbb
- [x] 1.2 Full Appliances suite passes: `php artisan test tests/Feature/Appliances/` — 1daafbb
- [x] 1.3 PHPStan passes: `./vendor/bin/phpstan analyse` — 1daafbb
- [x] 1.4 Pint passes: `./vendor/bin/pint --test` — 1daafbb

#### Manual

- [ ] 1.5 Happy-path fixture uses `$this->household->id`; 403 fixture uses `$otherHousehold->id` with no pivot
- [ ] 1.6 Removing `abort_if` from `show.blade.php:17` causes 403 test to fail; restore line

### Phase 2: Strengthen DashboardPageTest — Close the Silent Non-Assertion

#### Automated

- [x] 2.1 Dashboard suite passes: `php artisan test tests/Feature/Dashboard/DashboardPageTest.php`
- [x] 2.2 Full test suite passes: `php artisan test`
- [x] 2.3 PHPStan passes: `./vendor/bin/phpstan analyse`

#### Manual

- [x] 2.4 Removing `forHousehold()` from `markDone()` causes test to fail with `$this->fail()` message; restore scope

### Phase 3: §6.2 and §6.3 Cookbook Update

#### Automated

- [ ] 3.1 `grep -c 'TBD' context/foundation/test-plan.md` count decreases by 2 vs current count

#### Manual

- [ ] 3.2 §6.2 documents `Volt::test(..., ['appliance' => $appliance])` call signature, happy path, 403 path, and run command
- [ ] 3.3 §6.3 documents second-household fixture (no pivot), `abort_if`/scoped-`findOrFail` distinction, and "do NOT use `assertForbidden()` for scoped `findOrFail()` paths" rule

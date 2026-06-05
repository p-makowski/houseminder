# Authorization Depth — Plan Brief

> Full plan: `context/changes/testing-authorization-depth/plan.md`
> Research: `context/changes/testing-authorization-depth/research.md`

## What & Why

Close two household-authorization test gaps in Phase 2 of the test plan rollout. The `pages.appliances.show` component has an `abort_if` ownership guard that is completely untested for cross-household access. The existing `markDone()` test catches `ModelNotFoundException` silently — if the scope guard were removed, the test would pass without failing.

## Starting Point

Phase 1 (calculation correctness) is complete. `edit.blade.php` is covered by `ApplianceEditTest:53-67`. `show.blade.php` uses the identical authorization pattern but has zero cross-household tests. `DashboardPageTest:104-126` has a try/catch with no `$this->fail()` — the sole real assertion is `assertDatabaseMissing`.

## Desired End State

`ApplianceShowTest` exists with a happy-path test and a 403 test for `show.blade.php`. `DashboardPageTest::test_mark_done_rejects_foreign_household_task` explicitly fails when `ModelNotFoundException` is not thrown. §6.2 and §6.3 in `test-plan.md` are filled in with the canonical second-household fixture and Volt authorization assertion patterns.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| ApplianceShowTest scope | 403 + happy path | Mirrors ApplianceEditTest structure; establishes parity across all resource pages | Plan |
| markDone() fix pattern | try/catch + `$this->fail()` | Keeps `assertDatabaseMissing` as outcome assertion AND explicitly asserts the exception path; matches `RecordTaskCompletionTest:225-246` | Research / Plan |
| Cross-household fixture | Foreign household only, no pivot | Attacker is `$this->user` who is NOT a member of the foreign household — no attachment needed | Research |
| Assert pattern for `abort_if` guards | `->assertForbidden()` | Implicit binding + `abort_if` returns HTTP 403; Volt::test wraps this correctly | Research |
| Assert pattern for scoped `findOrFail` guards | try/catch + exception assertion | `ModelNotFoundException` bubbles as PHP exception in `Volt::test()->call()`, not as HTTP 404 | Research |

## Scope

**In scope:**
- New `tests/Feature/Appliances/ApplianceShowTest.php` (2 tests)
- Edit `tests/Feature/Dashboard/DashboardPageTest.php:117-121` (add `$this->fail()`)
- Edit `context/foundation/test-plan.md` §6.2 and §6.3 (fill TBD placeholders)

**Out of scope:**
- No production code changes
- No changes to `edit.blade.php`, `index.blade.php`, or `create.blade.php` tests
- No new base test case class
- No global model scope changes

## Architecture / Approach

Two authorization patterns exist in the codebase: (1) implicit binding + `abort_if` in `mount()` → test with `->assertForbidden()`; (2) household-scoped `findOrFail()` in an action → test with try/catch + `$this->fail()` + `assertDatabaseMissing`. This phase adds tests for the one untested instance of pattern 1 (`show.blade.php`) and hardens the existing test for pattern 2 (`markDone()`). The cookbook update documents both patterns so future contributors know which assertion style to use for each guard type.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. ApplianceShowTest | New test class with happy path + 403 | Risk that fixture creates wrong household_id — verify manually that `$otherHousehold->id` is used, not `$this->household->id` |
| 2. Fix DashboardPageTest | Silent-pass gap closed; test now fails when scope guard breaks | `$this->fail()` message must be inserted before the catch, not after it |
| 3. Cookbook §6.2/§6.3 | Both TBD placeholders filled in with canonical patterns | Risk of under-documenting the `findOrFail` vs `abort_if` distinction — the "do NOT use `assertForbidden()` for scoped `findOrFail()` paths" rule must be explicit |

**Prerequisites:** Phase 1 complete (testing-calculation-correctness), base test cases exist  
**Estimated effort:** ~1 session; 3 phases, all small

## Open Risks & Assumptions

- `Volt::test()->call()` continues to surface `ModelNotFoundException` as a PHP exception (not an HTTP response). If a future Livewire version changes this behavior, the try/catch pattern in Phase 2 would need to be revisited.
- `show.blade.php` has no action methods — Phase 1 only tests `mount()`. If action methods are added to this component in the future, they will need their own authorization tests.

## Success Criteria (Summary)

- `php artisan test tests/Feature/` passes with no failures or skips after all three phases.
- Temporarily removing `abort_if` from `show.blade.php` causes `ApplianceShowTest` to fail.
- Temporarily removing `forHousehold()` from `markDone()` causes `DashboardPageTest::test_mark_done_rejects_foreign_household_task` to fail at the `$this->fail()` message.

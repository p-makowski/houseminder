# Phase 3 — Edge Cases + AI Contract — Plan Brief

> Full plan: `context/changes/testing-edge-cases-ai-contract/plan.md`
> Research: `context/changes/testing-edge-cases-ai-contract/research.md`

## What & Why

Close the remaining integration-test gaps for Risks #4, #5, and #6 from `test-plan.md §3 Phase 3`. Risk #4 (dashboard bucket misclassification) and Risk #5 (unconfirmed task leakage) need exact-boundary and multi-window tests. Risk #6 (AI contract) requires two production fixes before its tests can assert correct behavior: the wizard currently shows a blank step 2 on zero AI tasks (no error), and the AI action silently passes through tasks with missing required fields.

## Starting Point

Phases 1 and 2 (`testing-calculation-correctness`, `testing-authorization-depth`) are complete. `DashboardPageTest.php` has bucket tests with relative offsets (`subDay()`, `addDays(3)`, `addDays(30)`) and one unconfirmed-task test for the overdue path. `AiFailureTest.php` covers `PrismException` + retry via action mock; the zero-tasks path, missing-fields path, and `\Throwable` fallback are unexercised. `test-plan.md §2` Risk #4 guidance contains a factual error that contradicts the actual strict-`<` operator used for the overdue bucket.

## Desired End State

Nine new tests are green: 3 AI contract tests, 4 dashboard boundary tests, 2 unconfirmed-bucket tests. Two production files are patched: `fetchSuggestions()` shows an immediate error on zero tasks; `GenerateMaintenancePlan` validates required fields before returning. `test-plan.md §2` Risk #4 guidance is corrected. `test-plan.md §6.2` documents the `Prism::fake()` pattern for future AI contract tests. Phase 3 is marked `complete`.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Zero-tasks UX | Fix + test (immediate error in fetchSuggestions) | Testing blank-state behavior would assert a bug, not correct behavior; the fix is a single guard before the `$aiError = null` clear | Plan |
| Missing-fields handling | Add PHP validation in action + test | Throws at the action boundary, surfaces error via existing `\Throwable` catch — action contract becomes explicit and testable | Plan |
| `\Throwable` fallback test | Include | One extra test method; closes the last untested branch in error handling at trivial cost | Plan |
| test-plan.md §2 correction | Yes | The document contradicts the code (strict `<` for overdue); leaving it wrong misleads future developers and agents | Plan |
| Risk #5 breadth | Add dueThisWeek + upcoming tests | Two extra methods that are nearly free to write given the shared pattern | Plan |
| Test tooling for Risk #6 | Prism::fake() (not action mock) | Exercises the real action → component path including the new field validation code | Research |

## Scope

**In scope:**
- Production fix: `fetchSuggestions()` zero-tasks immediate error
- Production fix: `GenerateMaintenancePlan` PHP field validation
- `AiContractTest.php` — 3 tests (zero-tasks, missing-fields, `\Throwable`)
- `DashboardBoundaryTest.php` — 4 boundary tests at exact `now()` and `now()->addDays(7)` transitions
- `DashboardPageTest.php` — 2 new unconfirmed-task absence tests (dueThisWeek, upcoming)
- `test-plan.md §2` Risk #4 guidance correction
- `test-plan.md §6.2` cookbook fill-in

**Out of scope:**
- Metric dashboard unconfirmed tests (same scope, structural argument holds)
- `km` metric task coverage
- CI gate wiring (Phase 4)

## Architecture / Approach

Production fixes go first (Phase 1) so tests assert correct behavior. Risk #6 tests use `Prism::fake()` with `StructuredResponseFake` fixtures to exercise the real action → component path — not action mocks. Risk #4 boundary tests rely on `DashboardTestCase::freezeTime()` to make exact-instant assertions deterministic. Risk #5 tests follow the existing `test_unconfirmed_task_does_not_appear` pattern, adding two more windows. All new test classes extend the appropriate base (`ApplianceTestCase`, `DashboardTestCase`) per the project's feature-namespace convention.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Risk #6 Production Fixes | Zero-tasks immediate error; action field validation | fetchSuggestions() guard inserted in wrong position wipes the error |
| 2. Risk #6 Tests | `AiContractTest.php` — 3 AI failure mode tests | `StructuredResponseFake` fixture setup may need tracing from existing tests |
| 3. Risk #4 Boundary Tests | `DashboardBoundaryTest.php` — 4 exact-boundary tests; test-plan §2 corrected | Double-bucket assertion (present + absent) is easy to accidentally write one-sided |
| 4. Risk #5 Unconfirmed Tests | 2 new methods in `DashboardPageTest.php` | Minimal — near-copy of existing pattern |
| 5. §6.2 Cookbook | `test-plan.md §6.2` filled in; Phase 3 marked complete | Content quality only |

**Prerequisites:** Phases 1 and 2 must complete in order (tests assert the fixed behavior). Phases 3–5 are independent of each other after Phase 2.
**Estimated effort:** ~1-2 sessions across 5 phases. Phase 2 is the longest — `Prism::fake()` fixture setup requires tracing `AddApplianceWizardTest:21`.

## Open Risks & Assumptions

- The minimum component properties `fetchSuggestions()` reads (likely `applianceName`, `applianceModel`, `typeId`) must be traced from `create.blade.php:107-121` before Phase 2 tests can set up the Volt component correctly.
- The `\Throwable` catch block's current error message (lines 118-120) must be verified to be non-empty before Phase 2 asserts on it — if it currently sets `$aiError = ''`, it must be updated as part of Phase 1.
- `StructuredResponseFake` API for configuring arbitrary `structured` responses should be verified from Prism's test utilities before writing Phase 2 fixtures.

## Success Criteria (Summary)

- `composer test` passes with 9 new tests green and zero regressions
- Zero AI tasks and missing-field AI responses both surface an explicit `$aiError` in the wizard — no blank states, no silent pass-through
- Every calendar bucket boundary is proven by an exact-timestamp assertion, not a relative offset

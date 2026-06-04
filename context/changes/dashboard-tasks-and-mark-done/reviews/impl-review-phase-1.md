<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Dashboard Tasks and Mark Done

- **Plan**: context/changes/dashboard-tasks-and-mark-done/plan.md
- **Scope**: Phase 1 of 2
- **Date**: 2026-06-04
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical  4 warnings  3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Grounding

All 3 implementation files match plan intent exactly. Automated criteria: 8/8 tests pass, PHPStan clean, Pint clean. Manual 1.4 ✓ (3 scopes confirmed), 1.5 ✓ (file exists), 1.6 ✓ (6/6 appliance wizard tests pass).

## Findings

### F1 — now() called three times inside transaction

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Actions/RecordTaskCompletion.php:20–33
- **Detail**: now() is called once for ServiceRecord.completed_at, once for last_completed_at, and once as the base for next_due_at arithmetic. The three timestamps can differ by microseconds. Capturing it once makes the action's three writes coherent.
- **Fix**: Capture now() once before the transaction and pass it into the closure as $completedAt, use $completedAt for all three writes and $completedAt->copy()->addDays(...) for next_due_at arithmetic.
- **Decision**: FIXED — captured now() once as $completedAt before transaction

### F2 — execute() vs __invoke() inconsistency with GenerateMaintenancePlan

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: app/Actions/RecordTaskCompletion.php:14
- **Detail**: GenerateMaintenancePlan uses __invoke() as its entry point. RecordTaskCompletion uses execute(), making call sites look different. One call site exists (the test) and one more will be added in Phase 2 (the Volt component).
- **Fix**: Rename execute() to __invoke() and update the one call site in RecordTaskCompletionTest.php to use (new RecordTaskCompletion)(...).
- **Decision**: FIXED + ACCEPTED-AS-RULE: "Action classes must use __invoke()"

### F3 — Test class duplicates ApplianceTestCase setUp boilerplate

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Feature/Dashboard/RecordTaskCompletionTest.php:17
- **Detail**: RecordTaskCompletionTest extends TestCase directly and re-implements user + household factory setup, pivot attach, and actingAs — identical to ApplianceTestCase. A future change to the household setup pattern would need to be made in two places.
- **Fix**: Create tests/Feature/Dashboard/DashboardTestCase.php mirroring ApplianceTestCase, add the $appliance fixture on top, and have RecordTaskCompletionTest extend it.
- **Decision**: FIXED + ACCEPTED-AS-RULE: "Feature test namespaces must have their own base test case"

### F4 — Multi-household authorization uses first() not exists()

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Actions/RecordTaskCompletion.php:16
- **Detail**: $user->households()->first() silently picks the first membership if a user belongs to multiple households. Note: the plan explicitly specifies this pattern, matching create.blade.php:169-170, so this is inherited from the established codebase pattern, not an implementation mistake.
- **Fix A ⭐ Recommended**: Keep plan-specified pattern; record as lesson. Adopting exists() here while every other auth-guarded action uses first() would create inconsistency — fix the pattern globally or accept it for now and document the single-household assumption.
  - Strength: Keeps Phase 1 consistent with the rest of the codebase; the lesson records the risk for future multi-household work.
  - Tradeoff: Bug remains if multi-household is ever added without revisiting this code.
  - Confidence: HIGH — current app scope is explicitly single-household.
  - Blind spot: Whether multi-household is in the roadmap.
- **Fix B**: Switch to exists() now. Replace first() check with abort_if(!$user->households()->where('households.id', $task->appliance->household_id)->exists(), 403).
  - Strength: Correct for any number of households; one query not two.
  - Tradeoff: Diverges from every other action's pattern; needs global reconciliation eventually.
  - Confidence: MED — haven't audited all other call sites.
  - Blind spot: Other actions using first() remain inconsistent.
- **Decision**: ACCEPTED-AS-RULE: "Ownership guards assume single household per user"

### F5 — appliance relationship lazy-loads in execute()

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Actions/RecordTaskCompletion.php:17
- **Detail**: $task->appliance->household_id triggers a lazy load if the caller didn't eager-load appliance. Fine for current single-call use; becomes N+1 if a future caller iterates tasks.
- **Fix**: Add $task->loadMissing('appliance') at the top of execute() to make the action self-contained.
- **Decision**: FIXED — added $task->loadMissing('appliance') at top of __invoke()

### F6 — scopeForHousehold subquery needs index on appliances.household_id

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Models/MaintenanceTask.php:32
- **Detail**: whereHas compiles to a correlated EXISTS subquery on appliances. Correct at household scale; verify a migration added an index on appliances.household_id.
- **Fix**: Grep the migration files to confirm the index exists. No PHP change needed.
- **Decision**: VERIFIED — foreignId()->constrained() creates the index automatically; no action needed

### F7 — abort_if couples action to HTTP context

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Architecture
- **Location**: app/Actions/RecordTaskCompletion.php:17
- **Detail**: abort_if throws HttpException. Fine for current HTTP-only use. If ever reused from Artisan/queue, replace with AuthorizationException.
- **Fix**: Accept for now; no change needed until a non-HTTP caller appears.
- **Decision**: ACCEPTED-AS-RULE: "Action classes that use abort_if are coupled to HTTP context"

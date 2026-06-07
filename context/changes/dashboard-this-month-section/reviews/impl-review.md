<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: "This Month" Section + Recurrence Labels

- **Plan**: `context/changes/dashboard-this-month-section/plan.md`
- **Scope**: Full plan — Phase 1 + Phase 2
- **Date**: 2026-06-07
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical · 3 warnings · 0 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS (20/20 changes matched exactly) |
| Scope Discipline | PASS |
| Safety & Quality | WARNING (F1, F2, F3) |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS (86 tests green, PHPStan clean) |

## Findings

### F1 — Appliance detail sections show unconfirmed tasks

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `resources/views/livewire/pages/appliances/show.blade.php:69–130`
- **Detail**: The plan explicitly chose NOT to use the `calendar()` scope on the appliance detail (to preserve display of all tasks and avoid breaking existing tests). The five new computed properties omit `where('is_confirmed', true)`. Unconfirmed AI-generated tasks appear in the date sections alongside confirmed tasks. The dashboard hides them behind `calendar()`. This was a deliberate plan decision, but the appliance detail is now a section-based management view and unconfirmed tasks in "This month" or "Overdue" may confuse the user.
- **Fix A ⭐ Recommended**: Accept the design — add a subtle "unconfirmed" badge or muted styling to tasks where `is_confirmed = false` so users can distinguish draft tasks from active ones in each section. No query change; just a Blade `@if(!$task->is_confirmed)` badge in the read card.
  - Strength: Preserves the management-view intent; makes the mixed state legible rather than hiding it.
  - Tradeoff: Small Blade change in each section's read card. Slightly more template complexity.
  - Confidence: MEDIUM — without knowing how often the wizard is abandoned mid-flight, the real-world frequency of unconfirmed tasks is uncertain.
  - Blind spot: Haven't checked whether the wizard always cleans up unconfirmed tasks on abandonment.
- **Fix B**: Filter to `is_confirmed = true` — add `->where('is_confirmed', true)` to the five computed property queries, and add `'is_confirmed' => true` to ApplianceShowSectionsTest factory calls.
  - Strength: Dashboard and appliance detail behave consistently.
  - Tradeoff: Unconfirmed tasks disappear from the page entirely — users lose the ability to see/manage them from the appliance view.
  - Confidence: HIGH — technically trivial but changes the UX contract significantly.
  - Blind spot: There may be no UI path to reach unconfirmed tasks once they're hidden here.
- **Decision**: PENDING

### F2 — Section tests don't set is_confirmed=true; tests pass by omission

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `tests/Feature/Appliances/ApplianceShowSectionsTest.php:22–83`
- **Detail**: All six factory calls omit `'is_confirmed' => true`. Factory default is `false`. Dashboard test conventions always set it explicitly. Tests currently pass because the computed properties have no `is_confirmed` filter. If F1-Fix-B is applied, all six tests immediately go red. The coupling makes the tests brittle and silent about their intent.
- **Fix**: Be explicit about intent — if Fix A from F1 is accepted (show all tasks), add `'is_confirmed' => false` explicitly to one test to assert unconfirmed tasks are visible. If Fix B is chosen, add `'is_confirmed' => true` to all six factory calls.
- **Decision**: PENDING

### F3 — resolveHouseholdId() runs a DB query per computed property

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `resources/views/livewire/pages/dashboard.blade.php:90–93`
- **Detail**: `resolveHouseholdId()` calls `Auth::user()->households()->first()` on every invocation. There are now 4 calendar-section computed properties calling it (was 3 before `dueThisMonth()`). Each is an independent DB query. Livewire `#[Computed]` caches section results but not this helper. Pre-existing issue; the new property adds one more hit.
- **Fix**: Cache the household id once in `mount()`: store as a property (e.g., `private int $householdId`) and assign `$this->householdId = Auth::user()->households()->first()->id` there. Aligns with the existing `abort_if` call in `mount()` which already does this lookup.
- **Decision**: PENDING

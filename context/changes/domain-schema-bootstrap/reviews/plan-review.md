<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Domain Schema Bootstrap

- **Plan**: `context/changes/domain-schema-bootstrap/plan.md`
- **Mode**: Deep
- **Date**: 2026-06-01
- **Verdict**: SOUND (after fixes)
- **Findings**: 0 critical  2 warnings  1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | WARNING |
| Plan Completeness | WARNING |

## Grounding

3/3 modified paths ✓, RegistrationTest.php ✓, no FK pragma in AppServiceProvider, brief↔plan ✓

## Findings

### F1 — Registration tests will fail after Phase 1

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Blind Spots
- **Location**: Phase 1 — Registration update; Success Criteria "php artisan test passes with no regressions"
- **Detail**: `tests/Feature/Auth/RegistrationTest.php` (confirmed present) submits registration without `household_name`. Phase 1 adds `household_name` as `required|string|max:255`. The test breaks the moment Phase 1 lands — contradicting the plan's own Success Criteria.
- **Fix ⭐ Recommended**: Add test update to Phase 1's file list — pass `household_name` in the POST data. One-line change, same commit as the Volt component.
  - Strength: Keeps `php artisan test` green throughout; Success Criteria achievable as written.
  - Tradeoff: None — trivially scoped.
  - Confidence: HIGH — only Breeze registration test; no other callers.
  - Blind spot: Factory may also need `household_name` — worth a quick check during implementation.
- **Decision**: FIXED — Phase 1 now includes step 6 (RegistrationTest.php update) and automated criterion `1.3 php artisan test passes`

### F2 — DatabaseSeeder test user has no household

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: Phase 2 — DatabaseSeeder
- **Detail**: DatabaseSeeder creates a test User but after Phase 1, that user has no Household. S-01 dev who seeds and logs in gets null from `$user->households()->first()`.
- **Fix**: Extend the test User block to create a Household and attach it with `role = owner`.
  - Strength: Local dev seeded state matches registration output; S-01 starts without manual DB fixup.
  - Tradeoff: Two extra lines in the seeder.
  - Confidence: HIGH.
  - Blind spot: None.
- **Decision**: FIXED — Phase 2 DatabaseSeeder step updated to create Household + attach pivot for test user

### F3 — SQLite enum columns are application-level only

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Architectural Fitness
- **Location**: Phase 1 (household_user.role), Phase 3 (maintenance_tasks.interval_unit, .anchor_type)
- **Detail**: Laravel's `$table->enum(...)` compiles to plain TEXT in SQLite — no CHECK constraint. Invalid values from raw inserts persist silently. Eloquent validation is the only guard in v1.
- **Fix**: No code change — add a one-line note to Open Risks & Assumptions.
- **Decision**: ACCEPTED — v1 on SQLite; Eloquent validation is the guard; revisit if migrating to Postgres

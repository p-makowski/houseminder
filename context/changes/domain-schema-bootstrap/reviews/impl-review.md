<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Domain Schema Bootstrap

- **Plan**: `context/changes/domain-schema-bootstrap/plan.md`
- **Scope**: All phases (1–3)
- **Date**: 2026-06-03
- **Verdict**: NEEDS ATTENTION → APPROVED after triage fixes
- **Findings**: 1 critical  4 warnings  5 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Plan Drift

21/21 planned files verified — zero drift, missing, or extras.

## Automated Verification

- `php artisan migrate` — nothing to migrate (all 6 ran during implementation) ✓
- `php artisan test` — 26/26 passed ✓
- `ApplianceType::whereNull('household_id')->count()` → 13 ✓

## Findings

### F1 — DatabaseSeeder plaintext password relies on implicit cast

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: database/seeders/DatabaseSeeder.php:27
- **Detail**: `'password' => 'password'` relies on the User model's `'password' => 'hashed'` cast to protect the DB. If the cast were removed, plaintext would be stored. UserFactory uses Hash::make() explicitly.
- **Fix**: Replace `'password' => 'password'` with `'password' => Hash::make('password')`.
- **Decision**: FIXED

### F2 — MaintenanceTask missing float casts for metric fields

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: app/Models/MaintenanceTask.php:12
- **Detail**: `last_metric_value` and `next_due_at_value` are double DB columns with no Eloquent cast. S-01/S-02 comparisons against user-entered meter readings would receive strings, causing silent comparison bugs.
- **Fix**: Add `'last_metric_value' => 'float'` and `'next_due_at_value' => 'float'` to casts().
- **Decision**: FIXED

### F3 — DatabaseSeeder household block not wrapped in transaction

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: database/seeders/DatabaseSeeder.php:32-35
- **Detail**: Household::create() + attach() without a transaction. If attach() fails, a dangling unowned household remains.
- **Fix**: Wrap in `DB::transaction(fn() => ...)`.
- **Decision**: FIXED

### F4 — DB::transaction() in registration has no deadlock retry

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: resources/views/livewire/pages/auth/register.blade.php:38
- **Detail**: Default retry count is 1. No-op on SQLite v1, but defensive for future DB migration.
- **Fix**: Pass `3` as second argument to DB::transaction().
- **Decision**: FIXED

### F5 — ApplianceTypeSeeder has no DB unique index backing updateOrCreate

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: database/migrations/2026_06_01_000003_create_appliance_types_table.php
- **Detail**: No composite unique index on (name, household_id). Deliberate plan decision due to SQLite NULL quirk.
- **Fix A ⭐ Applied**: Added comment in migration documenting the deliberate omission and SQLite NULL behaviour.
- **Decision**: FIXED via Fix A (documented)

### F6 — Household.applianceTypes() silently omits global types

- **Severity**: ℹ️ OBSERVATION
- **Dimension**: Pattern Consistency
- **Location**: app/Models/Household.php:26
- **Decision**: ACCEPTED-AS-RULE: "Appliance type queries must use the two-tier pattern" → lessons.md

### F7 — Appliance.model non-nullable with no model-layer guard

- **Severity**: ℹ️ OBSERVATION
- **Dimension**: Data Safety
- **Location**: app/Models/Appliance.php:12
- **Decision**: ACCEPTED-AS-RULE: "Appliance.model must be validated as required in all write paths" → lessons.md

### F8 — No enforcement of datetime vs. metric interval exclusivity

- **Severity**: ℹ️ OBSERVATION
- **Dimension**: Data Safety
- **Location**: app/Models/MaintenanceTask.php
- **Decision**: ACCEPTED-AS-RULE: "MaintenanceTask: interval_unit determines which next_due field is authoritative" → lessons.md

### F9 — 'owner' role hardcoded as string in two places

- **Severity**: ℹ️ OBSERVATION
- **Dimension**: Pattern Consistency
- **Location**: register.blade.php:41, DatabaseSeeder.php:34
- **Decision**: SKIPPED — deferred to S-01 when a HouseholdRole enum makes sense

### F10 — TOCTOU on email uniqueness

- **Severity**: ℹ️ OBSERVATION
- **Dimension**: Safety & Quality
- **Location**: register.blade.php:29
- **Decision**: SKIPPED — non-actionable; fully guarded by DB UNIQUE constraint on users.email

<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: PHPStan Green Implementation Plan

- **Plan**: context/changes/phpstan-green/plan.md
- **Scope**: All phases (1–3)
- **Date**: 2026-06-06
- **Verdict**: APPROVED
- **Findings**: 0 critical, 1 warning, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Success Criteria

| Check | Result |
|-------|--------|
| 1.1 `ls database/factories/ServiceRecordFactory.php` | FILE_OK ✅ |
| 1.2 PHPStan error count does not increase | 0 errors ✅ |
| 2.1 PHPStan reports exactly 1 remaining error | confirmed ✅ |
| 2.2 `composer test` | 93/93 pass ✅ |
| 3.1 `composer phpstan` exits 0 | [OK] 0 errors ✅ |
| 3.2 `composer test` | 93/93 pass ✅ |

## Findings

### F1 — Relationship @return annotations use $this instead of concrete class

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: app/Models/{Appliance,ApplianceType,Household,MaintenanceTask,ServiceRecord,User}.php (11 sites)
- **Detail**: Plan specified `BelongsTo<Household, Appliance>` etc. but implementation correctly delivers `BelongsTo<Household, $this>` — required by Larastan v3.x invariant TDeclaringModel. PHPStan exits 0. Plan text documented the concrete-class form.
- **Fix**: Update plan.md Group B bullets to use `$this` instead of concrete class names. Append lesson to lessons.md.
- **Decision**: FIXED — plan.md updated, lesson appended to context/foundation/lessons.md

### F2 — User.php groups HasFactory and Notifiable on one use line (pre-existing)

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: app/Models/User.php:21
- **Detail**: `use HasFactory, Notifiable;` groups two traits on one line. All other models use single-trait use statements. Predated this change.
- **Fix**: Split to two separate `use` statements.
- **Decision**: FIXED — split to `use HasFactory;` / `use Notifiable;`

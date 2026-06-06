# PHPStan Green — Plan Brief

> Full plan: `context/changes/phpstan-green/plan.md`
> Research: `context/changes/phpstan-green/research.md`

## What & Why

PHPStan level 6 with Larastan v3.10.0 reports 24 errors across `app/Models/` and `app/Actions/`. All are type-annotation gaps — no runtime logic is wrong. Adding the missing PHPDoc annotations brings the codebase to a clean PHPStan baseline and unlocks reliable static analysis going forward.

## Starting Point

All application logic is correct at runtime. PHPStan cannot infer types through Eloquent relationships without explicit `@return BelongsTo<…>` / `@return HasMany<…>` generics, and Larastan v3.10.0 does not read model property types from the method-based `casts(): array` form introduced in Laravel 11.

## Desired End State

`composer phpstan` exits 0 with `[OK] No errors`. `composer test` continues to pass. No PHP logic is changed — only PHPDoc comments and one new factory stub exist in the diff.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|----------|--------|------------------|--------|
| ServiceRecordFactory scope | Full factory (all 4 fillable fields) | Immediately usable in future tests without editing | Plan |
| @property annotation breadth | All 5 cast fields on MaintenanceTask | One-shot fix for the Larastan method-casts gap; prevents the same error on anchor_date, next_due_at, last_metric_value, next_due_at_value | Plan |
| Inner lambda annotation | Annotate `Builder<Appliance> $q` preemptively | Closes the obvious next error before it's reported; costs one line while the file is already open | Plan |
| RecordTaskCompletion | No changes to this file | Its 2 errors are downstream of relationship generics; they resolve automatically in Phase 2 | Research |

## Scope

**In scope:**
- Create `database/factories/ServiceRecordFactory.php`
- Add `@use HasFactory<…>` to 5 models (Appliance, ApplianceType, Household, MaintenanceTask, ServiceRecord)
- Add `@return` generics to 11 relationship methods across 6 models + User
- Add `@param Builder<MaintenanceTask>` to 3 query scopes + `@param Builder<Appliance>` to inner lambda
- Add `@property` PHPDoc for 5 cast fields on MaintenanceTask
- Add `@return array<int, array{…}>` to GenerateMaintenancePlan::__invoke

**Out of scope:**
- Any runtime logic changes
- Raising PHPStan above level 6
- Adding tests (annotation-only change has nothing to test behaviourally)
- Adding `@property` annotations to models other than MaintenanceTask

## Architecture / Approach

Pure PHPDoc — PHP has no native generics. Every fix is a comment above a method or class. The one non-annotation change is creating `ServiceRecordFactory.php`, a factory stub required before `@use HasFactory<ServiceRecordFactory>` can compile. `User.php` is the correct template for all `@use HasFactory<…>` annotations (already done there on line 20).

## Phases at a Glance

| Phase | What it delivers | Key risk |
|-------|-----------------|----------|
| 1. Create ServiceRecordFactory | Factory stub that unblocks HasFactory annotation | Stub must follow project factory format exactly |
| 2. Model PHPDoc Annotations | 22 of 24 errors resolved; RecordTaskCompletion errors resolve as side effect | Typo in generic class name is a new PHPStan error, not a fatal |
| 3. Action PHPDoc Annotation | Final error resolved; PHPStan exits 0 | Array shape type must match actual Prism response structure |

**Prerequisites:** None — this branch is clean on main.  
**Estimated effort:** ~1 session; all changes are mechanical annotations.

## Open Risks & Assumptions

- Larastan v3.10.0 must accept `BelongsToMany<TRelated, TDeclaringModel>` with 2 type params (the error says "2-4 required"). The 2-type form is confirmed as the minimum.
- The `@return array{…}` shape for GenerateMaintenancePlan matches the Prism structured schema defined in the same method — verified against the schema definition at `app/Actions/GenerateMaintenancePlan.php:24-45`.

## Success Criteria (Summary)

- `composer phpstan` exits 0 with `[OK] No errors`
- `composer test` passes (no regressions)
- Diff contains only PHPDoc additions and one new factory file

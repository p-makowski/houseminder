# PHPStan Green Implementation Plan

## Overview

Add missing PHPDoc type annotations across 8 files to bring the codebase to zero PHPStan level-6 errors. All 24 reported errors are annotation gaps — no runtime logic changes, no schema changes, no migrations.

## Current State Analysis

PHPStan 2.x + Larastan v3.10.0 at level 6 reports 24 errors across `app/Models/` and `app/Actions/`. Larastan requires explicit generic type parameters on Eloquent relationship return types, `HasFactory` trait usage, and `Builder` scope parameters. Additionally, Larastan v3.10.0 does not infer model property types from the method-based `casts(): array` form (a Laravel 11 pattern), causing one property-assignment error in `RecordTaskCompletion`.

`User.php` already uses the correct `/** @use HasFactory<UserFactory> */` pattern and serves as the reference template.

`ServiceRecord` is the only model using `HasFactory` without a corresponding factory file in `database/factories/` — this must be created before `@use HasFactory<ServiceRecordFactory>` can reference a concrete class.

### Key Discoveries:

- `app/Models/User.php:20` — correct `@use HasFactory<UserFactory>` template all other models must mirror
- `database/factories/` — all factories present except `ServiceRecordFactory.php`
- `app/Models/MaintenanceTask.php:47-57` — `casts()` method form not inferred by Larastan v3.10.0; requires explicit `@property` PHPDoc
- `app/Actions/RecordTaskCompletion.php:20,30` — errors are downstream of missing generics on `User::households()` and `MaintenanceTask::appliance()`; no changes to this file needed
- `app/Models/MaintenanceTask.php:34` — inner `Builder $q` lambda inside `scopeForHousehold` is untyped; does not error at level 6 but will at level 7+

## Desired End State

`composer phpstan` exits 0 with the message `[OK] No errors`. All existing tests continue to pass. No PHP runtime code is modified — only PHPDoc annotations and one new factory stub are added.

## What We're NOT Doing

- Not changing any PHP runtime logic in models or actions
- Not touching `RecordTaskCompletion.php` directly (its errors resolve as a side effect of Phase 2)
- Not upgrading PHPStan or Larastan versions
- Not adding tests (this change has no behaviour to verify beyond PHPStan passing)
- Not adding `@property` annotations to models other than `MaintenanceTask` (only MaintenanceTask is affected by the method-casts gap)
- Not raising PHPStan to level 7+

## Implementation Approach

Three phases ordered by dependency: create the missing factory first (unblocks HasFactory annotation), then annotate all models in a single pass (Groups A+B+C+F), then annotate the one action (Group D). PHPStan is run as the gate after each phase.

---

## Phase 1: Create ServiceRecordFactory

### Overview

`ServiceRecord` uses the `HasFactory` trait but has no factory file. PHPStan requires `@use HasFactory<ServiceRecordFactory>` to reference a concrete class. This phase creates the stub so Phase 2 can reference it.

### Changes Required:

#### 1. New factory file

**File**: `database/factories/ServiceRecordFactory.php`

**Intent**: Create a full factory for `ServiceRecord` matching the project's factory conventions, with all four fillable fields and a sensible default for the required FK.

**Contract**: The class must extend `Factory<ServiceRecord>`, carry `/** @extends Factory<ServiceRecord> */` PHPDoc, include `declare(strict_types=1)`, and implement `definition()` returning all fields in `ServiceRecord`'s `#[Fillable]` attribute. FK defaults to `MaintenanceTask::factory()`. Optional fields (`metric_reading`, `notes`) default to `null`.

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MaintenanceTask;
use App\Models\ServiceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRecord>
 */
class ServiceRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'maintenance_task_id' => MaintenanceTask::factory(),
            'completed_at' => now(),
            'metric_reading' => null,
            'notes' => null,
        ];
    }
}
```

### Success Criteria:

#### Automated Verification:

- File exists: `ls database/factories/ServiceRecordFactory.php`
- PHPStan still runs without fatal autoload errors: `composer phpstan 2>&1 | grep -v "ServiceRecord"` (count does not increase)

---

## Phase 2: Model PHPDoc Annotations

### Overview

Add all missing PHPDoc annotations across six model files and `User`. This single pass covers:
- Group A (5 errors): `@use HasFactory<ConcreteFactory>` on all models except User
- Group B (11 errors): `@return BelongsTo<…>`, `@return HasMany<…>`, `@return BelongsToMany<…>` on all relationship methods
- Group C (3 errors): `@param Builder<MaintenanceTask>` on all three query scopes in `MaintenanceTask`, plus `@param Builder<Appliance>` on the inner lambda (preemptive)
- Group F (1 error): `@property` PHPDoc on MaintenanceTask for all 5 datetime/float cast fields

Groups E (2 errors in `RecordTaskCompletion`) resolve automatically as a side effect of annotating `User::households()` and `MaintenanceTask::appliance()`.

### Changes Required:

#### 1. Appliance model

**File**: `app/Models/Appliance.php`

**Intent**: Add `@use HasFactory<ApplianceFactory>` to the trait usage and `@return` generics to all three relationship methods.

**Contract**:
- Before `use HasFactory;` on line 16: `/** @use HasFactory<\Database\Factories\ApplianceFactory> */`
- `household(): BelongsTo` → annotate `@return BelongsTo<Household, Appliance>`
- `applianceType(): BelongsTo` → annotate `@return BelongsTo<ApplianceType, Appliance>`
- `maintenanceTasks(): HasMany` → annotate `@return HasMany<MaintenanceTask, Appliance>`

#### 2. ApplianceType model

**File**: `app/Models/ApplianceType.php`

**Intent**: Add `@use HasFactory<ApplianceTypeFactory>` and `@return` generics to both relationship methods.

**Contract**:
- Before `use HasFactory;`: `/** @use HasFactory<\Database\Factories\ApplianceTypeFactory> */`
- `household(): BelongsTo` → `@return BelongsTo<Household, ApplianceType>`
- `appliances(): HasMany` → `@return HasMany<Appliance, ApplianceType>`

#### 3. Household model

**File**: `app/Models/Household.php`

**Intent**: Add `@use HasFactory<HouseholdFactory>` and `@return` generics to all three relationship methods.

**Contract**:
- Before `use HasFactory;`: `/** @use HasFactory<\Database\Factories\HouseholdFactory> */`
- `users(): BelongsToMany` → `@return BelongsToMany<User, Household>` (2-type form; pivot type omitted)
- `appliances(): HasMany` → `@return HasMany<Appliance, Household>`
- `applianceTypes(): HasMany` → `@return HasMany<ApplianceType, Household>`

#### 4. MaintenanceTask model

**File**: `app/Models/MaintenanceTask.php`

**Intent**: Add `@use HasFactory<MaintenanceTaskFactory>`, `@property` class-level PHPDoc for all 5 cast fields, `@param Builder<MaintenanceTask>` on the 3 scope methods, `@param Builder<Appliance>` on the inner lambda, and `@return` generics on both relationship methods.

**Contract**: The `@property` block goes immediately before `class MaintenanceTask`. Scope method annotations use `@param` only (scopes have no return value worth annotating). The inner lambda already has `fn (Builder $q)` syntax — add a `/** @param Builder<Appliance> $q */` comment immediately inside `scopeForHousehold` before the lambda.

```php
/**
 * @property \Illuminate\Support\Carbon|null $anchor_date
 * @property \Illuminate\Support\Carbon|null $last_completed_at
 * @property float|null $last_metric_value
 * @property \Illuminate\Support\Carbon|null $next_due_at
 * @property float|null $next_due_at_value
 */
class MaintenanceTask extends Model
```

For the three scope methods (each gets a `/** @param Builder<MaintenanceTask> $query */` PHPDoc block):
- `scopeCalendar(Builder $query)`
- `scopeMetric(Builder $query)`
- `scopeForHousehold(Builder $query, int $householdId)` — also add `/** @param Builder<Appliance> $q */` inside the closure body before the lambda expression

For relationship methods:
- `appliance(): BelongsTo` → `@return BelongsTo<Appliance, MaintenanceTask>`
- `serviceRecords(): HasMany` → `@return HasMany<ServiceRecord, MaintenanceTask>`

#### 5. ServiceRecord model

**File**: `app/Models/ServiceRecord.php`

**Intent**: Add `@use HasFactory<ServiceRecordFactory>` (now available after Phase 1) and `@return` generic on the one relationship method.

**Contract**:
- Before `use HasFactory;`: `/** @use HasFactory<\Database\Factories\ServiceRecordFactory> */`
- `maintenanceTask(): BelongsTo` → `@return BelongsTo<MaintenanceTask, ServiceRecord>`

#### 6. User model

**File**: `app/Models/User.php`

**Intent**: Add `@return` generic to `households()`. The `@use HasFactory<UserFactory>` is already correct on line 20 — do not modify it.

**Contract**: `households(): BelongsToMany` → `@return BelongsToMany<Household, User>`

### Success Criteria:

#### Automated Verification:

- PHPStan reports exactly 1 remaining error (only GenerateMaintenancePlan): `composer phpstan 2>&1 | grep "errors"` shows 1
- Tests still pass: `composer test`

---

## Phase 3: Action PHPDoc Annotation

### Overview

Add a typed `@return` annotation to `GenerateMaintenancePlan::__invoke`. This is the last remaining error (Group D — missing iterable value type).

### Changes Required:

#### 1. GenerateMaintenancePlan return type

**File**: `app/Actions/GenerateMaintenancePlan.php`

**Intent**: Annotate the return type with the exact array shape PHPStan needs to satisfy the `missingType.iterableValue` check.

**Contract**: Add a `@return` PHPDoc block immediately before the method declaration on line 18. The shape mirrors the Prism structured response schema defined in the same method.

```php
/** @return array<int, array{name: string, description: string, interval_value: int|float, interval_unit: string}> */
public function __invoke(string $applianceName, string $applianceModel, string $typeName): array
```

### Success Criteria:

#### Automated Verification:

- PHPStan exits 0: `composer phpstan` outputs `[OK] No errors`
- Tests still pass: `composer test`

---

## Testing Strategy

### Automated:

- `composer phpstan` — primary gate; must exit 0 after Phase 3
- `composer test` — regression gate after Phase 2 and Phase 3 (no behaviour changes expected)

### Manual Testing Steps:

None required — this change adds only PHPDoc annotations and a factory stub. Runtime behaviour is unchanged.

## References

- Research: `context/changes/phpstan-green/research.md`
- Reference model (factory pattern): `database/factories/ApplianceFactory.php`
- Reference model (@use HasFactory): `app/Models/User.php:20`
- PHPStan config: `phpstan.neon` (level 6, Larastan extension included)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Create ServiceRecordFactory

#### Automated

- [x] 1.1 File exists: `ls database/factories/ServiceRecordFactory.php`
- [x] 1.2 PHPStan error count does not increase: `composer phpstan` (baseline check)

### Phase 2: Model PHPDoc Annotations

#### Automated

- [ ] 2.1 PHPStan reports exactly 1 remaining error (GenerateMaintenancePlan only)
- [ ] 2.2 Tests still pass: `composer test`

### Phase 3: Action PHPDoc Annotation

#### Automated

- [ ] 3.1 PHPStan exits 0: `composer phpstan` outputs `[OK] No errors`
- [ ] 3.2 Tests still pass: `composer test`

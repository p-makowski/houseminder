---
date: 2026-06-06T12:00:00+00:00
researcher: p-makowski
git_commit: 4b208c74207c7bb62edcafe889b90ccc1e7eaea5
branch: main
repository: houseminder
topic: "Fix 24 failing PHPStan errors — models, actions, generics, property types"
tags: [research, phpstan, larastan, type-annotations, generics, models, actions]
status: complete
last_updated: 2026-06-06
last_updated_by: p-makowski
---

# Research: Fix Failing PHPStan Errors

**Date**: 2026-06-06  
**Researcher**: p-makowski  
**Git Commit**: `4b208c74207c7bb62edcafe889b90ccc1e7eaea5`  
**Branch**: main  
**Repository**: houseminder

## Research Question

PHPStan is reporting 24 errors across the codebase (`phpstan.neon`, level 6, Larastan v3.10.0). What are they, why do they occur, and what is the correct fix for each?

## Summary

All 24 errors fall into five distinct groups. None affect runtime behaviour — they are type-annotation gaps that PHPStan level 6 with Larastan requires to be explicit. The dominant group (19 of 24) is missing generic type parameters on Eloquent relationship return types and the `HasFactory` trait. Two real-logic errors (`property.notFound`) are downstream of the same generic omission: without generics, PHPStan loses the concrete model type through relationship chains. One property assignment error is caused by Larastan v3.10.0 not inferring model property types from the method-based `casts(): array` form (a Laravel 11 pattern not yet fully resolved in Larastan at this version).

No business logic changes are required.

## Detailed Findings

### Group A — Missing generics on `HasFactory` trait (5 errors)

PHPStan requires `/** @use HasFactory<ConcreteFactory> */` for each model using the trait. `User` already sets this pattern correctly (line 20: `/** @use HasFactory<UserFactory> */`). All other models omit it.

| File | Line | Fix |
|------|------|-----|
| `app/Models/Appliance.php` | 16 | `/** @use HasFactory<\Database\Factories\ApplianceFactory> */` |
| `app/Models/ApplianceType.php` | 16 | `/** @use HasFactory<\Database\Factories\ApplianceTypeFactory> */` |
| `app/Models/Household.php` | 16 | `/** @use HasFactory<\Database\Factories\HouseholdFactory> */` |
| `app/Models/MaintenanceTask.php` | 17 | `/** @use HasFactory<\Database\Factories\MaintenanceTaskFactory> */` |
| `app/Models/ServiceRecord.php` | 15 | No factory exists — must create `database/factories/ServiceRecordFactory.php`, then annotate |

All factories exist in `database/factories/` except `ServiceRecordFactory.php`. A minimal stub is needed for `ServiceRecord`.

### Group B — Missing generics on relationship return types (11 errors)

PHPStan level 6 with Larastan requires relationships to be typed as `BelongsTo<TRelated, TDeclaringModel>`, `HasMany<TRelated, TDeclaringModel>`, or `BelongsToMany<TRelated, TDeclaringModel>`. Without these, the return type degrades to the unparameterised base class, and PHPStan cannot infer which model `first()` / `get()` / magic property access returns.

**app/Models/Appliance.php**
- Line 18: `household(): BelongsTo` → `BelongsTo<Household, Appliance>`
- Line 23: `applianceType(): BelongsTo` → `BelongsTo<ApplianceType, Appliance>`
- Line 28: `maintenanceTasks(): HasMany` → `HasMany<MaintenanceTask, Appliance>`

**app/Models/ApplianceType.php**
- Line 18: `household(): BelongsTo` → `BelongsTo<Household, ApplianceType>`
- Line 23: `appliances(): HasMany` → `HasMany<Appliance, ApplianceType>`

**app/Models/Household.php**
- Line 18: `users(): BelongsToMany` → `BelongsToMany<User, Household>` (2-type form, pivot omitted)
- Line 23: `appliances(): HasMany` → `HasMany<Appliance, Household>`
- Line 28: `applianceTypes(): HasMany` → `HasMany<ApplianceType, Household>`

**app/Models/MaintenanceTask.php**
- Line 37: `appliance(): BelongsTo` → `BelongsTo<Appliance, MaintenanceTask>`
- Line 42: `serviceRecords(): HasMany` → `HasMany<ServiceRecord, MaintenanceTask>`

**app/Models/ServiceRecord.php**
- Line 17: `maintenanceTask(): BelongsTo` → `BelongsTo<MaintenanceTask, ServiceRecord>`

**app/Models/User.php**
- Line 23: `households(): BelongsToMany` → `BelongsToMany<Household, User>`

In PHP, generics are PHPDoc-only. The fix is a `@return` annotation before each method; the native PHP return type hint stays unchanged.

### Group C — Missing generics on query scope `$query` parameters (3 errors)

PHPStan level 6 requires `Builder<TModel>` for all scope `$query` arguments. All three scopes are in `app/Models/MaintenanceTask.php`.

| Line | Scope | Fix |
|------|-------|-----|
| 19 | `scopeCalendar(Builder $query)` | `@param Builder<MaintenanceTask> $query` |
| 26 | `scopeMetric(Builder $query)` | `@param Builder<MaintenanceTask> $query` |
| 32 | `scopeForHousehold(Builder $query, int $householdId)` | `@param Builder<MaintenanceTask> $query` |

Note: the inner lambda on line 34 also receives `Builder $q` (untyped). That does not currently error — the outer query's generic is sufficient at level 6 — but should be annotated as `Builder<Appliance>` to be safe.

### Group D — Missing iterable value type (1 error)

**`app/Actions/GenerateMaintenancePlan.php:18`**

```php
public function __invoke(string $applianceName, string $applianceModel, string $typeName): array
```

PHPStan requires `array` to specify its value type. The return value is `$response->structured['tasks'] ?? []`, where each element is an associative array decoded from the Prism structured response with shape:

```
name: string
description: string
interval_value: int|float  (AI returns a number)
interval_unit: string
```

Fix:
```php
/** @return array<int, array{name: string, description: string, interval_value: int|float, interval_unit: string}> */
public function __invoke(...): array
```

### Group E — Undefined property access in `RecordTaskCompletion` (2 errors)

**`app/Actions/RecordTaskCompletion.php:20`**

```php
$household = $user->households()->first();
abort_if(! $household || $task->appliance->household_id !== $household->id, 403);
```

Two errors:
1. `$household->id` — PHPStan types `$household` as `Model&object{pivot: Pivot}|null` because `User::households()` has no generics. With proper `BelongsToMany<Household, User>` generics, `first()` resolves to `Household|null`, and `$id` is available.
2. `$task->appliance->household_id` — PHPStan types the `appliance` accessor result as `Model|null` because `MaintenanceTask::appliance()` has no generics. With proper `BelongsTo<Appliance, MaintenanceTask>` generics, the type becomes `Appliance|null`, and `household_id` (declared in `#[Fillable]`) is accessible.

**These errors are fully downstream of Group B.** Fixing Group B eliminates them with no code change in `RecordTaskCompletion.php`.

### Group F — Property type mismatch on assignment (1 error)

**`app/Actions/RecordTaskCompletion.php:30`**

```php
$task->last_completed_at = $completedAt;  // $completedAt is Carbon
```

PHPStan reports `Property MaintenanceTask::$last_completed_at (string|null) does not accept Carbon`.

Root cause: `MaintenanceTask` defines casts via the method-based form `protected function casts(): array`. Larastan v3.10.0 does not fully infer Eloquent model property types from the `casts()` method (a Laravel 11 pattern). It falls back to `string|null` for unannoted datetime columns. The `$casts` property form (Laravel 9/10) does support inference; the method form does not yet in this Larastan version.

The `next_due_at` assignment on line 32 does not error because the match expression's default branch (`$task->next_due_at`) introduces `string|null` into the match result type, widening it to `Carbon|string|null` — PHPStan does not flag assigning a union that includes the declared type.

Fix: add explicit `@property` PHPDoc to `MaintenanceTask` for all datetime-cast fields, per Larastan's recommended workaround for this version:

```php
/**
 * @property \Illuminate\Support\Carbon|null $anchor_date
 * @property \Illuminate\Support\Carbon|null $last_completed_at
 * @property \Illuminate\Support\Carbon|null $next_due_at
 */
class MaintenanceTask extends Model
```

This makes the assignment on line 30 valid (`Carbon` satisfies `Carbon|null`).

## Code References

- `app/Actions/GenerateMaintenancePlan.php:18` — return type missing value type
- `app/Actions/RecordTaskCompletion.php:20` — undefined properties, downstream of missing generics
- `app/Actions/RecordTaskCompletion.php:30` — Carbon assigned to string|null typed property
- `app/Models/Appliance.php:16,18,23,28` — HasFactory + 3 relationship generics
- `app/Models/ApplianceType.php:16,18,23` — HasFactory + 2 relationship generics
- `app/Models/Household.php:16,18,23,28` — HasFactory + 3 relationship generics
- `app/Models/MaintenanceTask.php:17,19,26,32,37,42` — HasFactory + 3 scopes + 2 relationship generics
- `app/Models/ServiceRecord.php:15,17` — HasFactory (no factory exists!) + 1 relationship generic
- `app/Models/User.php:23` — BelongsToMany generic on `households()`
- `app/Support/CalendarInterval.php:11` — return type is `Carbon` (relevant to Group F)
- `database/factories/` — all factories except `ServiceRecordFactory.php`

## Architecture Insights

**PHPDoc-only generics**: PHP has no native generics. All Larastan-required type parameters live in PHPDoc `@return`, `@param`, and `@use` annotations. No PHP code changes, only PHPDoc additions.

**`User.php` as the correct template**: `User` already demonstrates the pattern (line 20: `/** @use HasFactory<UserFactory> */`). All other models should mirror it.

**Method-based casts vs property-based casts**: Larastan v3.10.0 infers property types from `$casts = [...]` but not from `protected function casts(): array`. This is a known gap. The workaround is `@property` PHPDoc annotations on affected model classes. Upgrading Larastan may eventually make these redundant.

**No runtime impact**: All 24 errors are annotation errors. The application logic is correct; PHPStan simply cannot infer types without the hints.

**Missing ServiceRecordFactory**: `ServiceRecord` uses `HasFactory` but has no corresponding factory class in `database/factories/`. A minimal factory stub is needed before the `@use` annotation can reference a concrete class. Alternatively, `/** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */` would suppress the error without a factory file, but a real factory is cleaner for test usage.

## Historical Context

- `context/changes/phpstan-green/change.md` — change opened 2026-06-06, status: new
- Lessons learned (`context/foundation/lessons.md`) — no prior PHPStan-related lesson recorded

## Open Questions

None — all 24 errors are fully classified and the fix for each is unambiguous.

## Fix Execution Plan (for /10x-plan input)

### Phase 1 — Create ServiceRecordFactory
Create `database/factories/ServiceRecordFactory.php` (minimal stub). Blocks Phase 2.

### Phase 2 — Add `@use HasFactory<…>` to all models (5 files)
`Appliance`, `ApplianceType`, `Household`, `MaintenanceTask`, `ServiceRecord`.

### Phase 3 — Add `@return` generics to all relationship methods (12 files×methods)
All files in Group B + User. Method signatures unchanged; PHPDoc added.

### Phase 4 — Add `@param Builder<MaintenanceTask>` to query scopes (1 file)
`MaintenanceTask::scopeCalendar`, `scopeMetric`, `scopeForHousehold`.

### Phase 5 — Add `@return` array shape to `GenerateMaintenancePlan::__invoke` (1 file)

### Phase 6 — Add `@property Carbon|null` PHPDoc to `MaintenanceTask` (1 file)
Fixes the `assign.propertyType` error in `RecordTaskCompletion` without touching the action.

### Verification
`composer phpstan` must exit 0 with 0 errors.

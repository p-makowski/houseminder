# Lessons Learned

> Append-only register of recurring rules and patterns. Re-read at start by /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## Appliance type queries must use the two-tier pattern

**Context**: app/Models/Household.php — applianceTypes() relationship
**Problem**: Household.applianceTypes() returns only household-scoped custom types. The 13 seeded system types (household_id = null) are invisible to this relation. Code that calls $household->applianceTypes silently misses all global types.
**Rule**: Always query available types with the two-tier pattern: `ApplianceType::whereNull('household_id')->orWhere('household_id', $householdId)`. Never rely on Household.applianceTypes() alone for a full type list.
**Applies to**: Any feature presenting a type list to the user (S-01 appliance-add, future type management).

## Appliance.model must be validated as required in all write paths

**Context**: app/Models/Appliance.php — model field
**Problem**: model is non-nullable in the DB but has no model-layer guard. Missing it produces a raw DB exception, not a validation error.
**Rule**: All components/controllers that create or update an Appliance must include `'model' => ['required', 'string', 'max:255']` in validation.
**Applies to**: S-01 appliance-add form, S-03 appliance-edit form.

## User input must be validated before reaching AI prompts

**Context**: app/Actions/GenerateMaintenancePlan.php:56 — user-supplied $applianceName, $applianceModel, $typeName are interpolated directly into the Prism user message.
**Problem**: Any value reaching an AI prompt without sanitization is a potential prompt injection vector. Even with structured output mode and authentication reducing the risk, the pattern of passing raw user input to AI is a recurring decision point.
**Rule**: Always validate user-supplied strings at the Livewire/controller boundary before passing them to AI actions — enforce max length, strip control characters, and use Laravel validation rules (max:255, string). The action itself stays clean; sanitization belongs at the entry boundary.
**Applies to**: Any feature that passes user input to an AI prompt — S-01 wizard, future AI features.

## Feature test namespaces must have their own base test case

**Context**: tests/Feature/Dashboard/ — DashboardTestCase, ApplianceTestCase
**Problem**: RecordTaskCompletionTest duplicated the user + household + pivot + actingAs setUp boilerplate from ApplianceTestCase verbatim. A change to household setup (e.g. new pivot field, role enum) would require updating two places.
**Rule**: Every feature test namespace that shares the same fixture setup (user, household, appliance, actingAs) must have its own abstract base TestCase in that namespace. New test classes in the namespace extend the base class and only add fixtures specific to their own tests.
**Applies to**: Any new test namespace under tests/Feature/ — e.g. Dashboard/, and future namespaces like Settings/, Notifications/.

## Action classes must use __invoke() as their single public entry point

**Context**: app/Actions/ — RecordTaskCompletion, GenerateMaintenancePlan
**Problem**: RecordTaskCompletion was initially written with execute() while GenerateMaintenancePlan uses __invoke(). Two different calling conventions in the same namespace force call sites to look different and make future action scaffolding inconsistent.
**Rule**: All single-responsibility action classes in App\Actions must expose one public method named __invoke(). Call sites use (new SomeAction)(...) — never ->execute() or ->handle(). Applies to new actions and any existing actions that are refactored.
**Applies to**: Any new or refactored action class under app/Actions/.

## Action classes that use abort_if are coupled to HTTP context

**Context**: app/Actions/RecordTaskCompletion.php — abort_if used for ownership guard
**Problem**: abort_if throws HttpException (HTTP 403). This works correctly from Livewire components and controllers, but if an action is ever reused from an Artisan command or a queued job, the 403 surfaces as an unhandled HTTP exception rather than a domain-level authorization failure.
**Rule**: Actions intended only for HTTP contexts may use abort_if. Actions that may be reused from non-HTTP contexts (queued jobs, Artisan commands) must throw AuthorizationException or a domain exception instead. Document the HTTP-only assumption at the action class level when using abort_if.
**Applies to**: Any action class under app/Actions/ that performs authorization checks.

## Ownership guards assume single household per user — revisit for multi-household

**Context**: app/Actions/ and Livewire pages — all ownership checks use $user->households()->first()
**Problem**: $user->households()->first() silently picks the first membership. For a user with multiple household memberships, it may resolve the wrong household, potentially granting access to another household's data.
**Rule**: The current app assumes one household per user. All ownership guards use the two-step pattern: `$household = $user->households()->first(); abort_if(!$household || ...)`. This is intentional and consistent. If multi-household support is ever added, every ownership guard in app/Actions/ and Livewire pages must be audited and switched to an exists()-based check scoped to the specific resource's household.
**Applies to**: Any feature that adds multi-household support, and any ownership guard review during that work.

## Guard helper calls when validation and helper contract can diverge

**Context**: resources/views/livewire/pages/appliances/create.blade.php:confirm() / app/Support/CalendarInterval.php
**Problem**: CalendarInterval::calculateNextDueAt() throws InvalidArgumentException on non-calendar units. The wizard's confirm() calls it unconditionally, relying entirely on upstream validation (interval_unit in:days,weeks,months,years) to prevent metric units from reaching the call. If validation is ever relaxed (e.g., hours/km added to the wizard), the helper throws inside the DB transaction — clean rollback, but an opaque internal exception instead of a user-facing validation error.
**Rule**: When a helper has a strict unit/type contract and is called inside a DB::transaction(), add an inline guard at the call site rather than relying solely on upstream validation. Either branch on interval_unit before calling, or extend the helper to handle the broader input set.
**Applies to**: Any call site inside a DB::transaction() that delegates to CalendarInterval::calculateNextDueAt() or similar strict helpers when the set of valid inputs may expand in future features.

## MaintenanceTask: interval_unit determines which next_due field is authoritative

**Context**: app/Models/MaintenanceTask.php — interval_unit, next_due_at, next_due_at_value
**Problem**: Calendar units (days/weeks/months/years) use next_due_at (datetime); metric units (hours/km) use next_due_at_value (float). No DB or model constraint enforces this. Both fields can be set or both null without error.
**Rule**: Any code reading/writing next_due_at or next_due_at_value must branch on interval_unit first. Form validation (S-01) and schedule calculation (S-02) must enforce that exactly one field is populated per task.
**Applies to**: S-01 plan confirmation, S-02 overdue detection, any future task CRUD.

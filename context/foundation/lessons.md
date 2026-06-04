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

## MaintenanceTask: interval_unit determines which next_due field is authoritative

**Context**: app/Models/MaintenanceTask.php — interval_unit, next_due_at, next_due_at_value
**Problem**: Calendar units (days/weeks/months/years) use next_due_at (datetime); metric units (hours/km) use next_due_at_value (float). No DB or model constraint enforces this. Both fields can be set or both null without error.
**Rule**: Any code reading/writing next_due_at or next_due_at_value must branch on interval_unit first. Form validation (S-01) and schedule calculation (S-02) must enforce that exactly one field is populated per task.
**Applies to**: S-01 plan confirmation, S-02 overdue detection, any future task CRUD.

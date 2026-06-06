<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Phase 1 — Calculation Correctness

- **Plan**: context/changes/testing-calculation-correctness/plan.md
- **Scope**: Phase 1 of 5
- **Date**: 2026-06-05
- **Verdict**: APPROVED
- **Findings**: 0 critical  2 warnings  1 observation

## Verdicts

| Dimension | Verdict |
|---|---|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | WARNING |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Findings

### F1 — FQCN exception without use import

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: app/Models/MaintenanceTask.php:27
- **Detail**: `throw new \InvalidArgumentException(...)` used a FQCN without a `use` import, inconsistent with every other class import in the project.
- **Fix**: Add `use InvalidArgumentException;` import and drop the backslash.
- **Decision**: FIXED — method subsequently moved to `App\Support\CalendarInterval` (see F2).

### F2 — First static business method on a model

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Architecture
- **Location**: app/Models/MaintenanceTask.php:21
- **Detail**: Every other model contains only relationships, scopes, and casts. `calculateNextDueAt()` was the first static method encoding business logic on a model; if calendar helpers grow, the model becomes a calculation class in disguise.
- **Fix**: Extract to `App\Support\CalendarInterval` — a dedicated, thin helper class.
- **Decision**: FIXED via Fix — moved to `app/Support/CalendarInterval.php`; both call sites updated; `MaintenanceTask` returned to thin-model shape.

### F3 — Wizard calls helper with no inline guard for metric units

- **Severity**: 👁️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: resources/views/livewire/pages/appliances/create.blade.php:202
- **Detail**: `CalendarInterval::calculateNextDueAt()` was called unconditionally; safe today because validation enforces calendar units, but would throw inside a DB transaction if metric units were ever added to the wizard.
- **Fix**: Added `in_array()` guard; metric units now yield `$nextDueAt = null` instead of an exception.
- **Decision**: FIXED + ACCEPTED-AS-RULE: "Guard helper calls when validation and helper contract can diverge" (appended to context/foundation/lessons.md).

## Post-triage state

After triage the following additional files were modified beyond the original Phase 1 scope:

| File | Change |
|---|---|
| `app/Support/CalendarInterval.php` | NEW — extracted helper class (F2 fix) |
| `app/Models/MaintenanceTask.php` | Removed `calculateNextDueAt()`, Carbon import, InvalidArgumentException import |
| `app/Actions/RecordTaskCompletion.php` | Switched call site from MaintenanceTask:: to CalendarInterval:: |
| `resources/views/livewire/pages/appliances/create.blade.php` | Switched import + call site; added in_array() guard (F3 fix) |
| `context/foundation/lessons.md` | Appended new lesson (F3 rule) |

All 59 tests pass post-triage.

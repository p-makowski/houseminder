<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Appliance Detail Page — Schedule Management

- **Plan**: context/changes/appliance-detail-page/plan.md
- **Mode**: Deep
- **Date**: 2026-06-07
- **Verdict**: REVISE → SOUND (after fixes applied)
- **Findings**: 1 critical | 1 warning | 2 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | WARNING |
| Architectural Fitness | PASS |
| Blind Spots | WARNING |
| Plan Completeness | FAIL |

## Grounding

5/5 paths ✓, 3/3 symbols ✓, brief↔plan ✓

## Findings

### F1 — Progress section missing 403 guard steps for Phases 3 and 4

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: ## Progress → Phase 3 (Manual) and Phase 4 (Manual)
- **Detail**: Phase 3 and Phase 4 each include a 403 guard criterion in their Manual Verification sections with no matching `- [ ] N.M` entry in the Progress block. /10x-implement parses Progress to track completion — missing items won't be tracked.
- **Fix**: Add `- [ ] 3.7 A task from a different appliance cannot be deleted via crafted confirmDelete call (403)` and `- [ ] 4.9 A task from a different appliance cannot be edited (403 on startEdit)` to the respective Progress sections.
- **Decision**: FIXED — 3.7 and 4.9 added to Progress.

### F2 — editIntervalUnit validation cannot enforce category without fetching the task first

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Blind Spots
- **Location**: Phase 4 — saveEdit validation spec
- **Detail**: saveEdit is specced as validate → fetch task → verify ownership. The `editIntervalUnit` rule is `in:[units for task's category]` but without the task the validator can't know which category to enforce. Validating against all 6 units would silently permit the cross-category switch the plan explicitly forbids.
- **Fix A ⭐ Recommended**: Add `public string $editIntervalCategory = ''` populated in `startEdit()` as `'calendar'` or `'metric'`. Validate `editIntervalUnit` against that property.
  - Strength: Standard Livewire form-state pattern; startEdit already populates 6 fields.
  - Tradeoff: One extra property; client-crafted value is safe (server re-validates against actual task).
  - Confidence: HIGH — standard Livewire pattern.
  - Blind spot: Must document that editIntervalCategory is validation-only, not persisted.
- **Fix B**: Reorder saveEdit — fetch task first, then validate.
  - Strength: No extra property; fully authoritative.
  - Tradeoff: Wasted DB fetch on invalid input; non-standard Laravel pattern.
  - Confidence: MEDIUM.
  - Blind spot: None significant.
- **Decision**: FIXED via Fix A — `editIntervalCategory` property added to state properties, startEdit contract, and saveEdit validation spec.

### F3 — DB::transaction wraps a single model save

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Lean Execution
- **Location**: Phase 4 — saveEdit method
- **Detail**: Plan wraps `$task->save()` in `DB::transaction()`. MaintenanceTask has no observers or boot-method event listeners writing to other tables. A single-model save needs no transaction.
- **Fix**: Remove `DB::transaction()` from saveEdit spec.
- **Decision**: ACCEPTED — harmless to keep; adds safety margin.

### F4 — ApplianceShowTest provides smoke-only coverage during phases 1–4

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: Success Criteria — Phases 1–4
- **Detail**: The two existing tests assert only component boot and 403 gate. Won't catch broken computed property, sorting error, or mark-done regression. Real coverage arrives in Phase 5.
- **Fix**: Note in Phase 1 implementation note that the filter is smoke-only.
- **Decision**: FIXED — note added to Phase 1 Implementation Note.

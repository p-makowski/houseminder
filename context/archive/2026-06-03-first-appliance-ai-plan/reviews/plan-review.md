<!-- PLAN-REVIEW-REPORT -->
# Plan Review: First Appliance + AI Plan (S-01)

- **Plan**: context/changes/first-appliance-ai-plan/plan.md
- **Mode**: Deep
- **Date**: 2026-06-04
- **Verdict**: SOUND (after triage fixes)
- **Findings**: 0 critical, 2 warnings, 3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | WARNING |
| Plan Completeness | PASS |

## Grounding

5/5 paths ✓, 3/3 symbols ✓, brief↔plan ✓

## Findings

### F1 — ServiceRecord created for fixed_calendar tasks is semantically wrong

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Blind Spots
- **Location**: Phase 3 — confirm() method
- **Detail**: Plan's confirm() created a ServiceRecord whenever a backdate date was provided, regardless of anchor_type. For fixed_calendar tasks the backdate date is a schedule anchor, not a past completion event.
- **Fix**: Restrict ServiceRecord creation to anchor_type === 'from_last_done' tasks only. Fixed_calendar tasks use the backdate date only to compute next_due_at.
- **Decision**: FIXED — applied to plan lines 29, 267, 510

### F2 — Prism::fake() exception simulation API not confirmed

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Blind Spots
- **Location**: Phase 5 — AI failure path test
- **Detail**: Prism::fake() documented API has no confirmed method to simulate exception throws. If unsupported, the AI failure test cannot be written as planned.
- **Fix A ⭐**: Verify after Phase 1 installs Prism, inspect installed source, then choose approach (Prism::fake() if supported, or mock GenerateMaintenancePlan directly via app()->instance() if not).
- **Decision**: FIXED (Fix A) — decision point note added to Phase 5 AI failure test contract

### F3 — Metric field wired in step 3 but metric tasks are out of S-01 scope

- **Severity**: 🕍 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Lean Execution
- **Location**: Phase 3 — Step 3 Blade template
- **Detail**: backdates.N.metric field is wired but can never be shown (AI constrained to calendar units in S-01).
- **Fix**: Remove metric field from step 3 template and $backdates array for S-01.
- **Decision**: ACCEPTED RISK — user prefers to wire it now for future slice compatibility

### F4 — Backdates lost silently when navigating back from step 3

- **Severity**: 🕍 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: Phase 3 — advanceFromStep2() method
- **Detail**: advanceFromStep2() always re-initialises $backdates with no warning to the user or documentation of the tradeoff.
- **Fix**: Document as intentional tradeoff in the plan's advanceFromStep2() contract.
- **Decision**: FIXED — tradeoff explanation added to advanceFromStep2() contract

### F5 — No null guard on households()->first()

- **Severity**: 🕍 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: Phase 3 — mount() and confirm() methods
- **Detail**: Auth::user()->households()->first() returns null with no guard; null-dereference causes 500.
- **Fix**: Add abort_if(!$household, 403) in mount(); store as $this->householdId; reuse in confirm().
- **Decision**: FIXED — abort_if guard added to mount() contract

---
change_id: testing-calculation-correctness
title: Phase 1 — Calculation correctness tests for next_due_at
status: archived
created: 2026-06-05
updated: 2026-06-06
archived_at: 2026-06-06T06:54:30Z
---

## Notes

Open a change folder for rollout Phase 1 of context/foundation/test-plan.md: "Calculation correctness".
Risks covered: Risk #2 (next_due_at calculated wrongly after wizard confirm or mark-done — dashboard shows wrong due dates, no alarm fires).
Test types planned: unit + integration.
Risk response intent: Risk #2: prove that given anchor_date + interval_unit + interval_value, the exact expected next_due_at is produced for all 4 calendar units (days, weeks, months, years) × both anchor types (from_last_done, fixed_calendar); the assertion must be an exact date match, not "is in the future"; challenge whether wizard confirm() and RecordTaskCompletion share the same calculation or duplicate it independently; identify which code path is exercised by the existing tests and which is not.
After creating the folder, follow the downstream continuation rule.

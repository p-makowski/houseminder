---
change_id: testing-authorization-depth
title: Authorization depth — prove household scope on all Volt components and IDOR on markDone
status: implementing
created: 2026-06-05
updated: 2026-06-05
archived_at: null
---

## Notes

Rollout Phase 2 of context/foundation/test-plan.md: "Authorization depth".
Risks covered: #1 (cross-household data exposure), #3 (IDOR on markDone).
Test types planned: integration (Volt::test with second-household fixtures).

Risk response intent:
- Risk #1: Prove that a request from household B for household A's resource returns 403 — no data leaked, no 500. Challenge: "we tested one entry point, not all Volt components that accept a resource ID." Research must identify every Volt component that accepts an appliance/task route param and verify how mount() vs action-level calls enforce household scope. Anti-pattern: testing only the route-parameter path and missing direct query paths.
- Risk #3: Prove that a Volt markDone call with a foreign task ID returns 403 and creates no ServiceRecord. Challenge: "the action may have an internal guard, but does the Volt component fetch the task through household scope before passing it to the action?" Research must trace how the dashboard Volt component's markDone() retrieves the task. Anti-pattern: testing only the action in isolation and missing the component-level fetch path.

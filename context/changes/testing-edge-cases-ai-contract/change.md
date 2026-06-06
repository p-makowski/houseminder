---
change_id: testing-edge-cases-ai-contract
title: Edge cases + AI contract — dashboard date boundaries, unconfirmed tasks, Prism failure modes
status: preparing
created: 2026-06-06
updated: 2026-06-06
archived_at: null
---

## Notes

Rollout Phase 3 of context/foundation/test-plan.md: "Edge cases + AI contract".
Risks covered: #4 (dashboard date boundaries), #5 (unconfirmed tasks hidden), #6 (AI contract — zero-task and malformed responses).
Test types planned: integration (Volt::test + Prism::fake()).

Risk response intent:
- Risk #4: prove the dashboard date-boundary scopes are exact (< vs <=) for overdue / due-this-week / upcoming; challenge that subDay()/addDays() offsets cover the exact boundary values; avoid using relative offsets in tests instead of pinning to exact boundary timestamps.
- Risk #5: prove unconfirmed tasks (is_confirmed = false) never appear in dashboard output even when overdue; challenge that filtering is applied in the query, not just in the view; avoid happy-path-only tests that never seed an unconfirmed task.
- Risk #6: prove the wizard surfaces a user-facing error (not a silent empty state or exception) when Prism returns zero tasks or a malformed response; challenge that the component handles both failure modes explicitly; avoid asserting current output lifted from the implementation (oracle problem).

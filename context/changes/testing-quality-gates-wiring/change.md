---
change_id: testing-quality-gates-wiring
title: Quality-gates wiring — PHPStan, Pint, and PHPUnit as mandatory CI gates
status: preparing
created: 2026-06-07
updated: 2026-06-10
archived_at: null
---

## Notes

Open a change folder for rollout Phase 4 of context/foundation/test-plan.md: "Quality-gates wiring".
Risks covered: cross-cutting (PHPStan level 6 + Pint + PHPUnit enforcement). Test types planned: CI gates.
Risk response intent: Prove that PHPStan level 6, Pint (laravel preset, strict_types enforced), and PHPUnit test suite are mandatory gates — no code reaches production without passing all three; add post-edit hook guidance so the agent loop enforces these gates at edit time.
After creating the folder, follow the downstream continuation rule.

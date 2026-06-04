1. /10x-new dashboard-tasks-and-mark-done
   Creates context/changes/dashboard-tasks-and-mark-done/change.md. Skip if the folder already exists.

2. /10x-research dashboard-tasks-and-mark-done
   Parallel sub-agents read the codebase — existing models, query patterns, Livewire conventions, how S-01 wired things up. Writes research.md. Non-optional for S-02: the next-due-date advancement logic needs evidence from the real schema before you plan it.

3. External research (as needed)
- Context7 for any library you're unsure about (Livewire polling, Alpine patterns, Prism if used again).
- Exa for best-practice questions the codebase can't answer ("how to structure overdue/due-soon bucketing in Livewire").
  Do this in parallel with or right after /10x-research — they answer different questions.

4. /10x-plan dashboard-tasks-and-mark-done
   Reads research.md + any external findings, produces a phased plan.md. This is the contract everything else executes against.

5. /10x-plan-review dashboard-tasks-and-mark-done (optional but recommended)
   Catches plan gaps before you start building — cheaper than finding them mid-phase.

6. /10x-implement dashboard-tasks-and-mark-done phase 1 (then 2, 3, …)
   One phase at a time. Run the phase's automated verification checks before moving to the next.

After all phases:

7. /10x-impl-review dashboard-tasks-and-mark-done

8. /10x-archive dashboard-tasks-and-mark-done

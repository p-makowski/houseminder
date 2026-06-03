---
project: "House Minder"
version: 1
status: draft
created: 2026-06-01
updated: 2026-06-03
prd_version: 1
main_goal: market-feedback
top_blocker: time
---

# Roadmap: House Minder

> Derived from `context/foundation/prd.md` (v1) + auto-researched codebase baseline.
> Edit-in-place; archive when superseded.
> Slices below are listed in dependency order. The "At a glance" table is the index.

## Vision recap

House Minder is a shared web tool for households tracking maintenance on home appliances. The pain compounds in three ways: service history is scattered across email and drawers, decision paralysis sets in because no one can recall when maintenance last happened, and no existing tool suggests the right maintenance interval for a specific appliance without manual lookup. The product's core bet is that combining AI-generated maintenance task suggestions with manual interval tracking and a simple service log — in one low-friction web app — fills a gap the current tools leave open.

## North star

**S-01: User can add a first appliance, receive AI maintenance suggestions, customise the plan, and confirm it** — the smallest end-to-end flow whose successful delivery proves the core product hypothesis (the claim that AI-grounded suggestions paired with a household-owned schedule in one place is meaningfully better than the current patchwork of email and memory).

> "North star" here means the first end-to-end user-visible slice that, if shipped, proves the product's central bet — placed as early as Prerequisites allow because all downstream work only matters if this works.

## At a glance

| ID   | Change ID                     | Outcome (user can …)                                                                                  | Prerequisites | PRD refs                                          | Status   |
| ---- | ----------------------------- | ----------------------------------------------------------------------------------------------------- | ------------- | ------------------------------------------------- | -------- |
| F-01 | domain-schema-bootstrap       | (foundation) domain models and migrations in place; appliance types seeded; registration extended with household name | —             | FR-001, FR-002, FR-005, FR-010, FR-011, FR-012, FR-013 | done     |
| S-01 | first-appliance-ai-plan       | add an appliance, get AI suggestions, edit intervals and anchor type, backdate services, confirm plan | F-01          | US-01, FR-005, FR-010, FR-011                     | proposed |
| S-02 | dashboard-tasks-and-mark-done | view all tasks grouped as overdue / due soon / upcoming; mark any task done; next due date advances   | S-01          | US-02, FR-012, FR-013                             | proposed |
| S-03 | appliance-crud                | edit appliance details; delete appliance with explicit confirmation (permanent)                       | S-01          | FR-008, FR-009                                    | proposed |

## Streams

Navigation aid — groups items that share a Prerequisites chain. Canonical ordering still lives in the dependency graph below; this table is the proposed reading order across parallel tracks.

| Stream | Theme               | Chain                       | Note                                                                                                   |
| ------ | ------------------- | --------------------------- | ------------------------------------------------------------------------------------------------------ |
| A      | AI Plan & Dashboard | `F-01` → `S-01` → `S-02`   | Core hypothesis chain: foundation unlocks the north star; S-02 adds the recurring value that brings users back. |
| B      | Appliance Lifecycle | `S-03`                      | Branches from S-01; parallel with S-02 once the north star is shipped.                                |

## Baseline

What's already in place in the codebase as of 2026-06-01 (auto-researched + user-confirmed).
Foundations below assume these are present and do NOT re-scaffold them.

- **Frontend:** present — Blade templates + Livewire/Volt components + Tailwind CSS; Vite build (`vite.config.js`)
- **Backend / API:** present — Laravel 13.8; `routes/web.php` + `routes/auth.php` (Volt-based); `app/Http/Controllers/Controller.php`
- **Data:** present — Eloquent ORM + SQLite; `database/migrations/`, `database/seeders/`, `app/Models/User.php`; domain models (Appliance, ApplianceType, MaintenanceTask, ServiceRecord) absent
- **Auth:** present — Laravel session-based auth; `middleware(['auth', 'verified'])` at `routes/web.php:10`; `app/Livewire/Actions/Logout.php`
- **Deploy / infra:** present (partial) — `Dockerfile` (FrankenPHP, PHP 8.4, auto-migrate on startup), `fly.toml` (app: house-minder, region: ams); no `.github/workflows/` yet
- **Observability:** partial — Monolog via Laravel default (`config/logging.php`, stderr channel configured); no Sentry/APM

## Foundations

### F-01: Domain schema bootstrap

- **Outcome:** (foundation) Eloquent models and migrations for Appliance, ApplianceType, MaintenanceTask, and ServiceRecord are in place; ApplianceType table is seeded with common household appliance categories; registration form and users migration extended with `household_name`.
- **Change ID:** `domain-schema-bootstrap`
- **PRD refs:** FR-001, FR-002, FR-005, FR-010, FR-011, FR-012, FR-013
- **Unlocks:** S-01 (the appliance add + AI plan flow needs all four domain models); S-02 (dashboard queries MaintenanceTask and ServiceRecord); S-03 (edit/delete operates on Appliance)
- **Prerequisites:** —
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Schema decisions made here propagate to every downstream slice; a migration mistake is cheap to fix before any user-facing work sits on top of it and expensive after. Sequenced first precisely to catch data-model errors at the lowest cost.
- **Status:** done

## Slices

### S-01: First appliance — AI suggestions + confirmed plan

- **Outcome:** user can add an appliance (name required, model required, type from seeded list or custom per-household, purchase date optional), receive at least one AI-generated maintenance task suggestion, review and edit intervals, choose anchor type (from last done / fixed calendar) per task, optionally backdate recent services, and confirm the maintenance plan — the confirmed plan persists across logout/login.
- **Change ID:** `first-appliance-ai-plan`
- **PRD refs:** US-01, FR-005, FR-010, FR-011
- **Prerequisites:** F-01
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:**
  - Will the Anthropic API reliably return structured task + interval pairs from an appliance name/model/type prompt, within the 10-second NFR? — Owner: team. Block: no (can proceed; if response format is unreliable, prompt engineering resolves it during implementation — but final latency and format are unknown until tested).
- **Risk:** The AI suggestion call is the highest-uncertainty integration and the product's stated differentiator (FR-010: "the product's core differentiator — must be in v1"). Sequenced immediately after F-01 so this assumption is tested at the earliest possible moment. If suggestions are poor or too slow, the product premise needs revision before more slices are built.
- **Status:** proposed

### S-02: Dashboard — tasks by status + mark service done

- **Outcome:** user can see all maintenance tasks across all household appliances grouped as overdue / due soon (≤ 30 days default) / upcoming; user can mark any task done (completion date required, conditional metrics — e.g. motor hours, km — shown based on appliance type); marking done removes the task from overdue/due-soon and advances its next due date by the configured interval.
- **Change ID:** `dashboard-tasks-and-mark-done`
- **PRD refs:** US-02, FR-012, FR-013
- **Prerequisites:** S-01
- **Parallel with:** S-03
- **Blockers:** —
- **Unknowns:**
  - Which appliance types should surface conditional metrics (motor hours, km, etc.)? PRD says "shown conditionally based on appliance type" but does not specify the mapping. — Owner: user. Block: no (can ship with sensible defaults — e.g. motor hours for lawn mowers and generators, km for vehicles — and adjust during implementation).
- **Risk:** The next-due-date advancement logic is the recurring-use-case engine; if the calculation is wrong users lose trust quickly. Sequenced after S-01 so a real confirmed maintenance plan exists to validate the calculation against before this slice is signed off.
- **Status:** proposed

### S-03: Appliance CRUD — edit and delete

- **Outcome:** user can edit an appliance's name, model, type, and purchase date; user can delete an appliance after an explicit confirmation step (deletion is permanent in v1 — the confirmation guards against accidental loss of all maintenance history for that appliance).
- **Change ID:** `appliance-crud`
- **PRD refs:** FR-008, FR-009
- **Prerequisites:** S-01
- **Parallel with:** S-02
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Delete is irreversible in v1 (FR-009 Socrates: "risks losing all maintenance history permanently"). Sequenced after S-01 so the confirmation pattern is built and tested when at least one real appliance with a plan exists, making it easy to verify the guard actually works before it matters.
- **Status:** proposed

## Backlog Handoff

| Roadmap ID | Change ID                     | Suggested issue title                                          | Ready for `/10x-plan` | Notes                                                |
| ---------- | ----------------------------- | -------------------------------------------------------------- | --------------------- | ---------------------------------------------------- |
| F-01       | domain-schema-bootstrap       | Set up domain models and extend registration with household name | yes                   | No prerequisites; start here — run `/10x-plan domain-schema-bootstrap` |
| S-01       | first-appliance-ai-plan       | Add first appliance with AI-generated maintenance plan         | no                    | Needs F-01 first                                     |
| S-02       | dashboard-tasks-and-mark-done | Dashboard: view tasks by status and mark service done          | no                    | Needs S-01; parallel with S-03                       |
| S-03       | appliance-crud                | Edit and delete appliances with confirmation                   | no                    | Needs S-01; parallel with S-02                       |

## Open Roadmap Questions

1. **What distinguishes House Minder from existing tools (e.g., Centriq)?** — Owner: user. Block: no (the product can be built and validated without this answer, but market positioning will be weak until resolved). Lifted verbatim from `prd.md § Open Questions`.

2. **Email delivery infrastructure is not configured** — Owner: user. Block: **yes for production** (dashboard is gated behind `middleware(['auth', 'verified'])`; users cannot reach the dashboard without a delivered verification link). Discovered during F-01 registration testing on 2026-06-02. Local dev fix: configure Mailpit (`MAIL_MAILER=smtp`, `MAIL_PORT=1025`) or set `MAIL_MAILER=log` to write emails to the Laravel log instead of sending. Production fix: provision a transactional email provider (Resend, Mailgun, Postmark) and set `MAIL_*` secrets on Fly.io. See `SERVICES.md` for details. Run `/10x-new email-delivery-setup` when ready to plan this.

## Parked

- **No native mobile app** — Why parked: PRD §Non-Goals; responsive web covers mobile browsers in v1.
- **No cross-household sharing** — Why parked: PRD §Non-Goals; explicit non-goal from the project's outset.
- **No file uploads (receipts, manuals)** — Why parked: PRD §Non-Goals; file storage adds complexity before the AI suggestion loop is proven; deferred to v2.
- **No web search or document analysis for maintenance data** — Why parked: PRD §Non-Goals; LLM training knowledge only in v1; real-time model lookup is v2+.
- **No email or push reminders** — Why parked: PRD §Non-Goals; background scheduler + transactional email service is disproportionate before v1 is validated; deferred to v2.
- **No per-person accounts or household invite/join flow** — Why parked: PRD §Non-Goals; auth complexity deferred to v2.
- **No external platform integrations** — Why parked: PRD §Non-Goals; Google Calendar, Alexa etc. are explicit non-goals.
- **Dashboard "due soon" threshold configuration** — Why parked: default 30 days in v1; user-configurable threshold is a v2 enhancement (PRD FR-013 Socrates note).
- **Sort/filter dashboard by due date** — Why parked: PRD §Success Criteria §Secondary; no must-have FR; deferred to v2.

## Done

(Empty on first generation. `/10x-archive` appends an entry here — and flips the matching item's `Status` to `done` — when a change whose `Change ID` matches a roadmap item is archived.)

- **F-01: (foundation) domain models and migrations in place; appliance types seeded; registration extended with household name** — Archived 2026-06-03 → `context/archive/2026-06-01-domain-schema-bootstrap/`. Lesson: —.

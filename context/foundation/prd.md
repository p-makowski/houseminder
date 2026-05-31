---
project: "House Minder"
version: 1
status: draft
created: 2026-05-28
context_type: greenfield
product_type: web-app
target_scale:
  users: small
  qps: low
  data_volume: small
timeline_budget:
  mvp_weeks: 3
  hard_deadline: "2026-06-18"
  after_hours_only: true
---

## Vision & Problem Statement

Homeowners managing multiple household appliances have no central place to track maintenance schedules or know when service is due. The pain compounds in three ways: information about appliances and their service history is scattered across email, drawers, and phone photos; users face decision paralysis because they can't recall when maintenance last happened; and no existing tool provides appliance-specific intelligence — it can't suggest the correct filter change interval for a specific appliance without the user entering everything manually.

House Minder's core bet is that combining AI-generated maintenance suggestions with manual interval tracking and a simple service history in one low-friction web product is not adequately served by current options. The exact competitive differentiation relative to existing tools was not pinned during shaping — see Open Questions.

## User & Persona

**Primary persona**: A household — a couple or family — managing multiple home appliances together. They want shared visibility of what's in the house, when things were bought, and what's coming due for service. The moment they reach for this product is when something breaks or an unexpected repair bill arrives and they realise they had no system tracking the appliance's age or service history.

Within-household access is in scope (one shared account per household in v1). Cross-household sharing — appliance data visible to other households — is explicitly not in scope.

## Success Criteria

### Primary
A household can:
1. Register with email, password, and household name
2. Add an appliance (name, model, purchase date optional, type)
3. View AI-generated maintenance task suggestions, confirm or edit intervals, choose anchor type (from last done / fixed calendar), add custom tasks
4. Backdate previously performed services
5. Confirm the maintenance plan
6. On subsequent logins, see a dashboard showing tasks grouped as overdue, due soon, or upcoming

The flow working end-to-end for a single appliance = the product works.

### Secondary
User can sort or filter the dashboard by due date across all appliances.

### Guardrails
- The confirmed maintenance plan persists across logout/login cycles.
- Appliance data of one household is never accessible to another household.
- The app is usable on a phone browser (responsive web; no native mobile app required).
- Deleting an appliance requires explicit confirmation — the action is not reversible in v1.

## User Stories

### US-01: Household sets up a new appliance

- **Given** a logged-in household on the appliance list screen
- **When** they add a new appliance with name, model, and type (purchase date optional)
- **Then** the app returns suggested maintenance tasks with recommended intervals; the household reviews, edits, or adds custom tasks, selects the interval anchor (from last done / fixed calendar), optionally backdates recent services, and confirms the maintenance plan

#### Acceptance Criteria
- At least one AI-generated suggestion is returned for any appliance with a recognisable type
- User can add a custom task not in the suggested list
- User can select interval anchor type per task
- User can set a past completion date for any task during setup
- Confirmed plan persists after logout and re-login

### US-02: Household checks what is due

- **Given** a logged-in household with at least one appliance and a confirmed maintenance plan
- **When** they open the dashboard
- **Then** they see tasks grouped by status (overdue, due soon, upcoming) across all household appliances

#### Acceptance Criteria
- Overdue tasks are visually distinct from upcoming tasks
- Marking a task as done removes it from overdue/due-soon and advances the next due date by the task's interval

## Functional Requirements

### Authentication & Households
- FR-001: Household can register with email and password; household name is set at registration (one shared account per household — no per-person accounts in v1). Priority: must-have
  > Socrates: Counter-argument considered: "per-person accounts enable an audit trail and match the household-unit persona." Resolution: deferred — shared credential is simpler for v1; per-person accounts move to v2.
- FR-002: Household can log in. Priority: must-have

### Appliance Management
- FR-005: User can add an appliance with name (required), model (required), purchase date (optional), and appliance type (selected from a pre-seeded list or added as a custom type — custom types are per-household in v1). Priority: must-have
  > Socrates: Counter-argument considered: "unmoderated crowd-sourced types pollute the global list." Resolution: custom types are per-household only in v1; global type sharing moves to v2.
- FR-008: User can edit an appliance's details. Priority: must-have
  > Socrates: No counter-argument. Edit is standard and necessary for correcting mistakes.
- FR-009: User can delete an appliance (requires explicit confirmation step before deletion is permanent). Priority: must-have
  > Socrates: Counter-argument accepted in part: delete without confirmation risks losing all maintenance history permanently. Resolution: confirmation step required; soft-delete or export not required in v1.

### Maintenance Planning
- FR-010: User can view AI-generated maintenance task suggestions based on the appliance's name, model, and type. Priority: must-have
  > Socrates: No counter-argument — AI suggestions are the product's core differentiator and must be in v1.
- FR-011: User can confirm, edit, and add custom maintenance tasks with recurrence intervals; interval anchor is user-selectable: "from last completed date" or "fixed calendar date". Priority: must-have
  > Socrates: Counter-argument accepted: both anchor types are valid and users need both. v2 adds free-text schedule generation and complex recurrence patterns (e.g., "every second Friday of the month").
- FR-012: User can record a service as completed with applicable metrics (date, motor hours, kilometers, etc.); metrics shown conditionally based on appliance type. Priority: must-have
  > Socrates: Counter-argument accepted: motor hours, kilometers, and other metrics are only relevant for specific appliance types. Resolution: metrics are shown conditionally based on the appliance type field.

### Dashboard
- FR-013: User can see a dashboard listing all maintenance tasks grouped by status: overdue, due soon (default threshold: 30 days, configurable later), and upcoming. Priority: must-have
  > Socrates: Counter-argument considered: "'due soon' threshold is undefined." Resolution: default to 30 days; user-configurable threshold is a v2 enhancement.

## Non-Functional Requirements

- The app provides continuous visible feedback during AI suggestion generation; suggestions appear within 10 seconds of appliance submission under normal network conditions.
- An authenticated session can never retrieve appliance or maintenance data belonging to a different household — data isolation is absolute.
- The product is fully usable on the latest two major versions of Chrome, Safari, Firefox, and Edge, on both desktop and mobile screen widths ≥ 375 px, without horizontal scrolling or clipped content.
- The interface presents a consistent, modern visual style with no unstyled components or broken layouts at any supported screen size.

## Business Logic

House Minder determines the maintenance schedule a household appliance requires by reasoning from the appliance's name, model, and type, so the household never needs to look up service intervals manually.

The household provides the appliance's name, model, and type when adding it; purchase date, if provided, can inform age-based interval suggestions. When the household submits a new appliance, the app returns a list of suggested maintenance tasks (e.g., "filter change — every 12 months") drawn from knowledge of that appliance category and model. The household encounters this immediately after the add form — they review the list, edit intervals, choose an anchor type per task, add any missing tasks, and confirm. From that point forward, the schedule is theirs to own; the suggestion phase is complete after the first appliance addition.

## Access Control

One shared email + password account per household (no per-person accounts in v1). All household members use the same credentials and have equal access — anyone with the credentials can add, edit, and delete appliances.

Registration creates the household: the sign-up form collects email, password, and household name. No invitation or join mechanism in v1.

Unauthenticated users cannot access any appliance data.

## Non-Goals

- **No native mobile app** — web only; responsive design covers mobile browsers. Rationale: reduces v1 scope; responsive web is the stated v1 constraint.
- **No cross-household sharing** — each household's appliance and maintenance data is private and isolated. Rationale: cross-household sharing was an explicit non-goal from the start.
- **No file uploads (receipts, manuals)** — text data only in v1. Rationale: file storage infrastructure adds complexity before the AI + reminder loop is proven; deferred to v2.
- **No web search or document analysis for maintenance data** — AI suggestions use training knowledge only in v1; real-time model lookup and reading uploaded documents are both v2+. Rationale: both require non-trivial integrations before v1 value is established.
- **No email or push reminders** — dashboard only; users must open the app to see what's due. Rationale: async outbound notification requires background infrastructure disproportionate before v1 is validated.
- **No per-person accounts or household invite/join flow** — one shared credential per household in v1. Rationale: per-person accounts and invite flows add auth complexity; deferred to v2.
- **No external platform integrations** — no Google Calendar, Alexa, or similar. Rationale: integrations were an explicit non-goal from the project's outset.

## Open Questions

1. **What distinguishes House Minder from existing tools (Centriq and similar apps)?** The competitive insight was not pinned during shaping — the user noted they hadn't compared closely to alternatives. Owner: user. Block: no (the product can be built without this answer, but positioning will be weak until resolved).

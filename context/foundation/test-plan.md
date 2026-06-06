# Test Plan

> Phased test rollout for this project. Strategy is frozen at the top
> (§1–§5); cookbook patterns at the bottom (§6) fill in as phases ship.
> Read before writing any new test.
>
> Refresh: re-run `/10x-test-plan --refresh` when stale (see §8).
>
> Last updated: 2026-06-06 (Phase 2 complete + archived; Phase 3 change opening next)

---

## 1. Strategy

Tests follow three non-negotiable principles for this project:

1. **Cost × signal.** The cheapest test that gives a real signal for the
   risk wins. Do not promote to e2e because e2e "feels safer." Do not put a
   vision model on top of a deterministic visual diff that already catches
   the regression.
2. **User concerns are first-class evidence.** Risks anchored in "<the
   team is worried about X, and the failure would surface somewhere in
   <area>>" carry the same weight as PRD lines or hot-spot data.
3. **Risks are scenarios, not code locations.** This plan documents *what
   could fail* and *why we believe it's likely* — drawn from documents,
   interview, and codebase *signal* (churn, structure, test base). It does
   NOT claim to know which line owns the failure. That knowledge is
   produced by `/10x-research` during each rollout phase. If the plan and
   research disagree about where the failure lives, research is the
   ground truth.

Hot-spot scope used for likelihood weighting: `app/`, `resources/`, `database/`, `routes/` — excluding `vendor/`, `node_modules/`, `public/build/`.

---

## 2. Risk Map

The top failure scenarios this project must protect against, ordered by
risk = impact × likelihood. Risks are failure scenarios in user / business
terms, not test names. The Source column cites the *evidence that surfaced
this risk* — never a specific file as "where the failure lives" (that is
research's job, see §1 principle #3).

| # | Risk (failure scenario) | Impact | Likelihood | Source (evidence — not anchor) |
|---|---|---|---|---|
| 1 | One household's appliance or task data becomes readable or modifiable by another household — silent, no error surfaced to either party | High | High | PRD §Access Control ("never accessible to another household"); AGENTS.md ("cross-household data access is a critical bug"); interview Q1 |
| 2 | `next_due_at` is calculated wrongly after wizard confirm or mark-done — dashboard shows wrong due dates, no overdue alarm fires when it should | High | High | Roadmap S-02 risk ("if the calculation is wrong, users lose trust quickly"); interview Q2 (burned before); interview Q3 (low-confidence area); hot-spot dir `app/Models` (16 commits/30d) |
| 3 | Livewire `markDone` call with a foreign task ID succeeds — a user marks another household's task done and a `ServiceRecord` is created silently | High | Medium | Security/IDOR lens; archive `dashboard-tasks-and-mark-done/plan.md` ("double-layer ownership guard"); PRD §Access Control |
| 4 | Dashboard bucket misclassification — a task due exactly today appears in "upcoming" instead of "overdue"; 7-day boundary off-by-one sends task to wrong section | Medium | Medium | Roadmap S-02 ("next-due-date logic is the recurring-use-case engine"); archive `dashboard-tasks-and-mark-done/plan.md` (7-day threshold hardcoded) |
| 5 | Unconfirmed wizard draft tasks (from abandoned sessions) leak into the dashboard — users see phantom overdue tasks they never confirmed | Medium | Medium | Archive `dashboard-tasks-and-mark-done/plan.md` ("wizard sessions that stall before step 4 leave unconfirmed tasks in the DB that must not surface"); hot-spot dir `app/Models` (16 commits/30d) |
| 6 | AI response is valid JSON but returns zero tasks or tasks with missing fields — wizard accepts the empty list silently, user confirms a plan with no maintenance tasks | Medium | Medium | PRD FR-010 ("core differentiator — must be in v1"); roadmap S-01 unknowns ("API reliability unknown until tested") |

### Risk Response Guidance

| Risk | What would prove protection | Must challenge | Context `/10x-research` must ground | Likely cheapest layer | Anti-pattern to avoid |
|---|---|---|---|---|---|
| #1 | A request from household B for household A's resource returns 403 — no data leaked, no 500 | "We tested one entry point — not all Volt components that accept a resource ID" | Which Volt components accept `appliance` / `task` route params; how `mount()` vs action-level calls enforce household scope | Integration (Volt::test with second-household fixture) | Testing only the route-parameter path; missing direct query paths |
| #2 | Given anchor + interval_unit + interval_value → exact expected `next_due_at` for all 4 calendar units × both anchor types | "Happy-path test used `today()` as anchor — does a past anchor date produce the right future date?" | Whether wizard `confirm()` and `RecordTaskCompletion` share the calculation or duplicate it; which branch handles each anchor type | Unit (pure function if extractable) or integration | Asserting "is in the future" instead of the exact expected date |
| #3 | Volt `markDone` call with a foreign task ID throws `ModelNotFoundException` (component's `forHousehold()->findOrFail()` scope catches it before the action is reached) and creates no `ServiceRecord`; if action called directly with a foreign task, it aborts 403 | "The action has an internal guard — but a test asserting `->assertForbidden()` on the Volt call would pass even if the scope guard broke, since the action guard still fires 403; the test must assert the exception path, not the status code" | How the dashboard Volt component's `markDone()` retrieves the task — confirmed: household-scoped `findOrFail`; `ModelNotFoundException` bubbles up in `Volt::test()->call()` rather than returning HTTP 404 | Integration (Volt::test with cross-household task ID + `expectException(ModelNotFoundException)` + `assertDatabaseMissing`) | Testing only the action in isolation; asserting `->assertForbidden()` instead of the exception path; silent try/catch without `$this->fail()` if no exception is thrown |
| #4 | Task due at exactly `now()` → dueThisWeek (NOT overdue); task due at `now()->subSecond()` → overdue; task due at exactly `now()->addDays(7)` → dueThisWeek (NOT upcoming); task due at `now()->addDays(7)->addSecond()` → upcoming | "Happy-path tests use `subDay()` and `addDays(8)` — the exact boundary values are untested; `overdue` uses strict `<`, `dueThisWeek` uses inclusive `whereBetween`, `upcoming` uses strict `>`" | Whether `<` vs `<=` is used for the date comparisons in the relevant scopes or queries — confirmed: overdue is strict `<`, dueThisWeek is inclusive `whereBetween`, upcoming is strict `>` | Integration (Volt::test with `Carbon::setTestNow` at exact boundary timestamps) | Using relative offsets (`subDay()`, `addDays(8)`) in tests instead of pinning to the exact boundary; assuming "due today = overdue" when the actual operator is strict `<` |
| #5 | Unconfirmed tasks from an aborted wizard session do not appear on the dashboard | "The scope enforces `is_confirmed=true`, but does every dashboard query path go through it?" | All query paths in the dashboard Volt component that can surface tasks to the view | Integration (create appliance + abandon wizard before step 4; assert tasks absent from dashboard response) | Assuming the scope is applied automatically to all paths without verifying each one |
| #6 | When Prism returns an empty task array or a response missing required fields, the wizard surfaces an actionable error — not a confirmable empty list | "Prism::fake() always returns well-formed tasks — we never test empty or structurally invalid but non-exceptional responses" | How `GenerateMaintenancePlan` validates the parsed response; whether an empty array triggers the same error state as a `PrismException` | Integration (Prism::fake() with empty-array and missing-field fixtures) | Testing only the `PrismException` path; never testing invalid-but-non-exceptional responses |

---

## 3. Phased Rollout

Each row is a discrete rollout phase that will open its own change folder
via `/10x-new`. Status moves left-to-right through the values below; the
orchestrator updates Status as artifacts appear on disk.

| # | Phase name | Goal | Risks covered | Test types | Status | Change folder |
|---|---|---|---|---|---|---|
| 1 | Calculation correctness | Prove `next_due_at` is exact for all interval units and anchor types in both the wizard confirm path and mark-done path | #2 | unit + integration | complete | context/changes/testing-calculation-correctness |
| 2 | Authorization depth | Prove all Volt components enforce household scope; IDOR on markDone returns 403 and creates no ServiceRecord | #1, #3 | integration | complete | context/archive/2026-06-05-testing-authorization-depth |
| 3 | Edge cases + AI contract | Prove dashboard date boundaries are exact; unconfirmed tasks stay hidden; AI zero-task and malformed responses surface an error | #4, #5, #6 | integration | change opened | context/changes/testing-edge-cases-ai-contract |
| 4 | Quality-gates wiring | Lock PHPStan level 6 + Pint + PHPUnit as mandatory CI gates; add post-edit hook guidance | cross-cutting | CI gates | not started | — |

**Status vocabulary:**

| Value | Meaning |
|---|---|
| `not started` | No change folder for this rollout phase yet. |
| `change opened` | `context/changes/<id>/` exists with `change.md`; research not done. |
| `researched` | `research.md` exists in the change folder. |
| `planned` | `plan.md` exists with a `## Progress` section. |
| `implementing` | Progress section has at least one `[x]` and at least one `[ ]`. |
| `complete` | Progress section is fully `[x]`. |

---

## 4. Stack

The classic test base for this project. AI-native tools (if any) carry a
`checked:` date so future readers can see which lines need re-verification.

| Layer | Tool | Version | Notes |
|---|---|---|---|
| unit + integration | PHPUnit | ^12.5.12 | Configured in `phpunit.xml`; in-memory SQLite; run via `composer test` |
| AI mocking | Prism::fake() | (bundled with prism-php/prism) | Fakes Prism API responses in test scope; required for all wizard tests |
| HTTP client mocking | Laravel `Http::fake()` | (bundled with Laravel 13.8) | For any raw HTTP calls outside Prism |
| Livewire component testing | `Livewire\Volt\Volt::test()` | ^3.6.4 | All Volt full-page component tests use this; see `tests/Feature/Appliances/ApplianceTestCase.php` |
| static analysis | PHPStan + Larastan | level 6 | `./vendor/bin/phpstan analyse`; enforced pre-push |
| code style | Pint | (bundled with Laravel) | `./vendor/bin/pint`; laravel preset, strict_types enforced |
| e2e | none yet — see §3 Phase 4 | — | Browser-level flows not wired; critical paths are covered by Livewire integration tests |

**Stack grounding tools (current session):**
- Docs: Context7 — available; not queried (Laravel 13 / PHPUnit 12 stack is stable and well-known; version-specific details sourced from local `composer.json` and `phpunit.xml`); checked: 2026-06-05
- Search: Exa.ai — available; not queried (no disputed tool support questions arose); checked: 2026-06-05
- Runtime/browser: none in session — Playwright MCP not available; Livewire integration tests cover the critical component interactions without a browser layer
- Provider/platform: none in session — no GitHub, Fly.io, or database MCPs detected

---

## 5. Quality Gates

The full set of gates that must pass before a change reaches production.
"Required after §3 Phase N" means the gate is enforced once that rollout
phase lands; before that, the gate is `planned`.

| Gate | Where | Required? | Catches |
|---|---|---|---|
| lint + typecheck (Pint + PHPStan level 6) | local + CI | required | syntactic drift, type errors, PHPStan violations |
| unit + integration (PHPUnit) | local + CI | required after §3 Phase 1 | logic regressions, isolation failures |
| post-edit hook (run PHPStan + Pint + relevant test suite) | local (agent loop) | recommended after §3 Phase 4 | regressions at edit time before push |
| pre-prod smoke | between merge + prod (Fly.io deploy) | optional | environment-specific failures (email delivery, Anthropic API key presence) |

No e2e browser gate is planned until traffic or incident evidence justifies the cost; Livewire integration tests give real-signal coverage of critical flows at a fraction of the cost.

---

## 6. Cookbook Patterns

How to add new tests in this project. Each sub-section is filled in once
the relevant rollout phase ships; before that, the sub-section reads
"TBD — see §3 Phase N."

### 6.1 Adding a unit test for business logic

**When to use:** The logic is a pure function (no DB, no Livewire, no auth) — i.e., you can call it with typed inputs and assert the return value.

**Reference test:** `tests/Unit/Support/CalendarIntervalTest.php`

**Pattern:**
```php
namespace Tests\Unit\Support;

use App\Support\CalendarInterval;          // the class under test
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;            // no Laravel bootstrap needed

class CalendarIntervalTest extends TestCase
{
    private Carbon $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = Carbon::parse('2024-01-15');  // fixed date, not Carbon::now()
    }

    public function test_calculates_next_due_at_for_months(): void
    {
        $result = CalendarInterval::calculateNextDueAt($this->anchor, 'months', 6);
        $this->assertSame('2024-07-15', $result->toDateString());
    }
}
```

**Key rules:**
- Extend `PHPUnit\Framework\TestCase` directly — not `Tests\TestCase`. Unit tests must not boot Laravel.
- Import `Illuminate\Support\Carbon`, **not** `Carbon\Carbon`. The production helpers use Illuminate's subclass; type hints must match.
- Use a fixed anchor date (e.g., `Carbon::parse('2024-01-15')`). Never use `Carbon::today()` or `now()` — those make the expected value impossible to hardcode.
- Assert the exact output string with `assertSame`, not "is in the future" or `isFuture()`.
- Place the file in `tests/Unit/<Namespace>/` mirroring `app/<Namespace>/`.

**Run command:** `composer test --filter CalendarIntervalTest`

---

**When to use Volt integration tests instead:** The logic lives inside a Livewire Volt component's action (e.g., `confirm()`, `markDone()`). In that case use the Volt test pattern below.

**Reference test:** `tests/Feature/Appliances/WizardCalculationTest.php`

**Pattern:**
```php
namespace Tests\Feature\Appliances;

use App\Models\ApplianceType;
use App\Models\MaintenanceTask;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

class WizardCalculationTest extends ApplianceTestCase
{
    public function test_confirm_next_due_at_for_months_with_from_last_done_anchor(): void
    {
        $task = $this->confirmedTask(
            ['interval_unit' => 'months', 'interval_value' => 6, 'anchor_type' => 'from_last_done'],
            ['skip' => false, 'date' => '2024-01-15', 'metric' => null, 'notes' => ''],
        );

        $this->assertSame('2024-07-15', $task->next_due_at->toDateString());
        $this->assertSame('2024-01-15', $task->last_completed_at->toDateString());
        $this->assertNull($task->anchor_date);
    }

    private function confirmedTask(array $taskFields, array $backdate = []): MaintenanceTask
    {
        $type = ApplianceType::factory()->create(['name' => 'Test Type', 'household_id' => null]);
        $taskFields = array_merge(['name' => 'Test Task', 'description' => null], $taskFields);
        $backdate   = $backdate ?: ['skip' => false, 'date' => '', 'metric' => null, 'notes' => ''];

        Volt::test('pages.appliances.create')
            ->set('name', 'Test Appliance')
            ->set('model', 'Model X')
            ->set('selectedTypeId', $type->id)
            ->set('tasks', [$taskFields])
            ->set('backdates', [$backdate])
            ->call('confirm');

        return MaintenanceTask::first();
    }
}
```

**Key rules:**
- Extend `ApplianceTestCase` (in `tests/Feature/Appliances/`). Its `setUp()` calls `$this->freezeTime()` before fixture creation so `Carbon::today()` is pinned for the whole test.
- Inject component state with `->set()` rather than simulating the full wizard UI — this bypasses the AI step and avoids needing `Prism::fake()`.
- Backdate format: `['skip' => false, 'date' => 'YYYY-MM-DD', 'metric' => null, 'notes' => '']`. For no-backdate: `['skip' => false, 'date' => '', 'metric' => null, 'notes' => '']`.
- Assert exact date strings. For `fixed_calendar` anchor type also assert `anchor_date`; for `from_last_done` also assert `last_completed_at`.
- Fetch the created model via `MaintenanceTask::first()` after calling `confirm()` — the component does not return the model.

**Run command:** `composer test --filter WizardCalculationTest`

### 6.2 Adding an integration test for a Livewire Volt component

**When to use:** Testing any Volt page component's rendering and ownership guard (happy path + forbidden path).

**Reference test:** `tests/Feature/Appliances/ApplianceShowTest.php`

**Pattern:**

```php
// Happy path — owner sees the component
$appliance = Appliance::factory()->create(['household_id' => $this->household->id]);

Volt::test('pages.appliances.show', ['appliance' => $appliance])
    ->assertOk()
    ->assertSee($appliance->name);

// Forbidden path — foreign appliance is rejected by abort_if in mount()
$otherHousehold = Household::factory()->create();
$foreignAppliance = Appliance::factory()->create(['household_id' => $otherHousehold->id]);
// No pivot attachment — $this->user is NOT a member of $otherHousehold

Volt::test('pages.appliances.show', ['appliance' => $foreignAppliance])
    ->assertForbidden();
```

**Key rules:**
- Extend the relevant base `TestCase` for the namespace (e.g. `ApplianceTestCase`); never duplicate `setUp()` boilerplate.
- Pass the model via the binding array (second argument): key = route param name, value = model instance.
- Use `->assertOk()` + `->assertSee()` for the happy path.
- Use `->assertForbidden()` for the 403 path when the guard is `abort_if` in `mount()`.

**Run command:** `php artisan test tests/Feature/Appliances/`

### 6.3 Adding a test for a new household-scoped Volt component or action

**When to use:** Testing household isolation — proving a resource from household B cannot be accessed by a user who belongs only to household A.

**Reference tests:** `ApplianceShowTest::test_viewing_appliance_from_another_household_returns_403` and `DashboardPageTest::test_mark_done_rejects_foreign_household_task`

**Second-household fixture (shared by both styles):**

```php
$otherHousehold = Household::factory()->create();
$foreignAppliance = Appliance::factory()->create(['household_id' => $otherHousehold->id]);
// No pivot attachment — the test user is NOT a member of $otherHousehold
```

**Style A — `abort_if` in `mount()` (implicit binding, returns HTTP 403):**

```php
Volt::test('pages.appliances.show', ['appliance' => $foreignAppliance])
    ->assertForbidden();
```

**Style B — `forHousehold()->findOrFail()` in an action (scoped query, throws `ModelNotFoundException`):**

```php
use Illuminate\Database\Eloquent\ModelNotFoundException;

try {
    Volt::test('pages.dashboard')->call('markDone', $foreignTask->id);
    $this->fail('ModelNotFoundException not thrown — forHousehold() scope guard may have been removed');
} catch (ModelNotFoundException $e) {
    // correct: scoped findOrFail() blocked the foreign resource
}

$this->assertDatabaseMissing('service_records', ['maintenance_task_id' => $foreignTask->id]);
```

**Key rules:**
- Do NOT use `->assertForbidden()` for Style B actions. `Volt::test()->call()` does not run through the HTTP kernel, so Laravel's exception-to-response conversion does not fire — the `ModelNotFoundException` propagates as a raw PHP exception and must be caught in the test.
- Always add `$this->fail()` immediately after the `Volt::test()->call()` line so the test fails explicitly when the exception is NOT thrown.
- Pair the try/catch with `assertDatabaseMissing` as defense-in-depth to confirm the side effect was also blocked.

**Run command:** `php artisan test tests/Feature/`

### 6.4 Adding a test for an AI-integrated flow (Prism-backed)

TBD — see §3 Phase 3 (edge cases + AI contract — will document how to use `Prism::fake()` with empty/malformed response fixtures to guard against AI contract drift).

### 6.5 Wiring a new CI gate

TBD — see §3 Phase 4 (quality-gates wiring — will document the exact CI step configuration for PHPStan + Pint + PHPUnit and the post-edit hook command).

---

## 7. What We Deliberately Don't Test

Exclusions agreed during the rollout (Phase 2 interview, Q5). Future
contributors should respect these unless the underlying assumption changes.

- **Artisan commands and database seeders** — low blast radius; only trusted developers run these; testing them adds noise without catching user-visible failures. Re-evaluate if seeders are ever run in a production context or if an Artisan command gains a user-visible side effect. (Source: interview Q5.)
- **Breeze-generated auth scaffolding** — password reset, email confirmation, and password update flows are generated code, not authored code; failures would be immediately visible to every user and surfaced by manual smoke. Re-evaluate if the auth flows are customized beyond the current register / household-name extension. (Source: implied from existing `tests/Feature/Auth/` coverage of happy paths only.)
- **UI snapshots** — Blade/Livewire component HTML snapshots break on every Tailwind class change and catch nothing meaningful that the integration tests do not already catch. Re-evaluate if a design system with strict visual contracts is introduced. (Source: standard exclusion for utility-CSS stacks.)

---

## 8. Freshness Ledger

- Strategy (§1–§5) last reviewed: 2026-06-05
- Stack versions last verified: 2026-06-05
- AI-native tool references last verified: 2026-06-05

Refresh (`/10x-test-plan --refresh`) when:

- a new top-3 risk surfaces from the roadmap or archive,
- a recommended tool's `checked:` date is older than three months,
- the project's tech stack changes (new framework, new test runner, new AI SDK),
- §7 negative-space no longer matches what the team believes.

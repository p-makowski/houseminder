---
change_id: first-appliance-ai-plan
type: external-research + internal-codebase-audit
created: 2026-06-03
last_updated: 2026-06-03
last_updated_by: claude-sonnet-4-6
last_updated_note: "Added internal codebase compatibility audit — run against commit 2875b38"
git_commit: 2875b38e246bb8e2a34c2f3814e98be805732860
branch: main
repository: houseminder
status: complete
sources: exa.ai web search, Context7 /prism-php/prism, live codebase (parallel sub-agents)
---

# Research: Library Options and Codebase Compatibility for S-01

> Covers: (1) external library selection for AI integration, structured output, multi-step wizard, loading UX;
> (2) internal codebase compatibility audit verifying the external recommendations against the live codebase.

---

## Part A — External Research: Library Options

> Original research via exa.ai web search, 2026-06-03.

### 1. AI / Anthropic Integration

| Package | Composer | Stars | Notes |
|---|---|---|---|
| **`prism-php/prism`** | `prism-php/prism` | ~150 | Fluent Laravel-native wrapper; supports Anthropic, OpenAI, Gemini, Ollama; native `Prism::structured()` with schema validation; Claude Sonnet 4.5+ gets constrained-decoding structured output. Active (v0.100, latest 2026). **Recommended.** |
| `mozex/anthropic-laravel` | `mozex/anthropic-laravel` | 70 | Full Anthropic API surface (batches, extended thinking, token counting); Facade + testing fakes; same-day Anthropic feature support; lower-level than Prism. Best if Anthropic-only depth is needed. |
| `anthropic-ai/sdk` (official) | `anthropic-ai/sdk` | 154 | Official PHP SDK from Anthropic; framework-agnostic (no Laravel DI/Facades); PHP 8.1+; v0.25.0. Use if zero wrapper overhead is preferred. |

**Decision input:** `prism-php/prism` handles structured output with schema validation out of the box, supports provider swapping (useful for local dev with Ollama), and integrates naturally into Volt components via the service container. It is the only net-new Composer dependency needed for S-01.

### 2. Structured AI Response Parsing

| Package | Notes |
|---|---|
| **`Prism::structured()` (built into Prism)** | Define an `ObjectSchema`, call `->asStructured()`; Anthropic returns constrained JSON via native structured output (Claude Sonnet 4.5+) or tool calling fallback for older models. Zero extra dependency if Prism is already chosen. **Use first.** |
| `cognesy/instructor-php` | Define a plain PHP class (e.g. `MaintenanceSuggestion`); Instructor extracts it with auto-retry on bad responses and Laravel Facade support. Heavier dependency (~monorepo). Use as fallback if Prism structured output proves unreliable on edge-case prompts. |
| `gazu1986/laravel-ai-validator` | Laravel validation rules for AI output with auto-retry. **Incompatible — requires Laravel 12; project is on Laravel 13.8.** Skip. |

### 3. Multi-Step Wizard

S-01 flow: add appliance → trigger AI → review/edit suggestions → (optional backdate) → confirm plan.

| Package | Notes |
|---|---|
| **Native Livewire Volt** (no package) | `state()` + `rules()` in a single Volt component with a `$step` integer driving conditional `@if` sections. Sufficient for a 4–5 step flow; zero added dependency. **Recommended.** |
| `rinodrummer/livewire-wizard-form` | `IsWizard` + `IsStep` traits; Livewire Events for navigation; pre-release (v0.2.0); PHP 8.2+, Laravel ≥10, Livewire 3. Adds structure for longer wizards, unnecessary complexity here. |
| `invelity/laravel-headless-wizard` | Headless, supports Livewire; PHP 8.4+, Laravel 11+; interactive CLI generator; 1 star, very new. Skip for production use. |
| `Ympact/laravel-livewire-wizard` | Basic next/prev, validation; Livewire 3. Low adoption. |

**Decision input:** The S-01 wizard has a well-defined 4-step flow. Volt's built-in `state()` + `rules()` + a `$step` integer handles it cleanly without adding a dependency surface. A package only adds value when step count or branching logic scales beyond what a `match($this->step)` block can manage.

### 4. Loading UX During AI Call

No package needed — Livewire ships everything required:

| Mechanism | When to use |
|---|---|
| **`wire:stream`** | Stream Claude's response token-by-token as it arrives (progressive reveal). Pairs with Prism streaming or the official SDK's streaming callback. Documented for AI chatbot pattern in Livewire 4 docs. |
| **`wire:loading` / `data-loading`** | Show a spinner while waiting for a synchronous AI call. Livewire 4 recommends the `data-loading` attribute approach (no `wire:target` boilerplate needed). |

For S-01, a synchronous call + `wire:loading` spinner is the simpler default. Streaming is an enhancement worth adding if the 10-second NFR is tight and partial results are valuable to display.

### Recommended Stack for S-01

```
prism-php/prism          → Anthropic call + structured output schema
Livewire Volt (native)   → multi-step wizard ($step integer)
wire:loading             → AI call loading UX (streaming optional enhancement)
```

Only one net-new Composer dependency: `prism-php/prism`.

### 5. Prism PHP — Context7 API Reference (Anthropic + Structured Output)

> Fetched 2026-06-03 via Context7 `/prism-php/prism` (830 snippets, benchmark 81.2).

#### Installation

```bash
composer require prism-php/prism
php artisan vendor:publish --tag=prism-config
```

#### `.env`

```env
ANTHROPIC_API_KEY=sk-ant-...
```

#### `config/prism.php` (Anthropic block)

```php
'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY', ''),
    'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
    'default_thinking_budget' => env('ANTHROPIC_DEFAULT_THINKING_BUDGET', 1024),
    'anthropic_beta' => env('ANTHROPIC_BETA', null),
],
```

#### Schema for maintenance task suggestions (S-01 shape)

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\{ObjectSchema, ArraySchema, StringSchema, IntegerSchema};

$schema = new ObjectSchema(
    name: 'maintenance_plan',
    description: 'AI-generated maintenance tasks for a household appliance',
    properties: [
        new ArraySchema(
            name: 'tasks',
            description: 'List of suggested maintenance tasks',
            items: new ObjectSchema(
                name: 'task',
                description: 'A single maintenance task',
                properties: [
                    new StringSchema('name', 'Task name, e.g. "Clean filter"'),
                    new StringSchema('description', 'What to do and why'),
                    new IntegerSchema('interval_value', 'Numeric interval, e.g. 6'),
                    new StringSchema('interval_unit', 'Unit: days, weeks, months, or years'),
                ],
                requiredFields: ['name', 'interval_value', 'interval_unit']
            )
        ),
    ],
    requiredFields: ['tasks']
);

$response = Prism::structured()
    ->withSchema($schema)
    ->using(Provider::Anthropic, 'claude-sonnet-4-5')
    ->withPrompt("Suggest maintenance tasks for: {$appliance->name}, model {$appliance->model}, type {$applianceType->name}.")
    ->asStructured();

$tasks = $response->structured['tasks'];
// [['name' => 'Clean filter', 'interval_value' => 6, 'interval_unit' => 'months'], ...]
```

#### Native vs tool-calling structured output

| Mode | When | How |
| --- | --- | --- |
| **Native** (default) | Claude Sonnet 4.5+ | constrained decoding — guaranteed valid JSON, no extra option needed |
| **Tool calling fallback** | older models or non-English content with quotes | add `->withProviderOptions(['use_tool_calling' => true])` |

#### Response object

```php
$response->structured   // PHP array — the parsed output
$response->text         // raw JSON string
$response->usage        // promptTokens, completionTokens
$response->finishReason // stop / tool_call / etc.
```

#### Generation parameters (timeout, retry, temperature)

```php
Prism::structured()
    ->withSchema($schema)
    ->using(Provider::Anthropic, 'claude-sonnet-4-5')
    ->withSystemPrompt('You are a home appliance maintenance expert...')
    ->withPrompt($userPrompt)
    ->withMaxTokens(1024)
    ->usingTemperature(0.3)          // low = more deterministic task lists
    ->withClientOptions(['timeout' => 30])  // seconds; default is Guzzle default (~30s)
    ->withClientRetry(2, 500)        // retry 2× with 500ms delay on transient errors
    ->asStructured();
```

> `withClientOptions` passes directly to Guzzle — use for the 10-second NFR: set `timeout` here, not in `php.ini`.

#### Prompt caching with Anthropic (resolves Open Question 3)

Cache the system prompt so repeated appliance additions in a session hit the cache.
**Constraint:** must use `withSystemPrompt()` / `withMessages()` — `withPrompt()` does not support per-message provider options.

```php
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

Prism::structured()
    ->withSchema($schema)
    ->using(Provider::Anthropic, 'claude-sonnet-4-5')
    ->withSystemPrompt(
        (new SystemMessage('You are a home appliance maintenance expert. Return structured JSON only.'))
            ->withProviderOptions(['cacheType' => 'ephemeral', 'cacheTtl' => '5m'])
    )
    ->withMessages([
        new UserMessage("Suggest maintenance tasks for: {$appliance->name}, model {$appliance->model}, type {$applianceType->name}."),
    ])
    ->asStructured();
```

TTL options: `'5m'` (default) or `'1h'`. Cache hit saves ~60–80% of prompt tokens cost and reduces latency.

#### Error handling

```php
use Prism\Prism\Exceptions\PrismException;

try {
    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Anthropic, 'claude-sonnet-4-5')
        ->withClientRetry(2, 500)
        ->asStructured();
} catch (PrismException $e) {
    // API errors, rate limits, invalid requests
    Log::error('AI suggestion failed', ['error' => $e->getMessage()]);
    // surface a user-friendly message; do not let the wizard crash
} catch (\Throwable $e) {
    Log::error('Unexpected error during AI call', ['error' => $e->getMessage()]);
}
```

#### Testing with `Prism::fake()`

No real API calls in tests — use `StructuredResponseFake`:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

it('shows AI-suggested tasks after appliance is saved', function () {
    $fake = Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'tasks' => [
                    ['name' => 'Clean filter', 'description' => 'Remove dust build-up', 'interval_value' => 3, 'interval_unit' => 'months'],
                    ['name' => 'Check belt',   'description' => 'Inspect for wear',     'interval_value' => 1, 'interval_unit' => 'years'],
                ],
            ])
            ->withUsage(new Usage(120, 80)),
    ]);

    // ... Livewire test actions ...

    $fake->assertCallCount(1);
    $fake->assertRequest(fn($reqs) => expect($reqs[0]->provider())->toBe('anthropic'));
});
```

Assertion helpers: `assertPrompt()`, `assertCallCount()`, `assertRequest(fn)`, `assertProviderConfig()`.

---

## Part B — Internal Codebase Compatibility Audit

> Conducted 2026-06-03 against commit `2875b38` (branch: main).
> Three parallel sub-agents explored: (1) dependencies, (2) Volt/Livewire patterns, (3) F-01 domain models.

### Audit Summary

| Area | Research Assumption | Codebase Reality | Verdict |
|---|---|---|---|
| Laravel version | 13.8 | ^13.8 | ✅ COMPATIBLE |
| PHP version | 8.1+ | ^8.3 | ✅ COMPATIBLE |
| Livewire version | "Livewire 4" implied | 3.6.4 | ⚠️ ADJUSTMENT NEEDED |
| Volt version | functional `state()`/`rules()` | 1.7.0, class-based anonymous class | ⚠️ ADJUSTMENT NEEDED (syntax only) |
| prism-php/prism | to be installed | NOT installed | ✅ EXPECTED (add via composer) |
| AI packages | none | none | ✅ COMPATIBLE |
| ANTHROPIC_API_KEY env | needs to be added | not in .env.example | ✅ EXPECTED (add manually) |
| `description` field in Prism schema | maps to DB column | NO column in maintenance_tasks | ❌ SCHEMA MISMATCH |
| ApplianceType system types | global scope | household_id nullable | ✅ COMPATIBLE (two-tier query required) |
| Appliance.model required | must validate | no model-layer guard | ⚠️ LESSONS.md RULE — enforce in form |
| interval_unit calendar values | days/weeks/months/years | DB enum also has hours/km | ⚠️ PROMPT MUST RESTRICT TO CALENDAR UNITS |
| anchor_type choices | from_last_done / fixed_calendar | enum: from_last_done, fixed_calendar | ✅ COMPATIBLE |
| is_plan_confirmed | plan confirmation flag | boolean on Appliance | ✅ COMPATIBLE |
| is_confirmed per task | task confirmation | boolean on MaintenanceTask | ✅ COMPATIBLE |
| anchor_date | backdating | nullable date on MaintenanceTask | ✅ COMPATIBLE |

### Finding 1 — Livewire Version: 3.6.4, not 4

**Impact:** The research document states "Livewire 4 recommends the `data-loading` attribute approach". The codebase has Livewire 3.6.4. The `data-loading` approach is Livewire 4+.

**Resolution:** Use `wire:loading` directly (supported in Livewire 3.x and works identically for showing spinners). Example:

```blade
<button wire:click="generateSuggestions" wire:loading.attr="disabled">
    <span wire:loading.remove>Get suggestions</span>
    <span wire:loading>Generating…</span>
</button>
```

`wire:stream` for streaming is also available in Livewire 3. No functional difference for S-01's synchronous call path.

### Finding 2 — Volt API: Class-Based, Not Functional

**Impact:** Research mentions `state()` + `rules()` — these are from the Volt **functional** API (similar to React hooks). Every existing component in this codebase uses the **class-based** Volt API: an anonymous class with public properties and `#[Validate]` attributes.

**Example of what exists** (`resources/views/livewire/pages/auth/register.blade.php`):
```php
<?php
new class extends Component {
    public string $name = '';
    public string $household_name = '';
    public string $email = '';

    public function register(): void {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'household_name' => ['required', 'string', 'max:255'],
            ...
        ]);
    }
}; ?>
```

**Resolution:** Implement the S-01 wizard as a class-based Volt component with a `public int $step = 1` property and `@if($step === N)` sections. The functional `state()` / `rules()` API from the research is NOT used in this codebase and should not be introduced. No functional difference — just different Volt syntax.

### Finding 3 — Critical Schema Mismatch: `description` Field

**Impact (HIGH):** The Prism structured output schema in Part A returns `description` per task. The `maintenance_tasks` table has **no `description` column**. Persisting AI output as-is would require dropping the field or adding a migration.

**DB columns** (maintenance_tasks): `name`, `interval_value`, `interval_unit`, `anchor_type`, `anchor_date`, `last_completed_at`, `last_metric_value`, `next_due_at`, `next_due_at_value`, `is_confirmed`. No `description`.

**Options:**
- **A (recommended):** Add a `description` nullable string column via a new migration. This is low-risk, useful UX (shows user what the task means), and aligns with the S-01 outcome goal of meaningful plan suggestions.
- **B:** Drop `description` from the Prism schema and only persist `name` + interval fields. Simpler, but loses the "why" explanation the AI provides.
- **C:** Return `description` from AI and display it in the wizard review step without persisting it. Pragmatic if adding a column is out of scope for S-01.

**Recommendation:** Option A — add the column. It costs one migration line and meaningfully improves the user-facing review step.

### Finding 4 — ApplianceType Two-Tier Query (Lessons.md)

**Required pattern** (from `context/foundation/lessons.md`):
```php
ApplianceType::whereNull('household_id')->orWhere('household_id', $householdId)
```

The S-01 appliance form must use this exact query when populating the type dropdown. The 13 seeded system types (`household_id = null`) are invisible if `$household->applianceTypes()` is used alone.

**Seeded types:** Refrigerator, Washing Machine, Dryer, Dishwasher, HVAC / Air Conditioner, Water Heater, Oven / Range, Microwave, Vacuum Cleaner, Car / Vehicle, Lawn Mower, Generator, Other.

### Finding 5 — Appliance.model Must Be Validated Required (Lessons.md)

The `model` column is non-nullable in the DB but has no model-layer guard. S-01 form validation must include:

```php
'model' => ['required', 'string', 'max:255'],
```

This is mandated by `context/foundation/lessons.md` ("Appliance.model must be validated as required in all write paths").

### Finding 6 — interval_unit: DB Has Metric Units (hours, km)

The DB enum for `interval_unit` is: `days`, `weeks`, `months`, `years`, `hours`, `km`.

The Prism schema instructs the AI to return only `days`, `weeks`, `months`, `years`. This is intentional for S-01 (calendar-based tasks). However, the Prism prompt **must explicitly constrain** the AI to calendar units to avoid the model returning `hours` or `km`, which are valid DB values but would break the calendar-based next_due_at calculation.

**Add to system prompt:**
> "Return calendar-based intervals only. interval_unit must be one of: days, weeks, months, years. Do not return hours or km."

### Finding 7 — interval_unit Branching Rule (Lessons.md)

From `context/foundation/lessons.md`: "Any code reading/writing next_due_at or next_due_at_value must branch on interval_unit first."

S-01 plan confirmation code must:
- For calendar units (days/weeks/months/years): populate `next_due_at` (datetime), leave `next_due_at_value` null
- For metric units (hours/km): populate `next_due_at_value` (float), leave `next_due_at` null

Since S-01 only generates calendar tasks via AI, all confirmed tasks will use `next_due_at`. But the branching guard should still be present to be safe.

### Finding 8 — No Existing Factories for Domain Models

Only `UserFactory` exists. S-01 tests using `Prism::fake()` will need to create `Appliance`, `ApplianceType`, `Household`, and `User` directly via DB or through the models' `create()` method. Factories for these models would aid testing but are not required for S-01 to ship.

### Routing Convention for S-01

Existing pattern for authenticated Volt pages uses `Route::view()`:
```php
Route::view('dashboard', 'dashboard')->middleware(['auth', 'verified'])->name('dashboard');
```

For Volt components, `Volt::route()` is used in `routes/auth.php`. S-01 should use `Volt::route()` under the `auth`/`verified` middleware group, consistent with the auth route pattern.

### Dependency Installation Plan

```bash
composer require prism-php/prism
php artisan vendor:publish --tag=prism-config
```

Add to `.env` and `.env.example`:
```env
ANTHROPIC_API_KEY=sk-ant-...
```

---

## Revised Recommended Stack for S-01 (Codebase-Adjusted)

```
prism-php/prism                → install; Anthropic call + structured output
Livewire Volt class-based      → $step integer, public properties, #[Validate], $this->validate()
wire:loading (Livewire 3.x)    → synchronous AI call spinner (NOT data-loading — Livewire 4 only)
One new migration              → add nullable description column to maintenance_tasks
```

Prism API surface (Part A) is fully compatible — no changes to how `Prism::structured()`, prompt caching, error handling, or `Prism::fake()` are used.

---

## Open Questions for Planning

1. **`description` column**: Add migration (Option A, recommended) or drop from schema (Option B)? → Decision feeds plan.
2. **Which Claude model?** `claude-sonnet-4-5` confirmed for native structured output. Recommend as default.
3. **AI call sync vs queued?** Background jobs disabled (`has_background_jobs: false` in baseline). Keep synchronous; set `withClientOptions(['timeout' => 30])` and surface a user-friendly fallback on timeout.
4. **Prompt caching?** Resolved: cache system prompt with `'cacheType' => 'ephemeral'`, TTL `'5m'`. Compatible with class-based Volt via service injection.

## Related Research

- `context/archive/2026-06-01-domain-schema-bootstrap/` — F-01 domain schema decisions; all models referenced in this audit were delivered there.
- `context/foundation/lessons.md` — three standing rules that directly constrain S-01 implementation (two-tier type query, model required, interval_unit branching).

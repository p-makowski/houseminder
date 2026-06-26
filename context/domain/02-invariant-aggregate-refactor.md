---
title: "House Minder — Invariant & Aggregate Refactor Plan"
created: 2026-06-25
type: refactor-plan
---

## KROK 0 — Kontekst

**Stack**: PHP 8.5 / Laravel 13 / Livewire 3 (Volt). Logika biznesowa żyje w trzech miejscach: `app/Actions/` (GenerateMaintenancePlan, RecordTaskCompletion), `app/Models/` (Eloquent), `resources/views/livewire/pages/` (Volt — pełnią rolę thin controller + view).

Brak dedykowanej warstwy Domain ani Service — każda reguła biznesowa jest albo w modelu Eloquent, albo bezpośrednio w komponencie Livewire.

Testy: PHPUnit 12 + in-memory SQLite, runner `composer test`. Projekt prowadzi testy Feature dla każdego flow.

**Korekta względem 01-domain-distillation.md**: `RecordTaskCompletion` nie obsługuje `anchor_type` z założenia — `RecordTaskCompletionTest.php:138` komentuje wprost: _"anchor_type does not affect mark-done arithmetic by design — fixed_calendar tasks advance from completion time, same as from_last_done."_ Podobnie `saveEdit` z jawną datą (`editNextDueAt`) pomija przeliczenie celowo — test `test_save_edit_with_explicit_next_due_at_skips_recalculation` to potwierdza (`ApplianceShowTaskEditTest.php:67`). Oba przypadki to świadome wybory projektowe, nie rozbieżności.

---

## KROK 1 — Niezmienniki biznesowe

### INV-1: Plan potwierdzony raz i nieodwracalnie

> PRD Guardrails: "The confirmed maintenance plan persists across logout/login cycles."
> PRD Business Logic: "the suggestion phase is complete after the first appliance addition."
> PRD US-01 AC: "Confirmed plan persists after logout and re-login."

Reguła: przejście `Appliance.is_plan_confirmed: false → true` jest jednorazowe i atomowe. Appliance z `is_plan_confirmed = false` to nieukończony szkic — nie powinien być widoczny w dashboardzie ani wymagać jawnego "odblokowania" przez użytkownika.

**Cytaty z kodu**:
- `app/Models/Appliance.php:14` — `is_plan_confirmed` jest w `#[Fillable]` bez żadnej ochrony
- `resources/views/livewire/pages/appliances/create.blade.php:194` — ustawia `'is_plan_confirmed' => true` bezpośrednio przez `Appliance::create([...])`
- `resources/views/livewire/pages/appliances/create.blade.php:225` — `'is_confirmed' => true` na każdym tasku osobno

### INV-2: Household scoping — absolutna izolacja danych

> PRD NFR: "An authenticated session can never retrieve appliance or maintenance data belonging to a different household — data isolation is absolute."
> AGENTS.md: "All appliance queries must be scoped to the authenticated household; cross-household data access is a critical bug."

Reguła: żaden zapyt nie może zwrócić danych innego household.

**Cytaty z kodu**:
- `app/Actions/RecordTaskCompletion.php:20` — guard: `abort_if(... $task->appliance->household_id !== $household->id, 403)`
- `resources/views/livewire/pages/appliances/show.blade.php:65` — guard w `mount()`
- `resources/views/livewire/pages/dashboard.blade.php:18` — `abort_if(...doesntExist(), 403)`
- `resources/views/livewire/pages/appliances/show.blade.php:163` — `abort_if($task->appliance_id !== $this->appliance->id, 403)` w `confirmDelete`/`startEdit` — sprawdza przynależność do `Appliance`, nie do `Household`

### INV-3: is_confirmed taska nie cofa się po potwierdzeniu

> Pochodna INV-1: task `is_confirmed = true` to część planu; regresja do `false` czyni go martwym (nie pojawia się w dashboardzie przez `scopeCalendar`/`scopeMetric`).

**Cytaty z kodu**:
- `app/Models/MaintenanceTask.php:23` — `is_confirmed` jest w `#[Fillable]`
- `app/Models/MaintenanceTask.php:29–44` — `scopeCalendar` i `scopeMetric` filtrują `where('is_confirmed', true)` — task z `false` jest niewidoczny

### INV-4: Atomowość tworzenia Appliance + wszystkich zadań planu

> PRD US-01: cały flow (dodaj urządzenie → potwierdź plan) tworzy spójny stan. Częściowy zapis (urządzenie bez zadań, albo kilka tasków bez urządzenia) to stan niespójny.

**Cytaty z kodu**:
- `resources/views/livewire/pages/appliances/create.blade.php:178` — jest `DB::transaction` obejmujący tworzenie `Appliance` + `MaintenanceTask` + `ServiceRecord` — ✅ egzekwowany

### INV-5: AGENTS.md — usunięcie urządzenia wymaga potwierdzenia

> AGENTS.md: "Appliance deletion requires explicit user confirmation; no soft-delete or auto-undo in v1."

**Cytaty z kodu**: Modal potwierdzenia w `show.blade.php:578–597`. Cascade delete na FK zachowany w migracji. Egzekwowany tylko w UI, nie na poziomie modelu. Ryzyko: bezpośrednie `Appliance::find($id)->delete()` nie wymaga potwierdzenia.

---

## KROK 2 — Klasyfikacja i wybór #1

| Niezmiennik | Rdzeniowość | Rozsianie | Egzekucja |
|---|---|---|---|
| **INV-1 Plan potwierdzony raz** | Najwyższa — to esencja produktu; plan to obietnica systemu wobec użytkownika | Rozpylony: flaga w Appliance, flaga w każdym MaintenanceTask, logika w Livewire Volt | **Naruszalny** — dwa fillable pola, zero metody domenowej |
| INV-2 Household isolation | Najwyższa (bezpieczeństwo) | 4+ pliki, ręczne guardy | Deklarowany, nie skonsolidowany; ryzyko narastające przy nowych features |
| INV-3 is_confirmed nie cofa się | Wysoka (pochodna INV-1) | `MaintenanceTask` + każdy caller | Naruszalny, ale w praktyce nikt nie ustawia `false` dziś |
| INV-4 Atomowość transakcji | Wysoka | Jeden `DB::transaction` w wizardzie | Egzekwowany w create-flow; brakuje w innych ścieżkach |
| INV-5 Potwierdzenie przed usunięciem | Średnia (UX) | UI modal | Tylko UI, brak modelu |

### Wybór: INV-1 — Plan Confirmation Invariant

**Uzasadnienie**: INV-1 jest jednocześnie najbardziej rdzeniowy (plan = centralny byt produktu; PRD: "The confirmed maintenance plan persists across logout/login cycles") i najsłabiej egzekwowany (żadnej metody domenowej, oba pola fillable, egzekucja wyłącznie w Livewire Volt na warstwie UI). Każda przyszła ścieżka zapisu (API endpoint, Artisan command, test factory) może stworzyć `Appliance` z `is_plan_confirmed = true` i `is_confirmed = false` na taskach — albo odwrotnie — i żaden strażnik tego nie zatrzyma.

INV-2 (household isolation) jest równie krytyczny z punktu widzenia bezpieczeństwa, ale jest egzekwowany (choć rozsiany). INV-1 nie jest egzekwowany **w ogóle** na warstwie domeny.

---

## KROK 3 — Diagnoza INV-1

### Gdzie dziś żyje reguła

**Warstwa UI (jedyny strażnik)**

`resources/views/livewire/pages/appliances/create.blade.php:159–244` — metoda `confirm()`:
- Sprawdza wstępnie `abort_if(count($this->backdates) !== count($this->tasks), 422)` (linia 161)
- Waliduje dane wejściowe (linie 163–173)
- W transakcji tworzy `Appliance` z `'is_plan_confirmed' => true` (linia 194)
- Tworzy każdy `MaintenanceTask` z `'is_confirmed' => true` (linia 225)

**To jest jedyne miejsce w kodzie, które egzekwuje niezmiennik.**

### Warstwy nie egzekwujące

**Model `Appliance`** (`app/Models/Appliance.php:14`):
```php
#[Fillable(['household_id', 'appliance_type_id', 'name', 'model', 'purchase_date', 'is_plan_confirmed'])]
```
`is_plan_confirmed` jest fillable — każdy `$appliance->fill(['is_plan_confirmed' => false])` przechodzi bez wyjątku.

**Model `MaintenanceTask`** (`app/Models/MaintenanceTask.php:23`):
```php
#[Fillable(['appliance_id', 'name', ..., 'is_confirmed'])]
```
`is_confirmed` jest fillable — task może być przełączony z `true` na `false`.

**Fabryka** (`database/factories/MaintenanceTaskFactory.php:28`):
```php
'is_confirmed' => false,
```
Domyślnie tworzy niezatwierdzone taski — jeśli test zapomni ustawić `is_confirmed = true`, task znika z dashboardu, a test przechodzi bez błędu (nie sprawdza widoczności).

**Ścieżka `saveNewTask()`** (`show.blade.php:313`):
Tworzy task z `'is_confirmed' => true` bez sprawdzania, czy `appliance->is_plan_confirmed` jest `true`. Można dodać task do urządzenia w teorii jeszcze nieskonfigurowanego.

### Gdzie błąd jest "połykany"

`create.blade.php:confirm()` ustawia `is_plan_confirmed = true` jako zwykłe pole w `Appliance::create()`. Jeśli aplikacja wywołałaby `confirm()` dwa razy (np. double-submit, retry po timeout), druga operacja **nie rzuci wyjątku** — po prostu nadpisze istniejące urządzenie tworzeniem nowego (bo `confirm()` zawsze robi `Appliance::create()`). To nie jest scenariusz aktualny, ale pokazuje brak ochrony na modelu.

---

## KROK 4 — Projekt agregatu-strażnika

### Aggregate root: `Appliance` z metodami domenowymi

Cel: `Appliance` staje się jedynym miejscem zmiany stanu planu. `is_plan_confirmed` jest **usunięty z `#[Fillable]`**.

#### Wyjątek domenowy

```php
// app/Exceptions/PlanAlreadyConfirmedException.php
namespace App\Exceptions;

final class PlanAlreadyConfirmedException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Maintenance plan is already confirmed and cannot be modified.');
    }
}
```

#### Metoda `confirmPlan()` na modelu `Appliance`

```php
// app/Models/Appliance.php

/**
 * @param array<int, array{
 *   name: string, description: string|null,
 *   interval_value: int, interval_unit: string,
 *   anchor_type: string, anchor_date: string|null,
 *   last_completed_at: string|null, next_due_at: string|null,
 *   next_due_at_value: float|null
 * }> $tasks
 * @param array<int, array{
 *   completed_at: string|null, metric_reading: float|null, notes: string|null
 * }|null> $initialRecords  null entry = brak rekordu dla danego taska
 */
public function confirmPlan(array $tasks, array $initialRecords = []): void
{
    if ($this->is_plan_confirmed) {
        throw new \App\Exceptions\PlanAlreadyConfirmedException();
    }

    if (empty($tasks)) {
        throw new \InvalidArgumentException('At least one task is required to confirm a plan.');
    }

    DB::transaction(function () use ($tasks, $initialRecords): void {
        foreach ($tasks as $i => $taskData) {
            $task = $this->maintenanceTasks()->create(
                array_merge($taskData, ['is_confirmed' => true])
            );

            $record = $initialRecords[$i] ?? null;
            if ($record !== null && isset($record['completed_at'])) {
                ServiceRecord::create([
                    'maintenance_task_id' => $task->id,
                    'completed_at'        => $record['completed_at'],
                    'metric_reading'      => $record['metric_reading'] ?? null,
                    'notes'               => $record['notes'] ?? null,
                ]);
            }
        }

        // Wyłącznie przez metodę domenową — nie przez fill()
        DB::table('appliances')
            ->where('id', $this->id)
            ->update(['is_plan_confirmed' => true]);

        $this->is_plan_confirmed = true;
    });
}
```

**Preconditions**:
- `is_plan_confirmed === false` → jeśli `true`: rzuca `PlanAlreadyConfirmedException`
- `count($tasks) >= 1` → jeśli puste: rzuca `\InvalidArgumentException`

**Dlaczego `DB::table()` zamiast `$this->save()`**: unikamy przypadkowego nadpisania innych pól przez fill(); chcemy zmienić wyłącznie `is_plan_confirmed`. Alternatywnie `$this->forceFill(['is_plan_confirmed' => true])->save()` — obie opcje działają, ale `forceFill` pomija `#[Fillable]` i jest równie bezpieczna przy tym wzorcu.

#### Usunięcie `is_plan_confirmed` z Fillable

```php
// PRZED:
#[Fillable(['household_id', 'appliance_type_id', 'name', 'model', 'purchase_date', 'is_plan_confirmed'])]

// PO:
#[Fillable(['household_id', 'appliance_type_id', 'name', 'model', 'purchase_date'])]
// is_plan_confirmed jest zapisywany wyłącznie przez confirmPlan()
```

#### Usunięcie `is_confirmed` z Fillable w `MaintenanceTask`

```php
// PRZED:
#[Fillable(['appliance_id', 'name', 'description', 'interval_value', 'interval_unit',
            'anchor_type', 'anchor_date', 'last_completed_at', 'last_metric_value',
            'next_due_at', 'next_due_at_value', 'is_confirmed'])]

// PO:
#[Fillable(['appliance_id', 'name', 'description', 'interval_value', 'interval_unit',
            'anchor_type', 'anchor_date', 'last_completed_at', 'last_metric_value',
            'next_due_at', 'next_due_at_value'])]
// is_confirmed jest ustawiany wyłącznie wewnątrz Appliance::confirmPlan()
```

#### Cienkie API w Livewire Volt

```php
// resources/views/livewire/pages/appliances/create.blade.php — metoda confirm()

// PRZED (linie 178–241):
$appliance = DB::transaction(function () use ($household) {
    // ... tworzy ApplianceType ...
    $appliance = Appliance::create([
        'household_id'      => $household->id,
        'appliance_type_id' => $type->id,
        'name'              => $this->name,
        'model'             => $this->model,
        'purchase_date'     => $this->purchaseDate ?: null,
        'is_plan_confirmed' => true,         // ← logika domenowa w UI
    ]);
    foreach ($this->tasks as $i => $task) {
        // ... 30+ linii kalkulacji i zapisu tasków + ServiceRecords ...
    }
    return $appliance;
});

// PO: UI przygotowuje dane, agregat egzekwuje niezmiennik
$appliance = DB::transaction(function () use ($household) {
    // Krok 1: utwórz typ (bez zmian)
    $type = $this->resolveApplianceType($household);

    // Krok 2: utwórz nieskonfigurowane urządzenie (is_plan_confirmed domyślnie false)
    $appliance = Appliance::create([
        'household_id'      => $household->id,
        'appliance_type_id' => $type->id,
        'name'              => $this->name,
        'model'             => $this->model,
        'purchase_date'     => $this->purchaseDate ?: null,
    ]);

    // Krok 3: deleguj niezmiennik do agregatu
    try {
        $appliance->confirmPlan(
            $this->buildTaskData(),      // mapuje $this->tasks + kalkuluje next_due_at
            $this->buildInitialRecords() // mapuje $this->backdates
        );
    } catch (\App\Exceptions\PlanAlreadyConfirmedException $e) {
        abort(422, $e->getMessage()); // double-submit guard
    }

    return $appliance;
});
```

Metody pomocnicze `buildTaskData()` i `buildInitialRecords()` to prywatne ekstrakcje z istniejącej logiki w `confirm()` — przenoszą tylko kalkulację dat, nie regułę domenową.

---

## KROK 5 — Before/After, plan faz, testy

### Before/After dla każdego miejsca reguły

| Lokalizacja | PRZED | PO |
|---|---|---|
| `Appliance.php:14` | `is_plan_confirmed` w `#[Fillable]` | Usunięty z `#[Fillable]`; chroniony przez `confirmPlan()` |
| `MaintenanceTask.php:23` | `is_confirmed` w `#[Fillable]` | Usunięty z `#[Fillable]`; ustawiany wyłącznie wewnątrz `confirmPlan()` |
| `create.blade.php:confirm()` | `Appliance::create([..., 'is_plan_confirmed' => true])` + pętla z `is_confirmed` | `Appliance::create([...])` + `$appliance->confirmPlan(tasks, records)` |
| `create.blade.php:confirm()` | Brak ochrony przed podwójnym wysłaniem | `catch (PlanAlreadyConfirmedException)` → abort 422 |
| `MaintenanceTaskFactory.php:28` | `'is_confirmed' => false` jako domyślna | Bez zmian; factory tworzy taski spoza planu (dla testów jednostkowych modelu) — `confirmPlan()` jest ścieżką dla planu |

### Plan faz refaktoru

**Faza 1 — Wyjątek domenowy** _(test-first)_
- Plik: `app/Exceptions/PlanAlreadyConfirmedException.php`
- Testy (nowe): patrz sekcja poniżej
- Weryfikacja: `composer test` zielony

**Faza 2 — Metoda `confirmPlan()` na modelu `Appliance`** _(test-first)_
- Plik: `app/Models/Appliance.php`
- Dodać metodę, usunąć `is_plan_confirmed` z `#[Fillable]`
- Testy: patrz sekcja poniżej
- Weryfikacja: `composer test` zielony

**Faza 3 — Usunięcie `is_confirmed` z `#[Fillable]` na `MaintenanceTask`** _(nie test-first; weryfikacja statyczna)_
- Plik: `app/Models/MaintenanceTask.php`
- Sprawdzić, że `composer phpstan` przechodzi (brak wywołań `fill(['is_confirmed' => ...])` poza `confirmPlan`)
- Weryfikacja: `composer phpstan` + `composer test`

**Faza 4 — Refaktor `create.blade.php`** _(nie test-first; istniejące testy są regresją)_
- Plik: `resources/views/livewire/pages/appliances/create.blade.php`
- Zastąpić inline-logikę wywołaniem `$appliance->confirmPlan()`
- Istniejące testy: `AddApplianceWizardTest`, `WizardCalculationTest`, `WizardValidationTest` — wszystkie muszą przejść bez zmian
- Weryfikacja: `composer test`

**Faza 5 — PHPStan + Pint** _(non-optional)_
- `composer phpstan` level 6 musi przejść
- `composer pint:check` musi przejść

---

### Przypadki testowe dla niezmiennika

Nowy plik: `tests/Feature/Appliances/PlanConfirmationInvariantTest.php`

```
LEGALNE operacje:
test_confirm_plan_sets_is_plan_confirmed_true()
  — nowe urządzenie, confirmPlan z 1 taskiem → is_plan_confirmed = true

test_confirm_plan_creates_all_tasks_as_confirmed()
  — confirmPlan z 3 taskami → każdy task ma is_confirmed = true

test_confirm_plan_creates_initial_service_record_when_provided()
  — confirmPlan z backdatem → ServiceRecord istnieje w bazie

test_confirm_plan_wraps_in_transaction()
  — symulacja wyjątku w środku pętli tasków → Appliance nie istnieje w bazie
    (DB::transaction rollback)

NIELEGALNE operacje (fail-fast):
test_confirm_plan_throws_when_already_confirmed()
  — confirmPlan wywołane dwa razy → drugie rzuca PlanAlreadyConfirmedException

test_confirm_plan_throws_when_tasks_empty()
  — confirmPlan z pustą tablicą → rzuca \InvalidArgumentException

test_is_plan_confirmed_not_in_fillable()
  — $appliance->fill(['is_plan_confirmed' => true]) na niezatwierdzone urządzenie
    → is_plan_confirmed pozostaje false (fill ignoruje pole spoza Fillable)

test_is_confirmed_not_in_fillable()
  — $task->fill(['is_confirmed' => false]) na potwierdzony task
    → is_confirmed pozostaje true
```

Nowy plik: `tests/Unit/Models/ApplianceConfirmPlanTest.php` _(unit, bez Livewire, bez HTTP)_

```
test_confirm_plan_sets_plan_confirmed_flag()
test_confirm_plan_is_idempotent_guard_throws_domain_exception()
test_confirm_plan_requires_at_least_one_task()
```

---

### Nowe "load-bearing" nazwy do zarejestrowania

- `App\Exceptions\PlanAlreadyConfirmedException` — wyjątek domenowy; nie `\Exception` ani `\RuntimeException`
- `Appliance::confirmPlan(array $tasks, array $initialRecords): void` — jedyna ścieżka do `is_plan_confirmed = true`
- Konwencja: `is_plan_confirmed` i `is_confirmed` są **write-once fields** — wartość `false → true` tylko przez `confirmPlan()`, nigdy przez `fill()` / `update()` / `save()`

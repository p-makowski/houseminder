---
title: "House Minder — Anti-Corruption Layer: AI Suggestion Port"
created: 2026-06-25
type: refactor-plan
---

## KROK 0 — Kontekst

**Stack**: PHP 8.5 / Laravel 13 / Livewire 3 (Volt). Zależność AI: `prism-php/prism ^0.100.1`.

**Deklaracja wymienialności (tech-stack.md)**:
> "AI-generated maintenance suggestions (FR-010) call the Anthropic or OpenAI API via a **lightweight service class**; no additional framework package is required."

Słowa "lightweight service class" i brak frazy "no additional framework package" sugerują, że autorzy zakładali izolację adaptera AI od reszty kodu. Kod tej intencji nie dotrzymuje.

**Warstwy kodu (do weryfikacji)**:
- `app/Actions/` — operacje domenowe
- `resources/views/livewire/pages/` — UI/Livewire Volt (warstwa prezentacji)
- `tests/Feature/` — testy integracyjne

---

## KROK 1 — Identyfikacja przeciekających zależności

### A. `prism-php/prism`

| Plik | Linia | Import |
|---|---|---|
| `app/Actions/GenerateMaintenancePlan.php` | 7 | `use Prism\Prism\Enums\Provider;` |
| `app/Actions/GenerateMaintenancePlan.php` | 8 | `use Prism\Prism\Facades\Prism;` |
| `app/Actions/GenerateMaintenancePlan.php` | 9 | `use Prism\Prism\Schema\ArraySchema;` |
| `app/Actions/GenerateMaintenancePlan.php` | 10 | `use Prism\Prism\Schema\NumberSchema;` |
| `app/Actions/GenerateMaintenancePlan.php` | 11 | `use Prism\Prism\Schema\ObjectSchema;` |
| `app/Actions/GenerateMaintenancePlan.php` | 12 | `use Prism\Prism\Schema\StringSchema;` |
| `app/Actions/GenerateMaintenancePlan.php` | 13 | `use Prism\Prism\ValueObjects\Messages\SystemMessage;` |
| `app/Actions/GenerateMaintenancePlan.php` | 14 | `use Prism\Prism\ValueObjects\Messages\UserMessage;` |
| **`resources/views/livewire/pages/appliances/create.blade.php`** | **16** | **`use Prism\Prism\Exceptions\PrismException;`** ← **PRZECIEK DO UI** |
| `tests/Feature/Appliances/AddApplianceWizardTest.php` | 11 | `use Prism\Prism\Facades\Prism;` |
| `tests/Feature/Appliances/AddApplianceWizardTest.php` | 12 | `use Prism\Prism\Testing\StructuredResponseFake;` |
| `tests/Feature/Appliances/AddApplianceWizardTest.php` | 13 | `use Prism\Prism\ValueObjects\Usage;` |
| `tests/Feature/Appliances/AiContractTest.php` | 8 | `use Prism\Prism\Facades\Prism;` |
| `tests/Feature/Appliances/AiContractTest.php` | 9 | `use Prism\Prism\Testing\StructuredResponseFake;` |
| `tests/Feature/Appliances/AiContractTest.php` | 10 | `use Prism\Prism\ValueObjects\Usage;` |
| `tests/Feature/Appliances/AiFailureTest.php` | 10 | `use Prism\Prism\Exceptions\PrismException;` |
| `tests/Feature/Appliances/TaskEditingTest.php` | 10 | `use Prism\Prism\Facades\Prism;` |
| `tests/Feature/Appliances/TaskEditingTest.php` | 11 | `use Prism\Prism\Testing\StructuredResponseFake;` |
| `tests/Feature/Appliances/TaskEditingTest.php` | 12 | `use Prism\Prism\ValueObjects\Usage;` |

Łącznie: **3 warstwy** (Action, UI, testy) × **18 importów** w **5 plikach**.

### B. Carbon (`illuminate/support` — pośrednio przez Laravel)

Carbon jest używany w `resources/views/livewire/pages/appliances/create.blade.php:204-205,233` i `show.blade.php:10,239,305,313,319,333`. Jest częścią `illuminate/support` — pakietu zawsze obecnego w Laravel, praktycznie niewymienialne. Nie jest kandydatem na ACL.

---

## KROK 2 — Klasyfikacja i wybór #1

Jedyna realna zewnętrzna zależność z ryzykiem przecieku: **prism-php/prism**.

| Oś oceny | Ocena |
|---|---|
| **Liczba warstw** | 3 (Action + UI + testy) — przekroczona granica; UI nie powinna znać kształtu wyjątków biblioteki AI |
| **Koszt wymiany dziś** | Wysoki — zmiana dostawcy AI (np. z Prism na bezpośredni Guzzle/OpenAI SDK) wymagałaby edycji 5 plików w 3 warstwach; testy musiałyby wymienić `Prism::fake()` na nowy mechanizm mockowania |
| **Rozjazd intencja-vs-kod** | Krytyczny — tech-stack.md deklaruje "lightweight service class" (= port/adapter), a PrismException przecieka wprost do komponentu Livewire |
| **Ryzyko operacyjne** | `PrismException` w UI oznacza, że zmiana kształtu wyjątku przez autora Prism (breaking change w semver minor) może zepsuć obsługę błędów w UI bez żadnego sygnału z testów domenowych |

**Wybór #1: prism-php/prism**. Jednocześnie najbardziej rozprzestrzeniany i bezpośrednio sprzeczny z deklarowaną intencją izolacji.

---

## KROK 3 — Diagnoza

### Przeciek przez granicę UI

`create.blade.php:16` importuje `PrismException` bezpośrednio w komponencie Livewire:

```php
// resources/views/livewire/pages/appliances/create.blade.php:16
use Prism\Prism\Exceptions\PrismException;

// ...linia 120:
} catch (PrismException $e) {
    $this->aiError = 'Could not generate maintenance tasks. Please try again.';
    Log::error('GenerateMaintenancePlan failed', ['error' => $e->getMessage()]);
}
```

Warstwa UI pełni tu rolę adaptera błędów — wie, co to `PrismException` i jak na nią reagować. To jest dokładnie wiedza, która powinna żyć wyłącznie w adapterze.

### Duplikacja mechanizmu mockowania w testach

Cztery pliki testowe (`AddApplianceWizardTest`, `AiContractTest`, `AiFailureTest`, `TaskEditingTest`) importują typy Prism wyłącznie po to, żeby skonstruować fake. W szczególności `StructuredResponseFake` i `Usage` to wewnętrzne value objects biblioteki — żaden test domeny nie powinien ich znać.

```php
// tests/Feature/Appliances/AddApplianceWizardTest.php:11-13
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

// linia 21-22:
$fake = Prism::fake([
    StructuredResponseFake::make()->withStructured(['tasks' => [...]])->withUsage(new Usage(120, 80)),
]);
```

Gdyby Prism zmienił kształt `StructuredResponseFake` lub `Usage` (np. w wersji 0.101), wszystkie cztery pliki testowe pękłyby jednocześnie — mimo że żaden nie testuje Prisma, a każdy testuje domenę.

### Zadeklarowana wymienialność jest fikcją

tech-stack.md:
> "no additional framework package is required"

Dziś wymiana Prism na np. bezpośredni klient HTTP wymaga: przepisania `GenerateMaintenancePlan`, usunięcia `PrismException` z `create.blade.php`, i zmiany mechanizmu mockowania w 4 plikach testowych. To 6 plików w 3 warstwach — przeciwieństwo "lightweight service class".

---

## KROK 4 — Projekt ACL

### Value Object: `SuggestedTask`

```php
// app/Domain/Ai/SuggestedTask.php (nowy plik)
final readonly class SuggestedTask
{
    public function __construct(
        public string $name,
        public string $description,
        public int $intervalValue,
        public string $intervalUnit,  // 'days'|'weeks'|'months'|'years'
    ) {}
}
```

`SuggestedTask` jest domenowym bytem — nie zna Prisma, nie zna żadnego HTTP klienta. Jest jedynym kształtem, jaki reszta kodu widzi jako wynik AI.

### Wyjątek domenowy: `AiSuggestionFailedException`

```php
// app/Domain/Ai/AiSuggestionFailedException.php (nowy plik)
final class AiSuggestionFailedException extends \RuntimeException {}
```

Zastępuje `PrismException` na granicy. Komponent Livewire łapie `AiSuggestionFailedException` — nie wie nic o Prism.

### Port (interfejs domenowy)

```php
// app/Domain/Ai/AiSuggestionPort.php (nowy plik)
interface AiSuggestionPort
{
    /**
     * @return list<SuggestedTask>
     * @throws AiSuggestionFailedException
     */
    public function suggest(string $applianceName, string $applianceModel, string $typeName): array;
}
```

Cały kod domenowy i UI zna tylko ten interfejs. Nie importuje Prisma.

### Adapter: `PrismAiSuggestionAdapter`

```php
// app/Adapters/Ai/PrismAiSuggestionAdapter.php (nowy plik)
final class PrismAiSuggestionAdapter implements AiSuggestionPort
{
    public function suggest(string $applianceName, string $applianceModel, string $typeName): array
    {
        // Wszystkie obecne importy Prism (Provider, Facades\Prism, Schema\*, ValueObjects\Messages\*)
        // żyją TYLKO tutaj.
        try {
            $response = Prism::structured()
                ->withSchema($this->buildSchema())
                ->using(Provider::Anthropic, 'claude-sonnet-4-5')
                // ... (pełna konfiguracja jak w GenerateMaintenancePlan.php:56-67)
                ->asStructured();

            return $this->parseTasks($response->structured['tasks'] ?? []);
        } catch (PrismException $e) {
            throw new AiSuggestionFailedException($e->getMessage(), previous: $e);
        }
    }

    /** @return list<SuggestedTask> */
    private function parseTasks(array $raw): array
    {
        $required = ['name', 'description', 'interval_value', 'interval_unit'];
        foreach ($raw as $task) {
            foreach ($required as $field) {
                if (! array_key_exists($field, (array) $task)) {
                    throw new AiSuggestionFailedException('AI returned a task missing required fields.');
                }
            }
        }

        return array_map(fn ($t) => new SuggestedTask(
            name: (string) $t['name'],
            description: (string) $t['description'],
            intervalValue: (int) $t['interval_value'],
            intervalUnit: (string) $t['interval_unit'],
        ), $raw);
    }

    private function buildSchema(): ObjectSchema { /* ... */ }
}
```

### Refaktor `GenerateMaintenancePlan` → delegacja do portu

```php
// app/Actions/GenerateMaintenancePlan.php — po refaktorze
final class GenerateMaintenancePlan
{
    public function __construct(private readonly AiSuggestionPort $ai) {}

    /** @return list<array{name: string, description: string, interval_value: int, interval_unit: string}> */
    public function __invoke(string $applianceName, string $applianceModel, string $typeName): array
    {
        $tasks = $this->ai->suggest($applianceName, $applianceModel, $typeName);
        return array_map(fn (SuggestedTask $t) => [
            'name'           => $t->name,
            'description'    => $t->description,
            'interval_value' => $t->intervalValue,
            'interval_unit'  => $t->intervalUnit,
        ], $tasks);
    }
}
```

`GenerateMaintenancePlan` przestaje importować cokolwiek z `Prism\`. Zna tylko `AiSuggestionPort` i `SuggestedTask`.

### Zmiana w warstwie UI

```php
// create.blade.php — po refaktorze
// USUŃ: use Prism\Prism\Exceptions\PrismException;
// DODAJ: use App\Domain\Ai\AiSuggestionFailedException;

} catch (AiSuggestionFailedException $e) {
    $this->aiError = 'Could not generate maintenance tasks. Please try again.';
    Log::error('GenerateMaintenancePlan failed', ['error' => $e->getMessage()]);
}
```

### Fake dla testów

```php
// tests/Fakes/FakeAiSuggestionAdapter.php (nowy plik — w tests/)
final class FakeAiSuggestionAdapter implements AiSuggestionPort
{
    /** @param list<SuggestedTask> $tasks */
    public function __construct(private readonly array $tasks = []) {}

    public function suggest(string $applianceName, string $applianceModel, string $typeName): array
    {
        return $this->tasks;
    }
}
```

Testy konfigurują `FakeAiSuggestionAdapter` przez service container — bez żadnego importu Prism.

---

## KROK 5 — Dowód izolacji + before/after

### Before/after: które pliki znają Prism

| Plik | Before | After |
|---|---|---|
| `app/Actions/GenerateMaintenancePlan.php` | 8 importów Prism | **0 importów Prism** (zna tylko `AiSuggestionPort`) |
| `resources/views/livewire/pages/appliances/create.blade.php` | `use PrismException` | **0 importów Prism** (łapie `AiSuggestionFailedException`) |
| `tests/Feature/Appliances/AddApplianceWizardTest.php` | `Prism::fake()` + `StructuredResponseFake` + `Usage` | **0 importów Prism** (`FakeAiSuggestionAdapter`) |
| `tests/Feature/Appliances/AiContractTest.php` | `Prism::fake()` + `StructuredResponseFake` + `Usage` | **0 importów Prism** |
| `tests/Feature/Appliances/AiFailureTest.php` | `PrismException` | **0 importów Prism** |
| `tests/Feature/Appliances/TaskEditingTest.php` | `Prism::fake()` + `StructuredResponseFake` + `Usage` | **0 importów Prism** |
| `app/Adapters/Ai/PrismAiSuggestionAdapter.php` | (nieistnieje) | **JEDYNE miejsce z importami Prism** |

Kryterium sukcesu z KROK 6: `grep -r "Prism\\\\" app/ resources/ tests/ --include="*.php"` zwraca wyłącznie `app/Adapters/Ai/PrismAiSuggestionAdapter.php`.

### Rozstrzygnięcie otwartego pytania: gdzie zakodować model AI

`GenerateMaintenancePlan.php:58` hardkoduje `using(Provider::Anthropic, 'claude-sonnet-4-5')`. Po refaktorze ta decyzja należy wyłącznie do adaptera — `PrismAiSuggestionAdapter`. Zmiana dostawcy lub modelu (np. z Anthropic na OpenAI, z `claude-sonnet-4-5` na `claude-opus-4-8`) wymaga edycji jednego pliku.

---

## KROK 6 — Weryfikacja i plan faz

### Kryterium sukcesu

```bash
grep -r "Prism\\\\" app/ resources/ tests/ --include="*.php"
# Oczekiwany wynik: wyłącznie linie z app/Adapters/Ai/PrismAiSuggestionAdapter.php
```

### Pliki dziś znające Prism → po refaktorze

| Plik | Dziś | Po refaktorze |
|---|---|---|
| `app/Actions/GenerateMaintenancePlan.php` | **zna** (8 importów) | nie zna |
| `resources/views/.../create.blade.php` | **zna** (PrismException) | nie zna |
| `tests/Feature/Appliances/AddApplianceWizardTest.php` | **zna** | nie zna |
| `tests/Feature/Appliances/AiContractTest.php` | **zna** | nie zna |
| `tests/Feature/Appliances/AiFailureTest.php` | **zna** | nie zna |
| `tests/Feature/Appliances/TaskEditingTest.php` | **zna** | nie zna |
| `app/Adapters/Ai/PrismAiSuggestionAdapter.php` | nieistnieje | **jedyny właściciel** |

### Plan faz

**Faza 1 — Typy domenowe** (bez zmiany produkcji)
- Utwórz `app/Domain/Ai/SuggestedTask.php` (readonly final class)
- Utwórz `app/Domain/Ai/AiSuggestionPort.php` (interface)
- Utwórz `app/Domain/Ai/AiSuggestionFailedException.php`
- Test: `tests/Unit/Domain/Ai/SuggestedTaskTest.php` — konstruktor i pola (czysta unit, bez Prism)

**Faza 2 — Adapter** (jedyne miejsce z Prism)
- Utwórz `app/Adapters/Ai/PrismAiSuggestionAdapter.php` implementujący `AiSuggestionPort`
- Przenieś całą logikę schema + wywołanie Prism z `GenerateMaintenancePlan` do adaptera
- `PrismException` → `AiSuggestionFailedException` w catch adaptera
- Zarejestruj binding: `AppServiceProvider::register()` → `$this->app->bind(AiSuggestionPort::class, PrismAiSuggestionAdapter::class);`
- Uruchom `composer phpstan` — powinno przejść bez zmian w `GenerateMaintenancePlan`

**Faza 3 — Refaktor `GenerateMaintenancePlan`**
- Wstrzyknij `AiSuggestionPort` przez konstruktor
- Usuń wszystkie 8 importów Prism
- Usuń logikę budowania schema i wywołanie Prism — zostaje mapowanie `SuggestedTask → array`
- `composer phpstan` + `composer test` — suite musi przejść bez zmian w testach (testy jeszcze używają `Prism::fake()`)

**Faza 4 — Fake dla testów**
- Utwórz `tests/Fakes/FakeAiSuggestionAdapter.php`
- Zaktualizuj `AddApplianceWizardTest`, `AiContractTest`, `AiFailureTest`, `TaskEditingTest`:
  - Usuń `Prism::fake()`, `StructuredResponseFake`, `Usage`, `PrismException`
  - W `setUp()` zamiast `Prism::fake()` rejestruj `FakeAiSuggestionAdapter` przez `$this->app->instance(AiSuggestionPort::class, new FakeAiSuggestionAdapter([...]))`
- `composer test` — pełna suite musi przejść

**Faza 5 — Refaktor UI i weryfikacja grep**
- W `create.blade.php`: usuń `use Prism\Prism\Exceptions\PrismException`, dodaj `use App\Domain\Ai\AiSuggestionFailedException`, zmień catch
- Uruchom kryterium sukcesu: `grep -r "Prism\\\\" app/ resources/ tests/ --include="*.php"` — tylko adapter
- `composer pint:check` + `composer phpstan` + `composer test`

**Commit**: `[CHORE] M4L5 anti-corruption layer: isolate Prism behind AiSuggestionPort`

---
title: "House Minder — Domain Distillation"
created: 2026-06-25
type: domain-distillation
---

## KROK 0 — Kontekst projektu

**Stack**: PHP 8.5 / Laravel 13 / Livewire 3 (Volt) / Tailwind CSS. AI przez bibliotekę Prism → Anthropic API (claude-sonnet-4-5). Deploy: Fly.io + GitHub Actions.

**Dokumenty źródłowe**: `context/foundation/prd.md` (kompletny PRD), `context/foundation/tech-stack.md`, `AGENTS.md`, `README.md`.

**Warstwy kodu**:
- `app/Actions/` — operacje domenowe (GenerateMaintenancePlan, RecordTaskCompletion)
- `app/Models/` — Eloquent: Household, Appliance, ApplianceType, MaintenanceTask, ServiceRecord
- `app/Support/` — CalendarInterval (logika dat)
- `resources/views/livewire/pages/` — Livewire Volt: komponenty pełniące rolę kontrolerów + widoków
- `database/migrations/` — schemat

**Uwaga**: `AGENTS.md` wymienia `app/Services/` jako katalog dla logiki biznesowej — ten katalog nie istnieje. Logika AI i serwisowa żyje w `app/Actions/`.

---

## KROK 1 — Ubiquitous Language

| Pojęcie | Definicja | Cytat źródłowy | Kod |
|---|---|---|---|
| **Household** | Jednostka gospodarska (para, rodzina) dzieląca jedno konto. | PRD: "A household — a couple or family — managing multiple home appliances together." | `app/Models/Household.php:13` |
| **Appliance** | Urządzenie domowe śledzone pod kątem serwisowania. | PRD FR-005: "User can add an appliance with name (required), model (required), purchase date (optional), and appliance type." | `app/Models/Appliance.php:16` |
| **ApplianceType** | Kategoria urządzenia (np. "lodówka", "pralka"). Globalne predefiniowane + per-household niestandardowe. | PRD FR-005: "selected from a pre-seeded list or added as a custom type — custom types are per-household in v1." | `app/Models/ApplianceType.php:13` |
| **MaintenanceTask** | Cykliczne zadanie serwisowe przypisane do urządzenia (np. "wymień filtr co 6 miesięcy"). | PRD FR-011: "confirm, edit, and add custom maintenance tasks with recurrence intervals." | `app/Models/MaintenanceTask.php:24` |
| **IntervalUnit** | Jednostka interwału: `days`, `weeks`, `months`, `years` (calendar) lub `hours`, `km` (metric). | PRD FR-012: "metrics shown conditionally based on appliance type." | `database/migrations/2026_06_01_000005_create_maintenance_tasks_table.php:19` |
| **AnchorType** | Sposób wyliczania kolejnego terminu: `from_last_done` (od ostatniego ukończenia) lub `fixed_calendar` (od stałej daty). | PRD FR-011: "interval anchor is user-selectable: 'from last completed date' or 'fixed calendar date'." | `database/migrations/2026_06_01_000005_create_maintenance_tasks_table.php:20` |
| **MaintenancePlan** | Zatwierdzona kolekcja zadań dla urządzenia. Byt domenowy wyrażony w PRD jako całość. | PRD US-01: "confirms the maintenance plan." / "the suggestion phase is complete after the first appliance addition." | BRAK jako klasa; reprezentowany przez `is_plan_confirmed` na `Appliance` + `is_confirmed` na każdym `MaintenanceTask` |
| **ServiceRecord** | Wpis potwierdzający wykonanie zadania (data, odczyt metryki, notatki). | PRD FR-012: "User can record a service as completed with applicable metrics (date, motor hours, kilometers, etc.)." | `app/Models/ServiceRecord.php:13` |
| **TaskStatus** | Klasyfikacja zadania wg pilności: overdue, due this week, due this month, upcoming. | PRD FR-013: "tasks grouped by status: overdue, due soon (default threshold: 30 days), and upcoming." | Computed properties w `dashboard.blade.php:36–81`, `show.blade.php:91–150` |
| **Backdate** | Cofnięty wpis serwisowy ustawiany podczas konfiguracji urządzenia w celu obliczenia dokładniejszych dat. | PRD US-01: "User can set a past completion date for any task during setup." | `create.blade.php:91–97` (tablica `$backdates`) |
| **DueSoonThreshold** | Domyślny horyzont 30 dni dla grupy "due soon". | PRD FR-013: "default threshold: 30 days, configurable later." | Hardkodowane `addDays(30)` w `dashboard.blade.php:65,75` i `show.blade.php:127,139` |
| **CalendarInterval** | Algorytm obliczania kolejnej daty serwisu z daty bazowej + interwału. | PRD: "advances the next due date by the task's interval." | `app/Support/CalendarInterval.php:12` |
| **AI Suggestion** | Lista zadań serwisowych wygenerowana przez model AI na podstawie nazwy, modelu i typu urządzenia. | PRD FR-010: "AI-generated maintenance task suggestions based on the appliance's name, model, and type." | `app/Actions/GenerateMaintenancePlan.php:19` |
| **RecordTaskCompletion** | Akcja oznaczenia zadania jako wykonanego + zapis ServiceRecord + aktualizacja `next_due_at`. | PRD US-02: "Marking a task as done removes it from overdue/due-soon and advances the next due date." | `app/Actions/RecordTaskCompletion.php:14` |

---

## KROK 2 — Klasyfikacja subdomen

| Obszar | Kategoria | Uzasadnienie |
|---|---|---|
| **Maintenance Planning** (AI suggestions + customization + plan confirmation) | **Core** | PRD: "AI-generated maintenance suggestions" to wprost wskazana przewaga konkurencyjna ("core bet", FR-010 bez counter-argumentu). |
| **Task Tracking** (next_due_at, RecordTaskCompletion, status grouping) | **Core** | PRD US-02: główna pętla produktu — "mark done → advance next due date → see updated dashboard." Bez tego product value = zero. |
| **Appliance Management** (CRUD Appliance, ApplianceType) | **Supporting** | Konieczna podstawa dla planowania, ale sama w sobie nie jest wartością — to infrastruktura dla Core. |
| **Household & Access Control** (household scoping, shared credential) | **Supporting** | Konieczny dla bezpieczeństwa ("data isolation is absolute"), ale table-stakes dla każdej multi-tenant aplikacji. |
| **Service History** (ServiceRecord, Backdate) | **Supporting** | Zapewnia ślad audytowy i zasilanie dat — ważna, ale podrzędna wobec planowania i śledzenia. |
| **Authentication** (Laravel Breeze, email+password) | **Generic** | PRD FR-001/FR-002: standardowe logowanie; żadna logika własna poza household-binding przy rejestracji. |
| **AI Integration** (Prism library, Anthropic API call) | **Generic** | Infrastruktura integracyjna — dostawca AI jest wymienny; wartość leży w domenie, nie w samym wywołaniu API. |

---

## KROK 3 — Kandydaci na agregaty i niezmienniki

### A. Appliance (z MaintenancePlan)

**Niezmiennik A1 — Przynależność do Household**
> PRD Guardrails: "Appliance data of one household is never accessible to another household."
> AGENTS.md: "All appliance queries must be scoped to the authenticated household; cross-household data access is a critical bug."

Status: **Częściowo egzekwowany** — każdy Livewire komponent i Action sprawdza ręcznie (np. `RecordTaskCompletion.php:20`, `show.blade.php:65`). Brak skonsolidowanej bramki na poziomie modelu lub global scope.

**Niezmiennik A2 — Plan potwierdzony jednorazowo**
> PRD: "the suggestion phase is complete after the first appliance addition" / "Confirmed plan persists across logout/login cycles."

Status: **Ignorowany przez model** — `is_plan_confirmed` jest polem w `#[Fillable]` (`Appliance.php:14`); model nie chroni przed nadpisaniem. Brak metody domenowej `confirmPlan()` z guard warunkiem.

**Niezmiennik A3 — Usunięcie wymaga potwierdzenia**
> PRD FR-009: "requires explicit confirmation step before deletion is permanent."

Status: **Egzekwowany** — UI wymusza modal z potwierdzeniem; `cascadeOnDelete` chroni spójność FK.

---

### B. MaintenanceTask

**Niezmiennik B1 — next_due_at pochodzi z anchor + interval**
> PRD US-02: "advances the next due date by the task's interval."

Status: **Deklarowany, nie egzekwowany** — `CalendarInterval` istnieje, ale `next_due_at` jest fillable. W `saveEdit()` (`show.blade.php:239`) akceptowana jest dowolna ręczna data bez walidacji relacji do interwału: `$task->next_due_at = Carbon::parse($validated['editNextDueAt'])`.

**Niezmiennik B2 — MetricTask nie posiada next_due_at**
> PRD FR-012: metryki są warunkowe; metric tasks nie mają kalendarza.

Status: **Konwencja UI, nie egzekwowany modelem** — `saveNewTask()` ustawia `next_due_at = null` dla metric tasks (`show.blade.php:323`), ale model nie blokuje ustawienia daty na metric task przez `fill()`.

**Niezmiennik B3 — is_confirmed nie cofa się**
> PRD: "the schedule is theirs to own" po potwierdzeniu.

Status: **Ignorowany** — `is_confirmed` jest fillable (`MaintenanceTask.php:23`); brak domain logic blokującej regresję.

---

### C. Household (jako granica dostępu)

**Niezmiennik C1 — Izolacja danych między gospodarstwami**
> PRD NFR: "An authenticated session can never retrieve appliance or maintenance data belonging to a different household — data isolation is absolute."

Status: **Deklarowany w wielu miejscach, brak single enforcer** — sprawdzany ręcznie w każdym komponencie (mount, markDone) i w Action. Ryzyko: pominięcie jednego nowego entry-point = naruszenie izolacji.

---

## KROK 4 — MODEL vs KOD

| Dokument mówi | Kod robi | Dowód (plik:linia) |
|---|---|---|
| `MaintenancePlan` jako spójny byt domenowy z jednorazowym potwierdzeniem | Dwie niezależne flagi: `is_plan_confirmed` na `Appliance` + `is_confirmed` na każdym `MaintenanceTask`; brak klasy/aggregate | `app/Models/Appliance.php:14`, `app/Models/MaintenanceTask.php:23` |
| Plan potwierdzony = nieodwracalny | `is_plan_confirmed` jest w `#[Fillable]` — można nadpisać | `app/Models/Appliance.php:14` |
| `next_due_at` = anchor date + interval (deterministyczne) | W `saveEdit()` użytkownik może ustawić dowolną datę ręcznie przez `editNextDueAt` | `resources/views/livewire/pages/appliances/show.blade.php:239` |
| `anchor_type = fixed_calendar` powinien liczyć od stałej daty niezależnie od ukończenia | `RecordTaskCompletion` ignoruje `anchor_type` — zawsze liczy od `$completedAt` (now()) | `app/Actions/RecordTaskCompletion.php:32–37` |
| DueSoon threshold = 30 dni (konfigurowalne w v2) | Hardkodowany literal `addDays(30)` zduplikowany w dwóch komponentach | `dashboard.blade.php:65,75` / `show.blade.php:127,139` |
| Household scoping jako absolutny niezmiennik bezpieczeństwa | Guard sprawdzany ręcznie w każdym komponencie i Action bez wspólnej polityki | `RecordTaskCompletion.php:20`, `show.blade.php:65`, `dashboard.blade.php:18` |
| `app/Services/` — katalog dla logiki biznesowej | Katalog nie istnieje; Actions w `app/Actions/` | AGENTS.md vs wynik `find app/` |
| Metryki (`hours`, `km`) warunkowe na podstawie typu urządzenia | `ApplianceType` nie przechowuje żadnej informacji o obsługiwanych metrykach; ograniczenie interval_unit jest globalne dla wszystkich typów | `app/Models/ApplianceType.php`, `MaintenanceTask.php:19` (migration) |

---

## KROK 5 — Ranking refaktoru

### #1 — MaintenancePlan jako aggregate (wartość: najwyższa / ryzyko: wysokie)

To rdzeń produktu. Brak explicit aggregate powoduje: (a) możliwość ustawienia `is_plan_confirmed = false` przez `fill()`, (b) `is_confirmed` rozproszony po taskach bez spójnej egzekucji, (c) brak enkapsulacji reguły "plan potwierdza się jednorazowo."

**Kierunek**: Dodać metodę domenową `Appliance::confirmPlan(array $tasks)` z guard `if ($this->is_plan_confirmed) throw`, lub wyodrębnić Value Object `MaintenancePlan`. Usunąć `is_plan_confirmed` z `#[Fillable]`.

---

### #2 — AnchorType w RecordTaskCompletion (wartość: wysoka / ryzyko: silent bug)

`RecordTaskCompletion.php:32–37` w ogóle nie rozróżnia `from_last_done` vs `fixed_calendar`. Dla zadania `fixed_calendar` ("wymień filtr 15 marca każdego roku") kod liczy `next_due_at = now() + interval` zamiast `anchor_date + n * interval`. To produkuje błędne terminy serwisowe — główna wartość produktu.

**Kierunek**: W `RecordTaskCompletion` dodać rozgałęzienie na `anchor_type`: dla `fixed_calendar` naliczać od `anchor_date` w przód do najbliższej daty po `$completedAt`.

---

### #3 — Household scoping jako Policy/GlobalScope (wartość: bezpieczeństwo / ryzyko: narastające)

Każdy nowy feature wymaga ręcznego dodania guard check. PRD klasyfikuje naruszenie izolacji jako "critical bug". Aktualnie brak single-point enforcement.

**Kierunek**: `HouseholdScope` jako global Eloquent scope na `Appliance` + `MaintenanceTask`, lub Laravel Authorization Policy `AppliancePolicy::view/update/delete` + Gate.

---

### #4 — next_due_at jako chroniony Value Object (wartość: domenowa spójność / ryzyko: średnie)

Edycja tasków pozwala na dowolną datę (`show.blade.php:239`), omijając regułę "next_due_at = anchor + interval." W dłuższej perspektywie prowadzi do niespójnych harmonogramów.

**Kierunek**: Usunąć `next_due_at` z `#[Fillable]`; wszystkie zmiany przez metodę `task->reschedule(Carbon $anchor): void` korzystającą z `CalendarInterval`.

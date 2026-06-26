# Raport architektoniczny — Moduł 4 (10xArchitect)

**Data**: 2026-06-26

---

## 1. Opisane projekty

| Projekt | Stack | Skala | Artefakt |
|---|---|---|---|
| **orchid-platform** | PHP/Laravel, Stimulus JS (39 kontrolerów), Blade | Pakiet open-source; ~25 pól, 20+ layoutów; 1 aktywny kontrybutor (77% commitów) | L2, L3, L4 |
| **houseminder** | PHP 8.5 / Laravel 13 / Livewire 3 (Volt) / Tailwind; AI przez Prism → Anthropic API | SaaS dla gospodarstw domowych; ~5 modeli Eloquent; deploy Fly.io | L5 |

---

## 2. Mapa projektu (z L2 — orchid-platform)

**Centrum aktywności**: `resources/js/controllers` (churn 73), `src/Screen/Fields` (49), `src/Screen/Layouts` (41).
Szczyt zmian w Q1 2026; Q2 2026 to faza stylingu (migracja Fluent UI). Bus factor = 1 (Alexandr Chernyaev).

**5 kluczowych wniosków**:

1. **AsyncController — strefa ryzyka #1.** Screen przekazywany przez zaszyfrowany string; refleksja + `App::call()`;
   testowalne tylko z pełnym routerem i kluczem szyfrowania — każdy test musi przejść przez HTTP.
2. **Most PHP↔JS — strefa ryzyka #2.** 39 kontrolerów Stimulus powiązanych z Blade przez `data-controller="<string>"`.
   Rename pliku JS lub stringa = cichy błąd runtime; brak testów E2E. Najwyższe ryzyko operacyjne w repo.
3. **Cykl `Selection ↔ Filterable`.** Wzajemne importy: `src/Screen/Layouts/Selection.php` → `src/Filters/Filter`
   → `src/Filters/Filterable.php` → `src/Screen/Layouts/Selection`. Każda zmiana po jednej stronie wymaga weryfikacji obu.
4. **`Screen.php` extends `Http\Controller`** — odwrócenie warstw; Screen zależy od Http, nie odwrotnie.
   Refaktoryzacja na POPO wymagałaby zmiany wielu miejsc naraz.
5. **Unknowns**: powiązania wewnątrz `resources/sass/` nieznane (brak grafu SCSS); dynamiczne importy
   w `AsyncController` (`app()`, `new $class()`) niewidoczne dla grepa.

---

## 3. Analiza ficzera (z L3 — orchid-platform)

**Wybrany przepływ**: powiadomienia. Uzasadnienie: repo-map wskazuje go jako strefę ryzyka #2 (most JS↔PHP)
i strefę bus factor (logika notification modal = wiedza implicite Chernyaeva). Commit `20cd95f13` (2026-04-10)
przepisał powiadomienia ze Screen na modal — atomowy blast radius 15 plików, świeżo niezbadany.

**Feature overview**: Kod userlandu buduje `OrchidMessage` → `$user->notify()` → `OrchidChannel` zapisuje wiersz
w tabeli `notifications` (Laravel standard, JSON `data`). Orchid nie wysyła powiadomień samodzielnie — to czyste API
dla userlandu (potwierdzone ast-grep: 0 wywołań `->notify()` w `src/`). Odczyt: `<x-orchid-notification>` renderuje
dzwonek w `profile.blade.php`; polling `unreadCount` co 60 s; klik ładuje listę Turbo Stream przez `NotificationController@index`
z cursor pagination i infinite scroll via `IntersectionObserver`.

**Technical debt**:

1. **Most PHP↔JS — 4 niezależne kontrakty stringowe** (nazwa kontrolera, nazwy `value`, nazwy `target`, ID DOM) plus
   nazwy tras; brak typowania, brak testu E2E. Rename po jednej stronie psuje funkcję przy zielonych testach PHP.
   *Potwierdzone ast-grep*: filtr `whereIn('type', [...])` w **5 miejscach** (`NotificationController.php:31,52,70,89`
   + `Components/Notification.php:38`) — wstępny opis zakładał 4.
2. **Zero pokrycia JS.** `notification_controller.js` zawiera nietrywialną logikę (polling, `CustomEvent`, gałąź `≥10`,
   `IntersectionObserver`, tryb badge vs sentinel). Brak runnera testów JS (`package.json` ma tylko `vite build`).
3. **`NotificationDisabledTest` jest no-op.** Asertuje na nieistniejące trasy (`orchid.notifications`,
   `orchid.api.notifications`); test przechodzi pusto. Realna bramka `orchid.notifications.index` nigdy niezweryfikowana.

---

## 4. Plan refaktoryzacji (z L4 — orchid-platform)

**Co refaktoryzowane**: dwa niezależne naprawy strukturalne po migracji Screen→modal (`20cd95f13`), jeden PR.

- **Faza 1 — usunięcie martwego kodu** (zero ryzyka): `NotificationTable.php` (wywołuje skasowany widok, 0 referencji)
  + `trigger.blade.php` (0 bajtów, 0 referencji). Weryfikacja auto: `grep -r "NotificationTable\|notification/trigger"` = 0
  wyników + `composer test` zielony. Wymaga wpisu CHANGELOG (klasa niedziałająca od 2026-04-10, ale nadal autoloadowana).
- **Faza 2 — naprawa domyślnej metody HTTP** (niskie ryzyko): `notification_controller.js:9` zmiana `default: "get"`
  na `"post"` + usunięcie redundantnego atrybutu Blade `data-notification-method-value="post"`. Weryfikacja auto:
  `testUnreadCount()` POST→200; ręcznie: polling badge + modal w przeglądarce, brak 405 w network tab.

**Czego świadomie NIE robimy**: konsolidacja 5 miejsc `whereIn` do stałej (odrębny PR); naprawa kontraktów PHP↔JS
(wymaga decyzji o TypeScript lub E2E na poziomie repo); usunięcie `DashboardMessage`/`DashboardChannel` (zależy od
polityki TTL danych); naprawa `NotificationDisabledTest` (niestrukturalne, osobny PR).

---

## 5. Domena wg DDD (z L5 — houseminder)

**Ubiquitous Language — 5 kluczowych pojęć**:

| Pojęcie | Definicja | Rozjazd model-vs-kod |
|---|---|---|
| **MaintenancePlan** | Zatwierdzona, nieodwracalna kolekcja zadań dla urządzenia | BRAK klasy; dwie fillable flagi rozrzucone po modelu i taskach |
| **AnchorType** | `from_last_done` lub `fixed_calendar` — sposób liczenia `next_due_at` | `RecordTaskCompletion` ignoruje `anchor_type` celowo (świadomy wybór, potwierdzony testem) |
| **TaskStatus** | Klasyfikacja: overdue / due this week / due this month / upcoming | Computed w Blade; `DueSoonThreshold` hardkodowany jako `addDays(30)` w **2 komponentach** |
| **CalendarInterval** | Algorytm następnej daty z anchora + interwału | Istnieje w `app/Support/`; `next_due_at` mimo to jest fillable — reguła obchodzona przez `show.blade.php:239` |
| **Household** | Absolutna granica dostępu; cross-household = critical bug | Guard ręczny w każdym komponencie i Action; brak single enforcer |

**Niezmiennik #1 (INV-1)**: plan potwierdzony jednorazowo i nieodwracalnie (`is_plan_confirmed: false → true`).
Agregat: `Appliance`. Stan: **naruszalny** — `is_plan_confirmed` w `#[Fillable]` (`Appliance.php:14`); brak metody
domenowej; jedyny strażnik to warstawa UI (`create.blade.php:confirm()`). Docelowo: `Appliance::confirmPlan()`
z guardem `if ($this->is_plan_confirmed) throw PlanAlreadyConfirmedException` + usunięcie obu flag z `#[Fillable]`.

**ACL — przeciekająca zależność**: `prism-php/prism` przecieka przez **3 warstwy** (Action + UI + testy),
**5 plików**, **18 importów**. Najpoważniejszy przeciek: `PrismException` bezpośrednio w
`resources/views/livewire/pages/appliances/create.blade.php:16` — warstwa UI wie o wewnętrznych typach wyjątków
biblioteki AI. `tech-stack.md` deklaruje "lightweight service class" (= port/adapter); kod tej intencji nie dotrzymuje.
Docelowo: jedynym właścicielem Prism jest `app/Adapters/Ai/PrismAiSuggestionAdapter.php`; reszta zna tylko
`AiSuggestionPort` i `AiSuggestionFailedException`.

---

## 6. Decyzje, które należą do mnie

- **Wybór przepływu (L3)**: AI wskazało `AsyncController` jako najwyższe ryzyko technicznie. Wybrałem powiadomienia —
  świeżo po atomowej migracji, konkretny blast radius, otwarte pytania o martwy kod wymagające natychmiastowej odpowiedzi.
- **Zakres planu (L4)**: AI zaproponowało też konsolidację 5 miejsc `whereIn` do stałej. Odłożyłem ją — zmiana jest
  semantyczna, nie strukturalna, i nie eliminuje długu #1 (kontrakt stringowy PHP↔JS). Priorytet: minimum bezpieczne od zaraz.
- **Korekta rozbieżności `anchor_type` (L5)**: AI oznaczyło brak obsługi `fixed_calendar` w `RecordTaskCompletion`
  jako silent bug. Po przeczytaniu `02-invariant-aggregate-refactor.md` okazało się świadomym wyborem potwierdzonym
  dedykowanym testem — nie bug. Wniosek: czytać testy przed kwalifikacją rozbieżności jako dług.
- **Hierarchia niezmienników (L5)**: AI równorzędnie traktowało INV-1 i INV-2. Uznałem INV-1 za pilniejszy —
  INV-2 (izolacja household) jest rozsiany, ale wszędzie egzekwowany; INV-1 nie ma żadnego strażnika na warstwie domeny.
- **Lokalizacja raportu**: prompt nie wskazał projektu dla `context/`. Zdecydowałem o zapisie w `10xdev/context/`
  jako raporcie przekrojowym modułu — obok obu projektów, nie wewnątrz żadnego z nich.

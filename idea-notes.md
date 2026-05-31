## House Minder - MVP

### Główny problem
Zarządzanie dużą ilością sprzętu domowego może być problematyczne, zwłaszcza gdy trzeba pamiętać o regularnych przeglądach, 
konserwacji i wymianie części. Często brakuje centralnego miejsca do przechowywania informacji o sprzęcie, 
co prowadzi do zapomnienia o ważnych terminach i potencjalnie kosztownych napraw.

### Najmniejszy zestaw funkcjonalności
- Zapisywanie informacji o sprzęcie (nazwa, model, data zakupu, instrukcje obsługi)
- Zapisywanie uplodowanego paragonu zakupu (pdf, zdjęcie)
- Zapisywanie uplodowanej instrukcji obslugi (pdf, zdjęcie)
- Manualne dodawanie informacji o interwałach przeglądów i konserwacji
  - Przykładowo: "przegląd co 6 miesięcy", "wymiana filtra co 12 miesięcy", "umycie wnętrza co 3 miesiące"
- Przypominanie o zbliżających się przeglądach i konserwacji
- Podpowiadanie terminów przeglądów i konserwacji na podstawie danych o sprzęcie, pozyskanych z internetu dla danego modelu lub zblizonych sprzetow, oraz jego wieku 
- Przeglądanie, edycja i usuwanie danego sprzętu z bazy
- Prosty system kont użytkowników

### Co NIE wchodzi w zakres MVP
- Analiza uploadowanej instrukcji obsługi i paragonu w celu automatycznego uzupełnienia informacji o interwalach przeglądów i konserwacji
- Import wielu formatów (CSV, DOCX, itp.)
- Współdzielenie sprzętu między użytkownikami
- Integracje z innymi platformami (np. kalendarz Google, Alexa, itp.)
- Aplikacja mobilna (na początek tylko web, ale z responsywnym designem)

### Kryteria sukcesu
- Użytkownik dostaje podstawowe sugestie co do interwałów serwisowych na podstawie danych o sprzęcie, nawet jeśli nie uzupełnił ich ręcznie
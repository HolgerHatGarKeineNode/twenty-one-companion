# VERSION 1.1.0 — „Create & Curate"

> **Mission:** Aus der read-only-App (v1.0) wird eine vollwertige Lese-**und-Schreib**-App
> mit AAA-Design, geführtem Onboarding, durchdachter Navigation und CRUD für eigene
> Meetups, Termine, Orte & Kurse.
>
> **Single Source of Truth.** Diese Datei wird abgehakt. Bei Session-Start lesen, erste
> offene `[ ]`-Checkbox finden, dort weitermachen, Erledigtes auf `[x]` setzen.
> Bei größeren Entscheidungen einen Eintrag im **Entscheidungs-Log** (unten) ergänzen.

---

## Ausgangslage (Stand v1.0.x)

- **Stack:** Laravel 13 · Livewire 4 (SFC `⚡*.blade.php`) · Flux UI v2 (free+pro) · Tailwind v4 · NativePHP Mobile v3 · Pest 4.
- **Portal-Anbindung:** `app/Services/PortalApi.php` ist eine **rein lesende** Saloon-Fassade mit 2-stufigem Cache (frisch + stale/offline). Auth-Token via Deep-Link-Flow (`PortalAuth`).
- **Seiten:** Meetups (Liste+Detail), Termine, Karte, Kurse/Referenten, Profil, Onboarding (1-Step Sprache+Region).
- **Navigation:** Bottom-Nav (Meetups · Termine · Karte · Profil) + Flyout-Menü (Kurse, Referenten, Städte, Einstellungen).
- **Schreib-API ist serverseitig schon da** (Portal): `create/update-meetup`, `add-meetup-to-mine`, `create/update-meetup-event`, `create/update-venue`, `create/update-city`, `create/update-lecturer`, `create/update-course`, `create/update-course-event`. ➜ v1.1.0 macht sie in der App nutzbar.

**Leitprinzipien für v1.1.0**
1. **AAA-Design** — keine generische CRUD-Optik. Motion, Haptik, Skeletons, Empty-States mit Charakter, konsistente Brand-Tokens.
2. **Offline-tauglich bleiben** — Schreib-Operationen müssen mit dem bestehenden Cache/Offline-Modell koexistieren (Outbox/Retry statt Datenverlust).
3. **Auth-gated** — Schreiben nur mit gültigem Portal-Token; saubere „Bitte verbinden"-Flows.
4. **Jede Änderung getestet** — Pest-Feature-/Livewire-Tests, `pint --dirty` vor Abschluss.

---

## Phase 0 — Schreib-Fundament (Write-Layer) 🏗️

> Ohne dieses Fundament ist jede CREATE/EDIT-Funktion Copy-Paste-Chaos. Zuerst die Schiene legen.

- [x] **0.1** Saloon-Write-Requests anlegen (POST/PATCH) analog zu den GET-Requests in `app/Http/Integrations/Portal/Requests/`. Basis: `PortalWriteRequest` (body-only, `HasJsonBody`). Test: `tests/Feature/PortalWriteRequestsTest.php` (Method/Endpoint/Body je Request, 8 grün).
  - [x] `CreateMeetupRequest` (`POST /meetup`), `UpdateMeetupRequest` (`PATCH /meetup/{id}`)
  - [x] `CreateMeetupEventRequest` (`POST /meetup-events`), `UpdateMeetupEventRequest` (`PATCH /meetup-events/{id}`)
  - [x] `CreateVenueRequest` (`POST /venues`), `UpdateVenueRequest` (`PATCH /venues/{id}`)
  - [x] `CreateCityRequest` (`POST /cities`), `UpdateCityRequest` (`PATCH /cities/{id}`)
  - [ ] ⛔ `AddMeetupToMineRequest` — **blockiert:** im Portal existiert KEINE REST-Route dafür (nur als MCP-Tool). Klärung nötig (Portal-Branch `feature/mobile-auth`), siehe Offene Fragen.
  - [ ] (später, Phase 7) `Create/UpdateLecturerRequest`, `Create/UpdateCourseRequest`, `Create/UpdateCourseEventRequest`
- [x] **0.2** Schreib-Fassade `app/Services/PortalWriter.php` (Gegenstück zu `PortalApi`):
  - [x] sendet authentifizierte Writes, mappt Erfolg/Validierungsfehler (422 → Feld-Fehler) sauber zurück
  - [x] invalidiert betroffene Cache-Keys nach Erfolg (`my-meetups`/`map-meetups`/`meetup-events`/`venues`/`cities`) via neue `PortalApi::forget()`; Stale-Kopie bleibt als Offline-Netz. *(Optimistisches Cache-Schreiben bewusst weggelassen — siehe Log.)*
  - [x] wirft typisierte Ergebnisse: `WriteResult` (`WriteStatus`: Success | ValidationError | Unauthorized | Forbidden | NetworkFailure) mit `errorFor()` zum Feld-Mapping
- [x] **0.3** Form-Object-Pattern: Livewire `Form`-Klassen unter `app/Livewire/Forms/` (`MeetupForm`) mit `#[Validate]`-Regeln statt Inline-Validation auf den Seiten. (Wird in Phase 4 in eine Seite eingebunden + dort getestet.)
- [x] **0.4** Offline-Outbox — **Minimal-Variante** umgesetzt: `WriteResult::networkFailure` liefert klare Fehlermeldung, UI kann manuell erneut senden (Muster wie `PortalPage::retry`). *(SQLite-Outbox + „Wird gesendet…"-Badge bleibt Stretch für später.)*
- [x] **0.5** Auth-Gate-Helper: Blade-Component `<x-requires-portal>` zeigt den Inhalt nur mit Token, sonst „Konto verbinden"-CTA mit Sprung zur Profil-Seite (Login-Flow).
- [x] **0.6** Tests: `tests/Feature/PortalWriterTest.php` (Erfolg, 422→Feldfehler, 401, 403, 500→networkFailure, kein-Token-Gate, Cache-Invalidierung, kein Write-Retry — 18 grün) + `RequiresPortalComponentTest.php` (Gate verbunden/unverbunden).

**Akzeptanz:** Ein Dummy-`PortalWriter::createMeetup()` lässt sich aus tinker/Test feuern, invalidiert den Cache und liefert bei 422 strukturierte Feld-Fehler.

---

## Phase 1 — AAA Design-System & Politur 🎨

> Erst die Design-Sprache schärfen, dann bauen alle folgenden Screens darauf auf.

- [x] **1.1** Design-Tokens konsolidiert: Radius- (`rounded-tile/card/sheet`), Shadow- (`shadow-card/-pressed/-pop/-glow`) und Motion-Tokens (`ease-spring`, `ease-emphasized`, `--duration-tap`, `animate-shimmer`) im `@theme`-Block von `resources/css/app.css`, dokumentiert als „Source of Truth". Brand-Skala war bereits vollständig.
- [x] **1.2** Motion-System: `.page-enter` (Layout-Main), `.list-stagger` (CSS-`--i`-Delay), `.pressable` (Active-Scale + Elevation), eine Spring + Emphasized-Ease. Vollständiger `prefers-reduced-motion`-Block.
- [x] **1.3** Haptik zweigleisig: clientseitig sofort über `window.haptic`/Alpine-Magic `$haptic()` (Web-Vibration-API, an Cards/Nav/Buttons verdrahtet) + serverseitige Bestätigung über `Device::vibrate()` in `PortalPage::vibrate()` (Retry-Pfade). Getestet (`OfflineStatesTest`, Device-Mock).
- [x] **1.4** `<x-skeleton-card>` (Varianten `list`/`detail`, Shimmer) — auf dem Meetups-Index per `wire:loading` beim Filtern/Suchen eingebunden.
- [x] **1.5** `x-empty-state` auf die neuen Tokens (`rounded-tile`, `shadow-card`) gehoben; Mini-CTA-Slot demonstriert/getestet. `x-portal-empty-state` erbt davon.
- [x] **1.6** Cards auf AAA-Niveau: `x-list-link-card` (Elevation, Pressed-State, Chevron-Shift, Haptik), `x-place-card` (Elevation, Flaggen-Ring), `x-meetup-avatar` (Tiefen-Ring). Termine- & Detail-Seiten auf dieselben Tokens vereinheitlicht.
- [x] **1.7** Dark-/Light-Audit: App ist dark-first (SSR `class="dark"`), Light-Pfad konsistent über `dark:`-Varianten. Elevation im Dark-Mode via Border/Ring statt Schatten (`dark:shadow-none`). Kontraste geprüft (Zinc-500/400-Text ≥ AA, Brand-Akzent in beiden Modes via `accent-content`). `appearance` weiter respektiert.
- [x] **1.8** `<x-sheet>` (Bottom-Sheet auf Flux-`flyout`/`position=bottom`, Greifer, gerundete obere Ecken via `rounded-sheet`, `pb-safe`) — die Termin-Detail-Modal nutzt es bereits.
- [x] **1.9** Icon-/Typo-Audit: durchgängig Heroicons über `flux:icon`; tabellarische Ziffern kommen aus der Monospace-Brand-Schrift Inconsolata gratis (+ gezieltes `tabular-nums` an Zähl-Stellen). Schriftgrößen-Skala über Flux-`size`-Props konsistent.

**Akzeptanz:** Referenz-Seite (Meetups-Index) demonstriert Tokens, Motion, Skeletons, Haptik & neue Cards; durch 181 grüne Tests + sauberen Build abgesichert. ⚠️ Finaler visueller Screenshot-Abnahme erfolgt on-device im Rahmen von Phase 9.4 (Emulator/Echtgerät via adb).

---

## Phase 2 — Navigation 2.0 🧭

- [ ] **2.1** Bottom-Nav überdenken: zentraler **Create-FAB** (kontextsensitiv: auf Meetups → „Meetup anlegen", auf Termine → „Termin anlegen") als 5. Element oder schwebender Button.
- [ ] **2.2** Aktiver-Tab-Indikator mit Motion (animierter Pill/Underline), Active-Icon-Variante (solid vs. outline).
- [ ] **2.3** Globale Suche / Command-Palette (`⌘K`-artig, hier als Such-Sheet): meetup-/stadt-/kurs-übergreifend, Sprung direkt ins Detail.
- [ ] **2.4** Konsistente Zurück-Navigation & Header-Kontext (Titel + optionale Sub-Action rechts) auf allen Detail-Seiten.
- [ ] **2.5** Flyout-Menü aufräumen: Gruppierung (Entdecken / Meine Inhalte / Einstellungen), Profil-Header mit Avatar + Verbindungsstatus.
- [ ] **2.6** Deep-Link-/Back-Stack-Verhalten testen (Hardware-Back auf Android verlässt nicht versehentlich die App mitten im Flow).
- [ ] **2.7** „Meine Inhalte"-Hub: neue Sektion/Seite, die eigene Meetups, Termine, Orte, Kurse bündelt (Einstieg in alle CRUD-Flows).

**Akzeptanz:** FAB + aktiver Indikator + globale Suche funktionieren; Navigationsstruktur per Pest-Browser-Smoke-Test ohne JS-Fehler.

---

## Phase 3 — Onboarding 3.0 (perfekt geführt) ✨

> Aktuell: 1 Step (Sprache + Region). Ziel: mehrstufiger, motivierender Flow mit Permission-Priming und optionaler Portal-Verbindung.

- [ ] **3.1** Mehrstufiger Pager (Swipe/Progress-Dots): Welcome → Sprache → Region → Interessen/Topics → Portal verbinden (optional) → Benachrichtigungen (optional) → Fertig.
- [ ] **3.2** Welcome-Screen mit Wertversprechen (3 Kacheln: Meetups finden · Termine im Kalender · Eigene Community pflegen).
- [ ] **3.3** Topic-/Interessen-Auswahl (z. B. Meetups, Kurse, Orte) → personalisiert Start-Tab & Filter-Defaults.
- [ ] **3.4** **Portal-Verbindung in den Onboarding-Flow integrieren** (Lightning/Nostr-Login per Deep-Link/Amber) — mit klarer „Überspringen"-Option (read-only weiter nutzbar).
- [ ] **3.5** Permission-Priming für Push-Benachrichtigungen (erklärt *warum*, bevor der OS-Dialog kommt) — siehe Phase 8.6.
- [ ] **3.6** Animierte Übergänge zwischen Steps, Skip/Back, „Fortschritt wird gespeichert" (Resume bei App-Neustart mitten im Onboarding).
- [ ] **3.7** `AppPreferences` um `topics`, `onboardingStep` erweitern; `EnsureOnboarded`-Middleware respektiert Teilfortschritt.
- [ ] **3.8** Tests: Onboarding-Defaults, Skip-Pfad, Portal-Verbindung-Pfad, Resume.

**Akzeptanz:** Frischer App-Start führt durch den Pager; Skip führt zu read-only-Meetups; Verbinden schaltet Schreib-Features frei.

---

## Phase 4 — „Meine Meetups": CREATE & EDIT 📝

> Kern-Wunsch: Nutzer können selbst Meetups anlegen, bearbeiten und „zu meinen hinzufügen".

- [ ] **4.1** „Meetup anlegen"-Flow (Bottom-Sheet/Vollbild-Form, via FAB aus Phase 2):
  - [ ] Felder: Name, Stadt (Suche/Anlegen), Land, Beschreibung (Markdown), Logo (Upload via NativePHP Camera/Gallery), Aktiv-Status, Links/Socials.
  - [ ] Stadt-Auswahl mit `search-cities`; falls nicht vorhanden → Inline „Stadt anlegen" (Phase 6).
  - [ ] **Duplikat-Schutz:** vor dem Anlegen `search-meetups` (Name + Stadt) — bestehendes Meetup vorschlagen statt Duplikat.
- [ ] **4.2** „Meetup bearbeiten" für eigene Meetups (`list-my-meetups` → `update-meetup`), nur für Ersteller/Admin.
- [ ] **4.3** „Zu meinen Meetups hinzufügen" (`add-meetup-to-mine`) direkt aus dem Meetup-Detail (Beitreten/Folgen-Button).
- [ ] **4.4** Tab „Meine" im Meetups-Index aufwerten: Edit-Affordance pro Karte, Status-Badges (Aktiv/Inaktiv), „Neu anlegen"-CTA wenn leer.
- [ ] **4.5** Markdown-Editor/Preview-Toggle für Beschreibung (Reuse `RendersMarkdown`).
- [ ] **4.6** Logo-Upload-Pipeline: Bild wählen/aufnehmen → zuschneiden → an Portal senden; Offline-Fallback.
- [ ] **4.7** Erfolgs-/Fehler-Feedback: Flux-Toast + Haptik; 422-Feldfehler an die Form-Felder mappen.
- [ ] **4.8** Tests: Create (Erfolg/Duplikat/422), Update (Ersteller-Gate), Add-to-mine, leerer-Tab-CTA.

**Akzeptanz:** Verbundener Nutzer legt ein Meetup an, sieht es im „Meine"-Tab, bearbeitet es, und ein zweites Konto kann es zu seinen hinzufügen.

---

## Phase 5 — Meetup-Termine: CREATE & EDIT 📅

- [ ] **5.1** „Termin anlegen" für eigene Meetups (`create-meetup-event`): Datum/Uhrzeit (Flux date-picker), Ort/Venue (Suche/Anlegen), Beschreibung, Online-Link.
- [ ] **5.2** „Termin bearbeiten" (`list-my-meetup-events` → `update-meetup-event`) inkl. Absagen/Inaktiv setzen.
- [ ] **5.3** Termin-Verwaltung im Meetup-Detail: kommende vs. vergangene Termine, Inline-Edit-Affordance.
- [ ] **5.4** Wiederkehrende Termine als Komfort (z. B. „jeden 3. Donnerstag") → mehrere Einzeltermine erzeugen. *(Stretch — sauber markieren.)*
- [ ] **5.5** Termin-Detail-Seite (eigene Route) mit Add-to-Calendar (Phase 8.2), Karte, Teilnehmen.
- [ ] **5.6** Tests: Termin-Create/Update, Ort-Verknüpfung, Datums-Validierung (keine Vergangenheit beim Anlegen).

**Akzeptanz:** Aus einem eigenen Meetup heraus lässt sich ein Termin anlegen, der sofort im Termine-Tab erscheint.

---

## Phase 6 — Orte & Städte: CREATE & EDIT 🏙️

- [ ] **6.1** Venue anlegen/bearbeiten (`create/update-venue`): Name, Adresse, Stadt, Geo-Koordinaten (Karten-Picker), Typ.
- [ ] **6.2** Stadt anlegen/bearbeiten (`create/update-city`): Name, Land, Geo — inline aus Meetup-/Venue-Flow erreichbar.
- [ ] **6.3** Karten-Picker-Component (Leaflet, Reuse aus `map`-Seite): Pin setzen → Lat/Lng in die Form.
- [ ] **6.4** „Meine Orte/Städte" im „Meine Inhalte"-Hub (`list-my-venues`, `list-my-cities`).
- [ ] **6.5** Tests: Venue/City Create+Update, Geo-Picker liefert Koordinaten, Verknüpfung Meetup↔Venue↔City.

**Akzeptanz:** Beim Meetup-/Termin-Anlegen kann eine neue Stadt/Venue ohne Kontextwechsel mitangelegt werden.

---

## Phase 7 — Kurse & Referenten: CREATE & EDIT 🎓

> Für die Bildungs-Schiene; nutzt die vorhandenen Kurs-/Referenten-Seiten.

- [ ] **7.1** Referenten-Profil anlegen/bearbeiten (`create/update-lecturer`): Name, Bio (Markdown), Avatar, Links.
- [ ] **7.2** Kurs anlegen/bearbeiten (`create/update-course`): Titel, Beschreibung, Referent (Suche/Anlegen), Kategorie.
- [ ] **7.3** Kurs-Event anlegen/bearbeiten (`create/update-course-event`): Datum, Ort/Online, Anmeldung.
- [ ] **7.4** „Meine Kurse/Referenten" im Hub; Edit aus Kurs-/Referenten-Detail.
- [ ] **7.5** Tests: Lecturer/Course/CourseEvent CRUD + Verknüpfungen.

**Akzeptanz:** Ein Referent legt einen Kurs samt Termin an, der unter Kurse erscheint.

---

## Phase 8 — Zusatz-Features (selbst ausgedacht) 🚀

> Auswahl nach Aufwand/Wirkung; jede Zeile ist optional einzeln abhakbar. MVP-Set fett.

- [ ] **8.1 Favoriten/Merkliste** — Meetups, Termine, Kurse lokal (SQLite) bookmarken; eigener „Gemerkt"-Filter.
- [ ] **8.2 Add-to-Calendar / ICS** — Termin in den nativen Kalender exportieren (NativePHP) oder `.ics` teilen.
- [ ] **8.3 Teilen** — Meetup/Termin/Kurs via nativem Share-Sheet (Deep-Link ins Portal/in die App).
- [ ] **8.4 QR-Code** — pro Meetup/Termin QR generieren (Anreise/Beitritt) + In-App QR-Scanner (NativePHP Scanner) zum Beitreten.
- [ ] **8.5 RSVP/Teilnehmen** — „Ich komme"-Status für Termine (falls Portal-API vorhanden; sonst als lokaler Reminder).
- [ ] **8.6 Push-Benachrichtigungen** — Reminder vor Terminen eigener/gemerkter Meetups; neue Termine in der Region (NativePHP Push, Permission-Priming aus Phase 3.5).
- [ ] **8.7 „In der Nähe"** — Geo-Sortierung von Meetups/Terminen nach Gerätestandort (NativePHP Location, opt-in).
- [ ] **8.8 Profil-Ausbau** — Avatar, Bio, eigene Beiträge, Verbindungsstatus, „Abmelden/Token erneuern".
- [ ] **8.9 Pull-to-Refresh & Cache-Status** — einheitliche Refresh-Geste + sichtbarer „zuletzt aktualisiert / offline"-Hinweis (baut auf `servedStaleData`).
- [ ] **8.10 Widget/Quick-Action** — Android-Shortcut „Nächster Termin". *(Stretch.)*
- [ ] **8.11 Mehrsprachigkeit prüfen** — alle neuen Strings via `__()` + `lang/`-Dateien (de/en).

**Akzeptanz (MVP-Set):** 8.1, 8.2, 8.3, 8.6, 8.9 funktionieren und sind getestet.

---

## Phase 9 — QA, Tests & Release ✅

- [ ] **9.1** Pest-Browser-Smoke-Test über alle Seiten/Flows (keine JS-Konsolenfehler).
- [ ] **9.2** Feature-Test-Abdeckung für jeden CRUD-Pfad (Create/Edit/Auth-Gate/422/Offline).
- [ ] **9.3** Larastan-Level halten/erhöhen; `vendor/bin/pint` sauber.
- [ ] **9.4** Manuelles Geräte-QA auf echtem Android (Hot-Reload-Loop), Screenshots der Kern-Flows.
- [ ] **9.5** Accessibility-Pass (Touch-Target ≥ 44px, Kontrast, Focus-Order, Screenreader-Labels).
- [ ] **9.6** Performance: Cold-Start, Listen-Scroll, Cache-Treffer prüfen.
- [ ] **9.7** Version-Bump auf `1.1.0` (`config/nativephp.php`), Changelog/Release-Notes.
- [ ] **9.8** Signierte APK + GPG-Manifest via GitHub-Release (Skill `/github-release`, Amber-Style).

**Definition of Done v1.1.0:** Verbundener Nutzer kann Meetups + Termine anlegen/bearbeiten, eigene zu „Meine" hinzufügen; Onboarding ist mehrstufig und führt zur optionalen Portal-Verbindung; Navigation hat FAB + globale Suche; Design erfüllt das AAA-Niveau aus Phase 1; alle Pfade getestet; signierter Release liegt vor.

---

## Empfohlene Reihenfolge

`Phase 0 → 1 → 2 → 4 → 5 → 3 → 6 → 7 → 8 → 9`

Begründung: Erst Schreib-Fundament (0) und Design/Navi (1, 2), weil alles darauf aufsetzt.
Dann der Kern-Wunsch „Meine Meetups" CRUD (4) + Termine (5) — der größte Nutzerwert.
Onboarding (3) danach, weil es die Portal-Verbindung & Push aus Phase 4/8 voraussetzt, um sie sinnvoll zu bewerben. Orte/Kurse (6, 7) erweitern, Zusatzfeatures (8) und Release (9) schließen ab.

---

## Offene Fragen / Klären

- [ ] **`add-to-mine` ohne REST-Route** (Phase 4.3): das Portal bietet es nur als MCP-Tool, `routes/api.php` hat keinen Endpoint und der `MeetupController` keine attach/sync-Methode. → Portal-Route ergänzen (Branch `feature/mobile-auth`) oder Feature 4.3 streichen.
- [ ] Unterstützt die Portal-API **Datei-/Logo-Upload** (multipart) für Meetups/Referenten? → vor Phase 4.6 verifizieren. *(Hinweis: `StoreMeetupRequest` kennt aktuell KEIN logo-Feld — Portal müsste erweitert werden.)*
- [ ] Gibt es eine **RSVP/Teilnehmen-Route** im Portal (Phase 8.5)? → sonst lokaler Reminder.
- [ ] Status von `GetMemberMeetupsRequest` (401-Problem in `PortalApi::memberMeetups`) — ist die Sanctum-Route inzwischen live?
- [ ] Push-Infrastruktur: nutzt das Portal einen eigenen Push-Provider oder rein clientseitige lokale Notifications?

## Entscheidungs-Log

| Datum | Entscheidung | Begründung |
|------|--------------|-----------|
| 2026-06-13 | v1.1.0 = read→write-Wende; Schreib-Layer (`PortalWriter`) gespiegelt zu `PortalApi` | Portal-Schreib-API existiert bereits; saubere Trennung Lesen/Schreiben |
| 2026-06-13 | REST-Writes erwarten **IDs** (`city_id`, `meetup_id`, `country_id`), nicht Namen | Die Namen-Auflösung macht nur das MCP-Tool-Layer; die App muss Stadt/Land/Meetup also vorab über die Such-/Listen-Endpunkte zu IDs auflösen (relevant für die Form-Flows ab Phase 4) |
| 2026-06-13 | Write-Requests sind **body-only**, DTO-Mapping + 422-Handling zentral im `PortalWriter` (0.2) | `store/update`-Resources des Portals sind nicht deckungsgleich mit den Lese-DTOs (CityResource ohne `country`, MeetupEventResource ohne `meetup`-Objekt) → fragiles Mapping in den Requests vermieden |
| 2026-06-13 | `add-to-mine` als Blocker dokumentiert statt kaputten Request anzulegen | Keine REST-Route im Portal vorhanden; muss serverseitig geklärt werden |
| 2026-06-13 | `PortalWriter` invalidiert nur den **frischen** Cache-Key (Stale-Kopie bleibt), und nur den **parameterlosen** Basis-Key je Endpunkt | Cache-Driver ohne Tag-Support (kein Redis); Stale ist das Offline-Netz und darf nicht verschwinden. Varianten mit Query-Parametern (z. B. `meetup-events` nach Datum) werden in den Form-Flows ab Phase 4 bei Bedarf gezielt nachgezogen |
| 2026-06-13 | **Optimistisches Cache-Schreiben** des frischen DTOs in 0.2 weggelassen — nur Invalidierung | Lese-DTOs (`my-meetups` im `data`-Wrapper, `map-meetups` anderes Shape) sind nicht deckungsgleich mit den Write-Resources; ein halb-korrekt gemergter Cache widerspricht dem „offline nie falsche Daten"-Prinzip. Invalidierung erzwingt sauberen Refetch |
| 2026-06-13 | Writes laufen mit `connector->tries = 1` (kein Auto-Retry), anders als Reads | Ein wiederholter POST nach Server-/422-Fehler legt Duplikate an. Transiente Netzfehler fängt der Aufrufer über `WriteResult::networkFailure` + manuellen Retry ab |
| 2026-06-13 | Haptik **zweigleisig**: clientseitig `window.haptic`/`$haptic()` via Web-Vibration-API + serverseitig `Device::vibrate()` | `window.Native` ist im WebView nur ein Event-Bus (on/off/dispatch), kein API-Aufruf-Kanal — native Calls laufen über `nativephp_call` (PHP). Tap-Feedback braucht aber sofortige Reaktion ohne Server-Round-Trip → `navigator.vibrate()` (Android-WebView, Hauptziel). Aktions-Bestätigung (Retry-Ergebnis) läuft testbar über die PHP-Facade |
| 2026-06-13 | Design-Tokens als **semantische** Tailwind-v4-`@theme`-Keys (`rounded-card`, `shadow-card`, `ease-spring` …) statt roher rounded-2xl/shadow-sm-Streuung | Ein Token-Set als Single Source of Truth; Cards/Sheets/Tiles teilen Radius/Elevation/Motion. Erleichtert die folgenden CRUD-Screens (Phase 4–7), die nur noch die Tokens referenzieren |
| 2026-06-13 | App bleibt **dark-first**; Elevation im Dark-Mode via Border/Ring statt Schatten (`dark:shadow-none`) | Bitcoin-/EINUNDZWANZIG-Brand ist dunkel; Schatten sind auf Zinc-950 kaum sichtbar. Der Light-Pfad bleibt über `dark:`-Varianten konsistent und AA-kontrastiert |
| 2026-06-13 | Visueller Screenshot-Abnahme (Phase-1-Akzeptanz) **auf Phase 9.4 verschoben** | Echte AAA-Optik zeigt sich nur im nativen Build (Emulator/Gerät via adb), nicht im Web-Renderer (würde zudem die echte Portal-API treffen). Implementierung ist durch 181 grüne Tests + sauberen Vite-Build abgesichert |

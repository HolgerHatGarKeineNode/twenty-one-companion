# Einundzwanzig Mobile App — Umsetzungsplan

> **Arbeitsanweisung für Claude:** Dieser Plan ist die Single Source of Truth.
> Bei Session-Start: diesen Plan lesen, erste unerledigte Checkbox `[ ]` finden, dort weitermachen.
> Erledigte Punkte sofort auf `[x]` setzen. Neue Erkenntnisse/Entscheidungen unten in
> „Entscheidungs-Log" bzw. „Offene Fragen" nachtragen. Vor jedem Portal-Eingriff:
> Konventionen im Schwesterprojekt prüfen.

## Kontext & feste Entscheidungen

- **Diese App** (`/home/user/Code/einundzwanzig-mobile-app`): Laravel 13 + NativePHP Mobile v3 + Livewire 4 + Flux Pro. App-ID `space.einundzwanzig.mobile`. Installiert, aber ungenutzt: Saloon v4, spatie/laravel-data, NativePHP-Plugins `network`/`dialog`/`share`/`browser` (⚠️ noch nicht registriert), laravel-lang.
- **Portal** (`/home/user/Code/einundzwanzig-app`, portal.einundzwanzig.space): Laravel 13, Livewire 4, Flux Pro, Sanctum (`HasApiTokens` am User), bestehende API unter `/api` (Scramble-Doku unter `/docs/api`). Wir dürfen und werden das Portal erweitern.
- **Auth-Flow (entschieden):** Deep-Link-Flow. App öffnet `portal.../auth/mobile` im In-App-Browser → User loggt sich ein → Portal erzeugt Sanctum Personal Access Token → Redirect `einundzwanzig://auth?token=...` → App speichert Token in SecureStorage → alle API-Calls mit `Authorization: Bearer`.
- **Login-Methoden (entschieden):** Nur **Lightning (LNURL-auth)** und **Nostr** — exakt wie der bestehende Portal-Login. Kein E-Mail/Passwort, keine eigene Registrierung in der App.
- **Module v1 (entschieden):** Meetups & Termine, Kurse & Referenten, Orte & Karte. **Nicht** in v1: Library, Podcasts, BitcoinEvents, ProjectProposals, Teams.
- **v1 ist read-only + Auth.** Schreibfunktionen (Events anlegen/bearbeiten) kommen in v2.
- App baut **kein eigenes Login-Frontend** — Login-UI lebt im Portal (neue mobile Views).

---

## Phase 1 — Portal-Erweiterung: Mobile Auth-Flow

Arbeitsverzeichnis: `/home/user/Code/einundzwanzig-app` (eigener Branch, z. B. `feature/mobile-auth`).

- [x] 1.1 Bestehenden Login-Flow analysieren: LNURL (k1-Challenge → Wallet-Callback → `LoginKey`-Row → `wire:poll` → Completion-Route) und Nostr (signiertes Kind-22242-Event mit Session-Challenge via `window.nostr`).
- [x] 1.2 Route `GET /auth/mobile` (Livewire-View `auth.mobile-login`): Lightning-QR + **Nostr via NIP-55-Signer (Amber)** statt `window.nostr` (im In-App-Browser gibt es keine Extensions). `redirect_uri`-Whitelist (`einundzwanzig://auth`), `device_name`-Param. Beide Methoden teilen sich ein k1; Amber ruft `GET /api/nostr-login-callback?k1=…&event=` (Event-Verifikation in `App\Support\NostrLogin`, geteilt mit Desktop-Login).
- [x] 1.3 Token-Erzeugung: `GET /auth/mobile/complete/{k1}` prüft `LoginKey` (5 min TTL), erzeugt Token (Name = Gerätename, ersetzt alte Tokens gleichen Namens), Redirect auf `einundzwanzig://auth?token=…`. Flow-State (`mobile_auth`) liegt in der Session der Login-Seite.
- [x] 1.4 Eingeloggte Session: Bestätigungsseite („Verbinden" / „Mit anderem Konto anmelden") → `POST /auth/mobile/confirm`.
- [x] 1.5 Sicherheit: `throttle:30,1` auf allen neuen Routen, Redirect-Whitelist hart kodiert im Controller, Token-Replacement pro Gerät. Abilities: Standard `['*']` — die API prüft derzeit keine Abilities, granulare Scopes wären nur kosmetisch (siehe Entscheidungs-Log).
- [x] 1.6 `GET /api/user` (auth:sanctum, `Api\UserController`): id, name, email, nostr, is_lecturer, is_leader, avatar.
- [x] 1.7 Token-Verwaltung existiert bereits: `resources/views/livewire/settings/api-tokens.blade.php`.
- [x] 1.8 Pest-Tests: `tests/Feature/Auth/MobileAuthTest.php` (10 Tests: Whitelist, Callback-Verifikation, falsche Challenge, Token-Ausgabe + API-Nutzung, Token-Replacement, Confirm-Screen, /api/user). Alle 27 Auth-Tests grün.
- [x] 1.9 Pint ok, committet auf `feature/mobile-auth` (07169df). Deployment macht der User.
- [x] 1.10 End-to-End-Test mit Amber gegen das LIVE-Portal — **erfolgreich** (2026-06-11 abends): App → `Browser::inApp` → portal.../auth/mobile → Amber signiert → pfadbasierter Callback `/auth/mobile/signed/{k1}/{event}` (Commit 4aba151; Amber verwirft Query-Strings!) → Bridge-Seite erzeugt Token → Deep Link → App speichert in SecureStorage → Home zeigt Portal-Profil. Lightning bleibt ungetestet (kein Wallet).
- [ ] 1.11 UX-Härtung Rücksprung via **verifizierte App Links auf portal.einundzwanzig.space** (User-Entscheidung 2026-06-11). Umgesetzt: Portal `7531f28` (assetlinks.json mit Debug-Cert, `GET /app/auth` Handoff + Button-Fallback, `POST /api/mobile/token` Event→Token-Exchange, complete/confirm/signedCallback → Handoff-URL) + App `a271f7c` (`NATIVEPHP_DEEPLINK_HOST`, App-Routen `/app/auth` + `/auth/mobile/signed/{payload}`, Browser-Fallback für fremde Portal-Pfade). ⚠️ Offen: Portal-Deploy durch User, App-Link-Verifikation prüfen (`adb shell pm get-app-links`), E2E-Retest. ⚠️ Vor Store-Release: Release-Cert-Fingerprint in assetlinks.json ergänzen! Hinweis: NativePHP claimt immer den GANZEN Host (`pathPrefix="/"`).
- [x] 1.12 Amber-Kopplung nach Spec via **NIP-46/window.nostr** (User-Entscheidung: robuster als NIP-55-Intents — die waren langsam, crashten die WebView mit langen Callback-URLs und legten keine App-Connection in Amber an). Portal-Commit `c30f193`: Mobile-Login nutzt jetzt denselben `openNostrLogin`-Pfad wie der Desktop-Login (`window.nostr.signEvent` via window.nostr.js/CDN, persistente Bunker-Connection mit Permissions) → `nostrLoggedIn`-Listener → `LoginKey` → Complete-Route → Token → `/app/auth`-Handoff (verifizierter App Link, kein Chrome-Prompt). NIP-55-Routen bleiben serverseitig als Fallback bestehen.
- [x] 1.13 NIP-46/window.nostr verworfen: Single-Device-Pairing-Race (Browser-Tab im Hintergrund während Amber-Approve verpasst das `connect`-ACK übers Relay). User-Entscheidung: **Variante C (NIP-55) sauber bauen.**
- [x] 1.14 **Variante C sauber** umgesetzt (Portal `58c7e41`, App `628a791`): window.nostr aus mobile-login entfernt → Portal-Login-Seite ist Lightning-only. Eigene App-Buttons „Mit Nostr anmelden" / „Mit Lightning anmelden". Nostr-Flow: App → `Browser::inApp(portal/auth/mobile/nostr)` (headless Launcher-Seite) → feuert `nostrsigner:` via `window.location` (mit `category.BROWSABLE`!) → Amber signiert lokal (kein Relay) → Callback `/auth/mobile/signed/{k1}/{event}` → Token → `/app/auth`-App-Link → App. **Wichtige Erkenntnis:** `Browser::open(nostrsigner:)` direkt aus der App scheitert („malformed request"), weil der ACTION_VIEW-Intent ohne `category.BROWSABLE` in Ambers App-zu-App-Pfad landet — daher der Umweg über die Launcher-Seite (Chrome dispatcht mit BROWSABLE). Toter App-Code (signed-Route, exchangeSignedEvent, Fallback-Route) entfernt.
- [~] 1.15 E2E-Retest Variante C: **„malformed nostrsigner request"** debuggt. Root Cause aus Amber-v6.2.0-Quellcode (`IntentUtils.kt`): Amber routet `nostrsigner:`-Intents nach `Browser.EXTRA_APPLICATION_ID` — vorhanden → Web-Flow (Event aus URI), fehlt → App-zu-App-Flow (`type`/Event aus Intent-**Extras** → unsere URI wird abgelehnt). Browser setzen dieses Extra **nur bei User-Geste**, nicht bei Auto-Redirect. Mein Launcher feuerte `launchSigner()` automatisch beim Laden → keine Geste → malformed. In Runde 2 hat der User den Button getippt (Geste) → ging. URI-Encoding war NIE das Problem (JS- und PHP-Build byte-identisch verifiziert). Fix Portal `76894a6`: Auto-Fire entfernt, Launcher wartet auf Button-Tap „Mit Amber signieren". **UX-Konsequenz:** 2 Taps (App „Mit Nostr anmelden" → Launcher „Mit Amber signieren"). Einzeltap unmöglich, weil nur ein echter Browser (kein App-WebView) das Extra setzen kann und nur bei Geste.
- [~] 1.16 Amber-v6.2.0-Web-Flow tief debuggt (Quellcode-Analyse `IntentUtils.kt` + `MainActivity.kt`). Erkenntniskette:
  - Plain `nostrsigner:`-Navigation → Amber liest `type` aus Intent-**Extras** (nicht Query) → „malformed". User-Geste/Custom-Tab/voller-Browser alle malformed.
  - **Lösung Teil 1 (Portal `4fa4a84`):** Launcher feuert jetzt eine **`intent://`-URL** mit Event als Data + `S.type`/`S.callbackUrl`/`S.returnType`/`S.appName` als Extras → **Amber akzeptiert und zeigt den korrekten Sign-Dialog** („Einundzwanzig wants to authenticate to relay" + Permissions). Per DevTools-trusted-click verifiziert.
  - **Offener Blocker:** Nach „Accept" feuert Amber den **Callback nicht** (kein `/auth/mobile/signed`-Request). Ursache laut `MainActivity.kt`: Amber öffnet die `callbackUrl` nur wenn `callingPackage == null`; bei Start aus dem Browser war es im Emulator offenbar non-null → `setResult` (geht verloren, da kein `startActivityForResult`). Im Emulator + Amber v6.2.0 nicht abschließbar.
- [x] 1.17 **Kompletter Nostr-Flow im Emulator durchgespielt** — fast vollständig. Belegt: App „Mit Nostr" → Launcher → „Mit Amber signieren" (intent://-URL mit Extras) → Amber zeigt Sign-Dialog → Accept → Amber öffnet die `callbackUrl` (weil `callingPackage==null` bei Browser-Start) → Portal verifiziert ✓. Zwei Bugs unterwegs gefunden+gefixt:
  - **TTL-Race:** Manuelles Testen (Bauen→Tap→Replay) überschritt die 300s-`created_at`-TTL. Im echten Flow erzeugt JS das Event erst beim Button-Tap → kein Problem.
  - **302→App-Link feuert nicht:** Chrome folgt dem 302 von `/signed`→`/app/auth` intern, ohne den App-Link-Intent zu dispatchen → Handoff-Seite blieb in Chrome, Token kam nie an. **Fix (Portal `76787a1`):** `/signed`-Callback rendert die Handoff-Seite (`einundzwanzig://auth?token=`-Button) jetzt **direkt**. Isolationstest bestätigte: App lädt `/app/auth?token=` korrekt und speichert (Deep-Link-Routing ok).
- [~] 1.18 Browser-Handoff scheitert im Emulator: Amber öffnet den Callback in einem **signer-eigenen Custom Tab**, der die Handoff-Seite nicht zuverlässig anzeigt (auch mit `Browser::open`/vollem Chrome landet man nach Amber wieder im Custom Tab mit der Launcher-Seite). Portal-Verifikation + Token-Ausgabe sind dabei nachweislich korrekt (curl-Replay der echten Callback-URL → 200 Handoff mit Token).
  - **Lösung: Browser-Handoff komplett umgangen** — Callback ist jetzt das **App-Custom-Scheme** `einundzwanzig://signed/{k1}/`. Amber öffnet es nach dem Signieren **direkt** (ACTION_VIEW → App, kein Browser, kein Prompt); die App tauscht das Event per `POST /api/mobile/token` gegen ein Token (`PortalSignedEventController` + `PortalAuth::exchangeSignedEvent`, App `3cf9600`; Portal-Launcher `54c959d`). **Isoliert verifiziert:** App empfängt den Deep-Link, lädt die lange `/signed/{event}`-URL **ohne SIGILL** (auf `-gpu host`), ruft den Exchange. (SIGILL-Crashes früher waren der swiftshader-GPU-Bug, nicht die URL-Länge.)
- [x] 1.19 E2E mit Custom-Scheme-Callback: Amber feuert `einundzwanzig://signed/...` korrekt an die App (verifiziert, `cmp=space.einundzwanzig.mobile/...MainActivity`). Emulator-Symptome (Process-Killing `cch LAST`, Kaltstart-Race) waren real, aber **nicht die Hauptursache** — siehe 1.20.
- [x] 1.20 **E2E auf echtem Gerät (Pixel 8, GrapheneOS, Android 16) — ERFOLGREICH** (2026-06-12). Root Cause des „nichts passiert nach Amber-Accept" (Gerät UND Emulator): `MainActivity` hatte `android:launchMode="singleTop"`. Ambers Callback-Intent startete dadurch eine **zweite Wegwerf-MainActivity in Ambers Task** (singleTop greift nur, wenn die Activity oben im Ziel-Task steht — bei uns lag der Custom Tab darüber); die echte Instanz bekam nie `onNewIntent`. **Fix: `launchMode="singleTask"`** in `nativephp/android/app/src/main/AndroidManifest.xml` → Intent geht per `onNewIntent` an die laufende Instanz, Custom Tab wird automatisch abgeräumt → `/signed` lädt → `POST /api/mobile/token` → Token in SecureStorage → Home zeigt „Mit dem Portal verbunden". Nebenbefund: App-Link-Verifikation `portal.einundzwanzig.space: verified` auf dem Gerät bestätigt (1.11). Hinweis Amber v6.2.0+GrapheneOS: Chooser „Öffnen mit Amber/Memely" erschien (kein Default gesetzt), Amber-PIN nötig.
- [x] 1.21 **launchMode-Fix persistent gemacht** (2026-06-12): `App\Services\AndroidManifestPatcher` (idempotenter singleTop→singleTask-Patch) + Console-Event-Hooks im `AppServiceProvider` — `CommandFinished(native:install)` patcht direkt nach dem Re-Scaffold, `CommandStarting(native:run|native:package|native:watch)` als Sicherheitsnetz vor jedem Build (real verifiziert: Patch greift vor der Argument-Validierung von native:run). Manueller Befehl: `php artisan app:patch-android-manifest`. 9 Pest-Tests (`tests/Feature/AndroidManifestPatcherTest.php`), Larastan grün. ⚠️ Wissen: Laravel mappt CommandStarting via Symfony ConsoleEvents — bei `--help` läuft das `help`-Kommando, Hook feuert da nicht (irrelevant, --help baut nichts). Upstream-PR an NativePHP optional. Weiterhin offen: Lightning-Login ungetestet (kein Wallet).

## Phase 2 — App: Deep Link, SecureStorage, Login-Flow

Arbeitsverzeichnis: dieses Projekt.

- [x] 2.1 NativePHP-Plugins registrieren — war bereits erledigt (Phase-1-Arbeit): alle 5 Plugins (`browser`, `dialog`, `network`, `secure-storage`, `share`) sind via Composer installiert UND in `app/Providers/NativeServiceProvider.php` registriert (`php artisan native:plugin:list` bestätigt 11 Bridge Functions). Memory war veraltet.
- [x] 2.2 Deep-Link-Schema `einundzwanzig://` in NativePHP konfigurieren (`config/nativephp.php` / `.env` `NATIVEPHP_DEEPLINK_SCHEME`); Handler für `einundzwanzig://auth?token=...` bauen.
- [x] 2.2b SecureStorage-Premium-Plugin: war bereits installiert (`nativephp/mobile-secure-storage` 1.0.1) und registriert — in Phase 1 nachweislich im Einsatz (Token-Speicherung E2E auf dem Pixel 8 verifiziert).
- [x] 2.3 `App\Services\PortalAuth`: Token aus Deep Link → SecureStorage (existierte aus Phase 1). **Neu (2026-06-12):** `logout()` revoked den Token zusätzlich am Portal (`DELETE /api/mobile/token`, Portal-Commit `f9b3428`, auth:sanctum, löscht nur den anfragenden Token) — best effort, Offline-Logout löscht trotzdem lokal. Connect-Komponente nutzt `logout()` statt nur `forgetToken()`.
- [x] 2.4 Login-Screen: existiert als `livewire/portal/connect` auf Home — zwei Buttons („Mit Nostr anmelden" / „Mit Lightning anmelden", Entscheidung 1.14 ersetzt den Ein-Button-Plan). `PORTAL_URL`-Env via `config/services.php → services.portal.url` vorhanden.
- [x] 2.5 Auth-State: `PortalAuth` ist die zentrale State-Quelle (`hasToken()`/`profile()`), Connect-Komponente rendert verbunden vs. Gast; Gast-Modus bleibt voll nutzbar (öffentliche API). **Neu:** Profil wird nach erfolgreichem `GET /api/user` lokal gecacht (30 Tage TTL) und offline aus dem Cache serviert; 401 verwirft Token + Cache. Eigene Middleware unnötig — es gibt keine geschützten App-Routen in v1.
- [x] 2.6 Feature-Tests: `tests/Feature/PortalAuthTest.php` (16 Tests: Deep-Link/App-Link-Callback, Signed-Event-Exchange, Login-Buttons, Profil-Fetch/-Cache/-Offline, Logout mit/ohne Netz). HTTP via `Http::fake()` (Saloon kommt erst in Phase 3). Larastan + Pint grün, 62 Tests gesamt.

## Phase 3 — App: Saloon API-Client + DTOs

- [x] 3.1 Saloon-Connector `app/Http/Integrations/Portal/PortalConnector.php`: Base-URL `services.portal.url` + `/api`, Bearer via `defaultAuth()` aus `PortalAuth`/SecureStorage (nur wenn Token vorhanden), AcceptsJson, Timeouts 10s/15s, Retry `tries=2` mit Backoff und `throwOnMaxTries=false` (Fehler-Response kommt zurück statt Exception).
- [x] 3.2 12 Request-Klassen unter `app/Http/Integrations/Portal/Requests/` mit `createDtoFromResponse()` + statischem `collectData()` (geteiltes Mapping für den Cache-Pfad). ⚠️ Abweichungen: `GET /api/my-courses` existiert im Portal NICHT → stattdessen `GetMyCourseEventsRequest` (`GET /api/course-events`, eigene Kurs-Events) bzw. `GET /api/courses?user_id=…`. `GET /api/meetup` (beigetretene Meetups) liegt im Portal in der öffentlichen Route-Gruppe ohne `auth:sanctum` und prüft `$request->user()` über den web-Guard → liefert mit Bearer-Token immer 401; `GetMemberMeetupsRequest` existiert, braucht aber eine Portal-Anpassung (siehe Offene Fragen).
- [x] 3.3 13 DTOs unter `app/Data/Portal/` (MapMeetup+NextEvent, MeetupEvent+EventMeetup, Meetup, MemberMeetup, Course, CourseEvent, Lecturer, City, Country, Venue, BtcMapCommunity, UserProfile) — Felder gegen Controller/Resources des Portals UND die Live-API verifiziert. Besonderheiten: `/api/meetup-events` liefert literale `meetup.*`-Punkt-Schlüssel → `MeetupEventData::prepareForPipeline()` schachtelt um; gemischte Datumsformate über `config/data.php` `date_format`-Array gelöst; in Kurs-Events sind Kurs/Venue nur `{id,name}` → Optional-Felder in CourseData/VenueData/CountryData.
- [x] 3.4 Caching-Layer `App\Services\PortalApi`: zweistufiger Cache (frisch mit TTL: Stammdaten 1 d, Termine 1 h, eigene Daten 15 min + Stale-Kopie forever) auf dem database-Store; bei Offline (network-Plugin `Network::status()`), Netzwerkfehlern oder 5xx wird die Stale-Kopie serviert. Gecacht wird rohes JSON, DTO-Mapping erst beim Lesen. `my*`-Methoden liefern ohne Token leere Collections ohne Request (verhindert auch Stale-Leaks nach Logout).
- [x] 3.5 `tests/Feature/PortalApiTest.php` (13 Tests, Saloon `MockClient::global`): DTO-Mapping aller Kern-Endpunkte (inkl. Punkt-Schlüssel, data-Wrapper, Datums-/Bool-Casts), Bearer-Header mit/ohne Token, Query-Params, Cache-Hit, Stale-Fallback bei 5xx (inkl. Retry), Offline-Pfad, Leer-Verhalten ohne Token. Gesamt: 75 Tests grün, Larastan + Pint sauber.

## Phase 4 — App: Modul Meetups & Termine

- [x] 4.1 Platzhalter-View `meetups.blade.php` ersetzt durch Livewire-4-SFC-Pages (`resources/views/pages/meetups/⚡index|⚡show`, `pages/events/⚡index`; `Route::livewire` + `#[Layout('layouts::mobile', […])]`). Bottom-Nav um „Termine"-Tab erweitert (grid-cols-4), Home um Termine-Karte.
- [x] 4.2 Meetup-Liste (`/api/meetups` mit withIntro+withLogos, ein Cache-Eintrag für Liste+Detail): Suche Name/Stadt + Länderfilter (clientseitig, da API alles liefert). Detail per Slug — aus `portalLink` abgeleitet (`HasPortalLink`-Trait; API liefert keinen slug): Logo, Intro (Markdown), Links (Telegram/Website/X/Nostr/Signal/SimpleX), „Nächster Termin"-Karte, „Weitere Termine" (Monats-Events ohne next_event-Duplikat). Unbekannter Slug → freundlicher Fallback.
- [x] 4.3 Termine-Seite mit Monatsnavigation: ⚠️ `/api/meetup-events` OHNE Datum liefert ALLE (auch vergangene) Events → immer mit Datums-Param (aktueller Monat = ab heute, andere = Monatserster; API filtert bis Monatsende). Gruppiert nach Tag, Detail als Flux-Modal (kein Event-Detail-Endpunkt/keine Event-ID in der API).
- [x] 4.4 „Teilen" via `Share::url()` auf Meetup-Detail + im Termin-Modal. iCal-Export nicht umgesetzt (Plan sagt „oder"; bei Bedarf v2 — bräuchte file-Plugin-Export). Externe Links öffnen via `Browser::open` mit http(s)-Whitelist (`openLink`-Action).
- [x] 4.5 „Meine Meetups" als eigener Tab (flux:tabs segmented, nur bei Token sichtbar), Quelle `/api/my-meetups`. `GET /api/meetup` (beigetretene) bleibt blockiert bis Portal-Fix (siehe Offene Fragen).
- [x] 4.6 17 neue Pest-Tests (`MeetupsPageTest`, `EventsPageTest`): Livewire-Komponententests + HTTP-Smoke-Tests, Saloon `MockClient::global`, Share/Browser-Facade-Mocks. Geteilte Fixtures (`mapMeetupFixture` etc. mit `$overrides`) nach `tests/Pest.php` verschoben (Ladereihenfolge!). Veralteter Platzhalter-Test in `MobileShellTest` ersetzt durch stärkeren Smoke-Test in `MeetupsPageTest`. Echte Browser-Tests bräuchten `pest-plugin-browser` (nicht installiert = Dependency-Änderung → nur mit Freigabe). 91 Tests grün, Larastan + Pint sauber, `yarn run build` ok.

## Phase 5 — App: Modul Kurse & Referenten

- [ ] 5.1 Kurs-Liste + Kurs-Detail (inkl. kommender Kurs-Events).
- [ ] 5.2 Referenten-Liste + Profil (Avatar, Nostr, Kurse des Referenten).
- [ ] 5.3 Eingeloggt als Lecturer: „Meine Kurse" read-only Übersicht.
- [ ] 5.4 Tests wie in Phase 4.

## Phase 6 — App: Modul Orte & Karte

- [ ] 6.1 Kartenlösung wählen (Leaflet + OSM-Tiles im WebView ist naheliegend; Entscheidung dokumentieren).
- [ ] 6.2 Karte mit Meetups (`/api/meetups` Map-Format) und BTC-Map-Communities; Marker → Meetup-Detail.
- [ ] 6.3 Städte-/Venue-Verzeichnis als Liste (Cities, Venues).
- [ ] 6.4 Tests.

## Phase 7 — Polish & Release-Vorbereitung

- [ ] 7.1 Navigation/Shell finalisieren (Tab-Bar: Meetups / Termine / Karte / Kurse / Profil), Dark Mode, Einundzwanzig-Branding (Assets aus Schwesterprojekt übernehmen).
- [ ] 7.2 Profil-Screen: Portal-Profildaten, Token-/Logout-Verwaltung, App-Version.
- [ ] 7.3 Fehler-/Offline-Zustände sauber (dialog-Plugin für Fehler, leere States).
- [ ] 7.4 Lokalisierung de/en mit laravel-lang.
- [ ] 7.5 Android-Release-Build laut `docs/nativephp-ausfuehrungsplan.md` (Keystore, .aab) — iOS später (macOS nötig).

---

## v2-Kandidaten (noch nicht entschieden)

- [ ] Schreibfunktionen: Meetup-Events + Kurs-Events anlegen/bearbeiten (Sanctum-Endpunkte existieren schon).
- [ ] Push-Notifications (NativePHP push) für neue Events der eigenen Meetups — bräuchte Portal-Erweiterung (Device-Registrierung + Versand).
- [ ] Deep Links in Inhalte (`einundzwanzig://meetup/{slug}`) + Portal-seitige App-Links/AssetLinks fürs Teilen.
- [ ] Library & Podcasts (bräuchte neue API-Endpunkte im Portal).
- [ ] Meetup beitreten/verlassen aus der App (neuer Portal-Endpunkt).
- [ ] QR-Scanner (scanner-Plugin) z. B. für LNURL/Events.

## Offene Fragen an den User

- [ ] Soll die LNURL-/Nostr-Login-UI im Portal-Look bleiben oder ein abgespecktes „App-Connect"-Design bekommen?
- [ ] Token-Abilities: reicht v1 read-only-Scope, oder gleich volle `my-*`-Abilities vergeben, damit v2 ohne Re-Login geht?
- [ ] Karte: Leaflet/OSM ok, oder gibt es eine Präferenz (MapLibre, statische Liste zuerst)?
- [ ] Dev-Setup: läuft das Portal lokal (z. B. Herd/Sail), damit der Auth-Flow gegen localhost getestet werden kann?
- [ ] „Meine Meetups" (Phase 4.5): `GET /api/meetup` (beigetretene Meetups) braucht im Portal `auth:sanctum`, sonst 401 mit Bearer-Token. Portal-Route anpassen (kleine Änderung, Default-Verhalten für die Web-Session bleibt) — oder reicht `GET /api/my-meetups` (nur selbst ERSTELLTE Meetups)?

## Entscheidungs-Log

- 2026-06-12: **Simplify-Pass nach Phase 4:** Wiederverwendbare Bausteine für Phase 5/6: Blade-Komponenten `x-list-link-card` (Navigations-Karte mit Chevron, auch auf Home), `x-empty-state` (Icon+Heading+Slot, `min-height`-Prop), `x-meetup-avatar` (Logo-Fallback); Basisklasse `App\Livewire\PortalPage` mit `openLink()` (http(s)-Whitelist → `Browser::open`) für alle Modul-SFCs — als Basisklasse statt Trait, weil PHPStan Traits ohne analysierte Nutzer als unused meldet (SFC-Views liegen außerhalb der phpstan-paths). `MapMeetupData::socialLinks()` liefert die Link-Liste (Label ⇒ URL) jetzt im DTO; `slug()` ist memoisiert.
- 2026-06-12: **Phase 4 umgesetzt** (Meetups & Termine). Entscheidungen: Markdown aus dem Portal (intro/description) wird mit `html_input=strip` + Tag-Allowlist gerendert; **Anker-Tags werden zu Text gestrippt**, weil Links sonst die WebView ohne Zurück-Navigation kapern (saubere Lösung = JS-Bridge zu `Browser::open`, Phase-7-Kandidat). Statt Tailwind-Typography-Plugin (nicht installiert, Dependency) eigene `.markdown`-Styles in app.css. `APP_LOCALE=de` in .env gesetzt, damit `translatedFormat()` deutsche Monats-/Wochentagsnamen liefert (volle l10n bleibt 7.4). Event-Identität: API liefert keine Event-IDs in `/api/meetup-events` → Modal-Auswahl über Index der sortierten Collection.
- 2026-06-12: **Phase 3 umgesetzt** (Saloon-Connector, 12 Requests, 13 DTOs, PortalApi-Caching, 13 Tests). Erkenntnisse: `/api/my-courses` existiert nicht (Ersatz: `/api/course-events` + `/api/courses?user_id`); `/api/meetup` ist faktisch session-only (kein `auth:sanctum`) → Offene Frage für Phase 4.5; `/api/meetup-events` liefert literale Punkt-Schlüssel (`meetup.name`); Datumsformate der API sind gemischt (ISO mit Mikrosekunden, `Y-m-d H:i`) → `date_format`-Array in `config/data.php`. Cache-Design: rohes JSON zweistufig (TTL + Stale forever), kein DTO-Serialisieren in den Cache.
- 2026-06-12: **Phase-1-Durchbruch auf echter Hardware (Pixel 8/GrapheneOS):** Nostr-Login via Amber läuft end-to-end. Root Cause aller „hängt im Custom Tab"-Symptome war `launchMode="singleTop"` der MainActivity (Wegwerf-Instanz in Ambers Task statt `onNewIntent` an die laufende App). Fix: `singleTask` im generierten Manifest (⚠️ ephemer, siehe 1.21). Emulator-Dev ist damit wieder praktikabel; Auth-E2E-Referenz bleibt das echte Gerät.
- 2026-06-11 (spät): **NIP-46-Pairing-Blocker auf Einzelgerät** (Punkt 1.13). Der gewünschte App-Connection-Dialog mit Permissions erscheint in Amber korrekt, aber das `connect`-ACK übers Relay verpufft, weil der Browser-Tab während des Amber-Approves im Hintergrund ist. Lösungsoptionen (User wählt):
  - **(A) Relay mit Replay/Retention** für den NIP-46-Handshake erzwingen (z. B. `wss://relay.nsec.app`) via `window.wnjParams.nostrConnectRelays` — wnj-Resubscribe nach Tab-Fokus könnte das ACK dann nachträglich erhalten. Kleinster Eingriff, muss aber auf Einzelgerät getestet werden.
  - **(B) bunker:// statt nostrconnect://**: User kopiert die bunker-URL aus Amber (Amber → Applications → Add) ins wnj-Feld. Umständlicher beim Setup, aber kein Foreground-Race; danach robuste Auto-Signatur.
  - **(C) Zurück zum NIP-55-Pfad**, aber sauber: Callback NUR im Browser verarbeiten (nie in die WebView laden → kein SIGILL), kurzer `/app/auth`-Handoff. Lief in Runde 2 end-to-end, nur ~1 min langsam und ohne persistente Connection.
  - Empfehlung: erst (A) testen (1 Zeile Config), bei Fehlschlag (B) als verlässlichen Fallback anbieten.
- 2026-06-11: Mobile-Nostr-Login via **NIP-55/Amber** (Intent-URL `nostrsigner:` + Server-Callback) statt `window.nostr` — im In-App-Browser gibt es keine Browser-Extensions. Testgerät hat Amber installiert; kein Lightning-Wallet zum Testen vorhanden.
- 2026-06-11: Token-Abilities = Standard `['*']`: die Portal-API prüft keine Sanctum-Abilities, granulare Scopes wären irreführend. Bei Bedarf in v2 nachrüsten.
- 2026-06-11: SecureStorage-Premium-Plugin gekauft (Marketplace plugins.nativephp.com) → in Phase 2 installieren + registrieren.
- 2026-06-11: Deep-Link-Flow für Token-Übergabe gewählt (statt In-App-Formular oder Device-Code).
- 2026-06-11: Login nur Lightning (LNURL) + Nostr, wie Portal-Login. Keine eigene Registrierung in der App.
- 2026-06-11: v1-Module = Meetups & Termine, Kurse & Referenten, Orte & Karte. v1 read-only + Auth.

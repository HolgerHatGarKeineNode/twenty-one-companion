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
- [ ] 1.11 UX-Härtung des Rücksprungs: Chrome zeigt beim automatischen `einundzwanzig://`-Redirect den Bestätigungs-Toast („Seite will App öffnen"). Lösung = verifizierte **Android App Links** (https-URL + assetlinks.json) — Entscheidung des Users zu Variante (Portal-Host komplett / Subdomain / Button-Tap behalten) ausstehend. ⚠️ NativePHP claimt bei `NATIVEPHP_DEEPLINK_HOST` immer den GANZEN Host (`pathPrefix="/"`, `RunsAndroid.php:341`).
- [ ] 1.12 Optional (User-Wunsch prüfen): Amber-Kopplung „nach Spec" — entweder `get_public_key`-Connect-Schritt mit `permissions`-Param (NIP-55) oder NIP-46/bunker über das im Portal bereits vorhandene nostr-login-Widget (`resources/js/nostrLogin.js`, „Connect with Nostr"-Button).

## Phase 2 — App: Deep Link, SecureStorage, Login-Flow

Arbeitsverzeichnis: dieses Projekt.

- [ ] 2.1 NativePHP-Plugins registrieren (`browser`, `dialog`, `network`, `share`) — laut Memory installiert, aber nicht registriert. Skill `nativephp-mobile` aktivieren und Registrierungsweg prüfen.
- [ ] 2.2 Deep-Link-Schema `einundzwanzig://` in NativePHP konfigurieren (`config/nativephp.php` / `.env` `NATIVEPHP_DEEPLINK_SCHEME`); Handler für `einundzwanzig://auth?token=...` bauen.
- [ ] 2.2b SecureStorage-Premium-Plugin installieren (`composer require` aus dem Marketplace `https://plugins.nativephp.com`, vom User am 2026-06-11 gekauft) und registrieren: `php artisan native:plugin:register <vendor/paket>` + Rebuild.
- [ ] 2.3 `AuthService` (o. ä.): Token aus Deep Link entgegennehmen → **SecureStorage** (NativePHP) speichern, nie in DB/Session/Logs. Logout = Token lokal löschen + `DELETE`-Call ans Portal (Token revoken).
- [ ] 2.4 Login-Screen in der App: nur ein Button „Mit Einundzwanzig Portal anmelden" → öffnet `https://portal.einundzwanzig.space/auth/mobile?redirect_uri=einundzwanzig://auth&device_name={gerät}` im In-App-Browser (browser-Plugin). Lokale `.env`-Konfig `PORTAL_URL` für Dev gegen lokales Portal.
- [ ] 2.5 Auth-Middleware/State in der App: eingeloggt vs. Gast; nach Login `GET /api/user` ziehen und Profil lokal cachen. Gast-Modus erlaubt die öffentlichen Read-Inhalte trotzdem (API ist public).
- [ ] 2.6 Feature-Tests (Pest): Deep-Link-Token-Verarbeitung, AuthService, Logout. HTTP gegen Portal mit Saloon::fake() mocken.

## Phase 3 — App: Saloon API-Client + DTOs

- [ ] 3.1 Saloon-Connector `PortalConnector` (Base-URL aus Config, Bearer-Token aus SecureStorage wenn vorhanden, Accept: application/json, sinnvolle Timeouts/Retry).
- [ ] 3.2 Requests für v1-Endpunkte: `GET /api/meetups` (Map-Format), `GET /api/meetup`, `GET /api/meetup-events/{date?}`, `GET /api/courses`, `GET /api/lecturers`, `GET /api/cities`, `GET /api/venues`, `GET /api/countries`, `GET /api/btc-map-communities`, `GET /api/user`, `GET /api/my-meetups`, `GET /api/my-courses`.
- [ ] 3.3 DTOs mit spatie/laravel-data je Resource (Meetup, MeetupEvent, Course, Lecturer, City, Venue, UserProfile) — Felder gegen die Portal-Resources (`app/Http/Resources/` im Schwesterprojekt) bzw. Scramble-Doku abgleichen.
- [ ] 3.4 Caching-Layer: Responses lokal cachen (SQLite/Cache) mit TTL, damit die App offline zumindest zuletzt geladene Daten zeigt (network-Plugin: Online-Status prüfen).
- [ ] 3.5 Unit-/Feature-Tests mit Saloon MockClient für Connector + DTO-Mapping.

## Phase 4 — App: Modul Meetups & Termine

- [ ] 4.1 Bestehende `meetups`-Route/Home-View der App sichten (Commit „Add meetups route") und auf API-Daten umstellen.
- [ ] 4.2 Meetup-Liste (Suche/Filter nach Land/Stadt) + Meetup-Detail (Beschreibung, Links, nächste Events).
- [ ] 4.3 Event-Übersicht „Kommende Termine" (`/api/meetup-events`), Detailansicht mit Datum/Ort.
- [ ] 4.4 iCal-/Kalender-Export oder „Teilen" via share-Plugin (Meetup-Link teilen).
- [ ] 4.5 Eingeloggt: „Meine Meetups" (`/api/my-meetups`) als eigener Tab/Bereich.
- [ ] 4.6 Browser-Tests/Smoke-Tests (Pest v4) für die Views.

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

## Entscheidungs-Log

- 2026-06-11: Mobile-Nostr-Login via **NIP-55/Amber** (Intent-URL `nostrsigner:` + Server-Callback) statt `window.nostr` — im In-App-Browser gibt es keine Browser-Extensions. Testgerät hat Amber installiert; kein Lightning-Wallet zum Testen vorhanden.
- 2026-06-11: Token-Abilities = Standard `['*']`: die Portal-API prüft keine Sanctum-Abilities, granulare Scopes wären irreführend. Bei Bedarf in v2 nachrüsten.
- 2026-06-11: SecureStorage-Premium-Plugin gekauft (Marketplace plugins.nativephp.com) → in Phase 2 installieren + registrieren.
- 2026-06-11: Deep-Link-Flow für Token-Übergabe gewählt (statt In-App-Formular oder Device-Code).
- 2026-06-11: Login nur Lightning (LNURL) + Nostr, wie Portal-Login. Keine eigene Registrierung in der App.
- 2026-06-11: v1-Module = Meetups & Termine, Kurse & Referenten, Orte & Karte. v1 read-only + Auth.

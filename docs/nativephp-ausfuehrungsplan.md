# NativePHP Mobile v3 — Ausführungsplan

> Erstellt am 2026-06-11 aus einem Deep-Scan aller 58 Seiten der offiziellen Doku (nativephp.com/docs/mobile/3).

# Ausführungsplan: „einundzwanzig-mobile-app" — Laravel 12 + NativePHP Mobile v3 + Flux UI Pro (Linux/Android-first)

> Zielversion: **nativephp/mobile ~3.3.6** (aktuell), PHP 8.3–8.5 (NativePHP bündelt PHP 8.4), Laravel 12, Livewire 4, Flux UI Pro v2, Tailwind v4.
> Zielpfad: `/home/user/Code/einundzwanzig-mobile-app`

---

## 0) Lizenz-Voraussetzungen (vorab klären!)

| Komponente | Lizenzstatus | Was wird benötigt |
|---|---|---|
| **NativePHP Mobile v3 Core** (`nativephp/mobile`) | **Free & Open Source** seit v3.0 — KEIN privates Composer-Repo, KEIN Lizenzschlüssel, keine `auth.json`-Credentials mehr | nichts |
| **Free Core-Plugins** (MIT): Browser, Camera, Device, Dialog, File, Microphone, Network, Share, System | kostenlos via Packagist | nichts |
| **Premium-Plugins** (proprietär, Bifrost Technology): Biometrics (49 $), Geolocation (49 $), Scanner (49 $), SecureStorage (49 $), Firebase/Push (99 $) — alternativ alle in **NativePHP Ultra** (ab 35 $/Monat) oder Starter-Kit-Bundle (199 $) | kostenpflichtig | Kauf + Composer-Auth: `composer config repositories.nativephp-plugins composer https://plugins.nativephp.com` und `composer config http-basic.plugins.nativephp.com <lizenz-email> <license-key>` (Credentials im NativePHP-Dashboard unter „Purchased Plugins") |
| **Flux UI Pro** | kostenpflichtige Lizenz (vorhanden, da im Hauptprojekt genutzt) | `composer config repositories.flux-pro composer https://composer.fluxui.dev` + `composer config http-basic.composer.fluxui.dev <email> <flux-license-key>` (alternativ `php artisan flux:activate`) |
| **Jump-App** (Live-Preview auf Gerät) | kostenlos (App Store / Google Play, https://bifrost.nativephp.com/jump) — Jump **v2+** für NativePHP 3.3+ nötig | nichts |
| **Apple Developer Account** *(nur iOS)* | kostenpflichtig; Pflicht für Echtgerät-Tests, App Store, Push | nur auf macOS relevant |
| **Google Play Console** *(für Release)* | einmalig 25 $ | erst bei Store-Release |

**Entscheidung vor Start:** Werden Biometrie/Scanner/Geolocation/SecureStorage/Push gebraucht? Dann Plugins kaufen oder Ultra-Abo abschließen — sonst nur Free-Plugins einplanen.

---

## 1) Voraussetzungen & Tools/SDKs (Linux, Android)

### 1.1 PHP & Basis-Tooling
- **PHP 8.3–8.5** mit Extensions **gd** (für Icon-/Splash-Generierung, `memory_limit` ≥ 2G empfohlen) und sqlite3. Achtung: `native:run` bricht seit 3.3.5 ab, wenn die Host-PHP-Version nicht zur `nativephp.lock` passt — Host-PHP konsistent halten (NativePHP bündelt PHP 8.4 → idealerweise lokal 8.4 nutzen, wird aus `composer.json` erkannt).
- Composer, Laravel-Installer, Node + Yarn:
```bash
# CachyOS/Arch-Beispiele
sudo pacman -S --needed php php-gd php-sqlite composer nodejs yarn jdk17-openjdk android-tools
composer global require laravel/installer
php -m | grep -E 'gd|sqlite'   # verifizieren
```
- `memory_limit` in der CLI-php.ini auf `2G` setzen (Icon/Splash-Verarbeitung).

### 1.2 Android-Toolchain (Pflicht für Linux-Entwicklung)
- **Android Studio 2024.2.1+** installieren (AUR `android-studio` oder JetBrains Toolbox).
- Im SDK Manager (Tools → SDK Manager):
  - Tab „SDK Platforms": mindestens **eine Plattform API 29+** (empfohlen: API 36).
  - Tab „SDK Tools": **Android SDK Build-Tools** + **Android SDK Platform-Tools**.
- **JDK 17** (Android Studio liefert kein JDK mehr automatisch mit).
- Mindestens **einen AVD/Emulator** im Virtual Device Manager anlegen (sonst Fehler „No AVDs found"). Zusätzlich einen AVD mit der minimal unterstützten Android-Version anlegen (WebView-/Tailwind-v4-Test, siehe §6).
- Umgebungsvariablen (fish-Shell, `~/.config/fish/config.fish`):
```fish
set -gx JAVA_HOME /usr/lib/jvm/java-17-openjdk
set -gx ANDROID_HOME $HOME/Android/Sdk
fish_add_path $JAVA_HOME/bin $ANDROID_HOME/emulator $ANDROID_HOME/platform-tools $ANDROID_HOME/tools $ANDROID_HOME/tools/bin
```
- Verifikation (muss beides funktionieren, sonst schlägt der Build fehl):
```bash
java -version
adb devices
```
- Echtgerät (optional): Entwickleroptionen + USB-Debugging aktivieren.

### 1.3 🍎 iOS-Hinweise (separat — NUR auf macOS möglich)
> iOS-Apps lassen sich **ausschließlich auf einem Apple-Silicon-Mac (M1+)** kompilieren. Unter Linux: iOS komplett überspringen; lediglich der **Jump**-Workflow erlaubt das Testen der Laravel-App auf einem iPhone ohne Build.
- Xcode 16.0+ (App Store), `xcode-select --install`, Homebrew, `brew install cocoapods`.
- Apple Developer Account: nicht nötig für Simulator, Pflicht für Echtgerät/Store/Push; `NATIVEPHP_DEVELOPMENT_TEAM` (Team-ID aus Membership) in `.env`.
- iOS-Builds dann: `php artisan native:run ios`, Packaging via `native:package ios --export-method=app-store …`.

---

## 2) Exakte Befehlsreihenfolge der Installation

```bash
# (1) Frisches Laravel-12-Projekt mit Livewire-Starter-Kit
cd /home/user/Code
laravel new einundzwanzig-mobile-app
#   → im Wizard: Starter Kit "Livewire", Auth wählen, Pest, kein Test-DB-Sonderfall
cd /home/user/Code/einundzwanzig-mobile-app

# (2) Flux UI Pro aktivieren (Free-Flux kommt mit dem Starter Kit)
composer config repositories.flux-pro composer https://composer.fluxui.dev
composer config http-basic.composer.fluxui.dev "<email>" "<flux-license-key>"
composer require livewire/flux-pro

# (3) NativePHP Mobile installieren (frei, kein Lizenz-Setup)
composer require nativephp/mobile:~3.3.6

# (4) PFLICHT vor native:install: App-ID in .env setzen (Reverse-Domain, unveränderlich planen!)
#     .env ergänzen:
#     NATIVEPHP_APP_ID=com.einundzwanzig.mobileapp
#     NATIVEPHP_APP_VERSION=DEBUG
#     QUEUE_CONNECTION=database

# (5) Installer ausführen (nur Android auf Linux)
php artisan native:install android
#   → ICU-Prompt: "ohne ICU" wählen, sofern keine intl-Extension gebraucht wird
#     (Flux/Livewire brauchen kein intl; ICU = +~30 MB Android. Filament bräuchte ICU.)
#   → erzeugt ./nativephp/ (ephemer!) und den Script-Helper ./native

# (6) .gitignore ergänzen
printf "nativephp/\npublic/ios-hot\npublic/android-hot\n" >> .gitignore

# (7) Vite-Plugin einbinden (vite.config.js, siehe §3.4), dann Frontend bauen
yarn install
yarn build --mode=android

# (8) App im Emulator starten
php artisan native:run android        # Kurzform nach install: ./native run android
```

**Alternative für schnellste Iteration ohne Emulator (Jump):** Jump-App (v2+) auf dem Handy installieren, beide Geräte ins selbe WLAN, dann `php artisan native:jump` und QR-Code scannen (funktioniert auch für iPhones vom Linux-Rechner aus!). Firewall: eingehende Ports 3000–3003 freigeben.

**Optional: Laravel Boost** für KI-Unterstützung: `php artisan boost:install` (lädt auch NativePHP-Guidelines).

---

## 3) Projektstruktur- & Konfigurationsschritte

### 3.1 `.env` (Entwicklung)
```env
NATIVEPHP_APP_ID=com.einundzwanzig.mobileapp     # NIE mehr ändern (Bundle-ID iOS+Android)
NATIVEPHP_APP_VERSION=DEBUG                       # in Dev so lassen → App-Bundle wird immer neu geladen
NATIVEPHP_START_URL=/dashboard                    # initiale Route beim App-Start
NATIVEPHP_DEEPLINK_SCHEME=einundzwanzig           # für spätere OAuth-/Deep-Link-Flows
QUEUE_CONNECTION=database                         # aktiviert den nativen Background-Queue-Worker (ZTS-PHP)
FILESYSTEM_DISK=mobile_public                     # nutzergenerierte Dateien überleben App-Updates
# NATIVEPHP_ANDROID_MIN_SDK=33                    # Default 33; NICHT unter 33 senken → Tailwind v4! (s. §6)
```

### 3.2 `config/nativephp.php` prüfen/setzen
- `runtime.mode = 'persistent'` (Default ab 3.1, ~5–30 ms Antwortzeit). Bei State-Problemen: `reset_instances=true` (Default), notfalls `gc_between_dispatches=true` oder Modus `classic`.
- `android`: `compile_sdk=36`, `target_sdk=36`, `min_sdk=33` (Relation: compile ≥ target ≥ min; absolutes Minimum 26 — aber siehe Tailwind-v4-Falle §6).
- `android.status_bar_style`: `auto|light|dark` passend zum Flux-Dark-Mode-Verhalten.
- `hot_reload.watch_paths`: **`'resources'` ergänzen** (Default enthält es nicht — Livewire-Views/Blade liegen dort!): `['app','resources','routes','config','database','public']`.
- `cleanup_env_keys`: alle Secrets eintragen (z. B. `APP_STORE_*`, `ANDROID_KEYSTORE_*`, API-Keys) — die `.env` wird sonst mit ins App-Bundle ausgeliefert!
- `permissions` (iOS-Usage-Strings) erst relevant, wenn Plugins mit Berechtigungen dazukommen.
- Fehlende neuere Keys aus `vendor/nativephp/mobile/config/nativephp.php` nachziehen (oder `php artisan vendor:publish --tag=nativephp-mobile-config --force` — Achtung: überschreibt eigene Änderungen).

### 3.3 Assets/Branding (Konvention, keine Config)
- `public/icon.png` — PNG, **exakt 1024×1024, ohne Transparenz** (EINUNDZWANZIG-Logo auf Vollflächen-Hintergrund).
- `public/splash.png` + `public/splash-dark.png` — PNG, mind. **1080×1920** (Hochformat).

### 3.4 `vite.config.js`
```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { nativephpMobile, nativephpHotFile } from './vendor/nativephp/mobile/resources/js/vite-plugin.js';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            hotFile: nativephpHotFile(),
        }),
        tailwindcss(),
        nativephpMobile(),
    ],
});
```
Builds immer plattformspezifisch: `yarn build --mode=android` (auf macOS zusätzlich `--mode=ios`).

### 3.5 Mobile-Layout (App-Shell) — `resources/views/components/layouts/app.blade.php`
- Viewport-Meta für natives Gefühl + Edge-to-Edge:
  `<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no, viewport-fit=cover">`
- `<body class="nativephp-safe-area …">` + Safe-Area-CSS-Variablen (`--inset-top/-bottom/-left/-right`) für fixe Header/Footer.
- Tailwind-v4-Variante für Tastatur in `resources/css/app.css`:
```css
@import "tailwindcss";
@custom-variant keyboard-visible (&:where(body.keyboard-visible *));
```
  → z. B. `class="… keyboard-visible:translate-y-full"` auf der Bottom-Nav.
- **EDGE-Komponenten ins Layout** (werden bei jedem Request gerendert, nativ — kein Tailwind-Styling möglich, max. 5 Bottom-Nav-Items, Pflicht-Props `id/icon/label/url`):
```blade
<native:top-bar title="EINUNDZWANZIG" show-navigation-icon="false" />
<native:bottom-nav label-visibility="labeled">
    <native:bottom-nav-item id="home"    icon="home"     label="Home"    url="/dashboard" :active="request()->is('dashboard')" />
    <native:bottom-nav-item id="meetups" icon="people"   label="Meetups" url="/meetups"   :active="request()->is('meetups*')" />
    <native:bottom-nav-item id="news"    icon="newspaper" label="News"   url="/news"      :active="request()->is('news*')" />
    <native:bottom-nav-item id="profile" icon="person"   label="Profil"  url="/profile"   :active="request()->is('profile*')" />
</native:bottom-nav>
```
  Hinweis: EDGE-Links machen volle Requests (für Livewire normal/okay); URLs außerhalb der WebView-Domain öffnen im System-Browser.

### 3.6 Beispiel-Screens (Livewire 4 + Flux Pro)
```bash
php artisan make:livewire Pages\\Dashboard --no-interaction
php artisan make:livewire Pages\\Meetups\\Index --no-interaction
php artisan make:livewire Pages\\Profile --no-interaction
php artisan make:livewire Auth\\Login --no-interaction
```
- Routen in `routes/web.php` als Vollseiten-Komponenten; `/` → Redirect auf `NATIVEPHP_START_URL`-Ziel.
- UI durchgängig mit Flux (`flux:card`, `flux:button`, `flux:input`, `flux:navbar` etc.), Dark-Mode via `dark:` (EDGE-Komponenten folgen dem System-Dark-Mode automatisch — Flux daran angleichen).
- Plattform-Weichen serverseitig: `Native\Mobile\Facades\System::isIos()/isAndroid()` (System-Plugin, s. §4).

### 3.7 Auth-Vorbereitung (Sanctum-Token gegen das bestehende Portal-API)
Architekturprinzip der Doku: **lokale Daten beweisen keine Authentifizierung** — Auth läuft gegen einen externen Service (hier: die bestehende `einundzwanzig-app`-API mit Sanctum).
1. Login-Screen (Livewire): E-Mail/Passwort → `Http::post('https://portal…/api/v1/auth/token', …)` → Token zurück.
2. Token-Speicherung:
   - **Mit SecureStorage-Plugin (49 $, empfohlen):** `SecureStorage::set('auth_token', $token)` (Android Keystore / iOS Keychain).
   - **Ohne Premium-Plugin (Fallback):** Token mit `Crypt::encryptString()` verschlüsselt in lokaler SQLite/Datei ablegen — NativePHP generiert pro Gerät einen einzigartigen `APP_KEY` im Secure Storage.
3. Serverseitig im Portal: **Sanctum-Token-Expiration aktivieren** (Default: läuft nie ab!), kurzlebige Tokens (< 48 h) + Refresh-Strategie, Rate-Limiting auf dem Auth-Endpoint (CSRF gibt es im API-Kontext nicht).
4. App-seitig: Vor API-Calls `Network::status()` prüfen (offline-Fallback auf lokalen SQLite-Cache); 401 → Re-Login-Flow.
5. Optional später: Lightning-Login via `Browser::auth(...)` + `NATIVEPHP_DEEPLINK_SCHEME` (Redirect `einundzwanzig://auth/handle`; Redirect-URI vorab beim Auth-Dienst registrieren).

### 3.8 Datenbank & Queues
- **Nur SQLite**, vollautomatisch: NativePHP schaltet die Connection beim Build um, legt die DB im App-Container an und führt **bei jedem App-Start ausstehende Migrationen** aus. Kein Remote-DB-Zugriff — zentrale Daten ausschließlich über die Sanctum-API (API-First, lokale DB = Offline-Cache).
- Seed-Daten als **Seed-Migration** (`php artisan make:migration seed_app_settings`) statt Seeder (läuft genau 1× pro Installation).
- Jobs: normale `ShouldQueue`-Jobs + `QUEUE_CONNECTION=database`; Worker startet automatisch in eigenem Thread (`queue:work --once`-Loop). Nur `database`-Connection wird unterstützt; Jobs überleben App-Neustarts, laufen aber primär bei aktiver App (echte Background-Tasks sind in v3 nur Roadmap!).

### 3.9 Tests
- Pest-Feature-Tests für Livewire-Komponenten wie gewohnt (`php artisan test --compact`); native Aufrufe in Tests mit `function_exists('nativephp_call')`-Guards bzw. Facade-Mocks absichern.

---

## 4) Verfügbare native APIs (vollständige Liste)

Alle nativen Features sind in v3 **Plugins** (Composer-Pakete; nach `composer require` ggf. via `php artisan native:plugin:register` registrieren, dann Rebuild). Nutzungsmuster in Livewire: Facade-Aufruf + asynchrones Ergebnis über `#[OnNative(EventKlasse::class)]` (aus `Native\Mobile\Attributes\OnNative`).

**Free (MIT):**
1. **Browser** (`nativephp/mobile-browser`) — öffnet URLs in-app (Custom Tabs/SFSafariViewController), im System-Browser oder als OAuth-Flow mit `Browser::auth()` und automatischem Deep-Link-Redirect.
2. **Camera** (`nativephp/mobile-camera`) — Fotoaufnahme, Videoaufzeichnung und Galerie-Picker; Ergebnisse via `PhotoTaken`/`VideoRecorded`/`MediaSelected`-Events (Achtung: Android-Foto landet als fixes `{cache}/captured.jpg` → sofort wegkopieren).
3. **Device** (`nativephp/mobile-device`) — Vibration, Taschenlampe, eindeutige Geräte-ID, Geräteinfos und Batteriestatus als synchrone Rückgaben (info-Felder sind JSON-Strings).
4. **Dialog** (`nativephp/mobile-dialog`) — native Alert-Dialoge mit Buttons (`ButtonPressed`-Event) und Toasts/Snackbars (`Dialog::toast()`, synchron).
5. **File** (`nativephp/mobile-file`) — Dateien verschieben/kopieren im App-Sandbox-Dateisystem mit Auto-Verzeichnisanlage und Integritätsprüfung.
6. **Microphone** (`nativephp/mobile-microphone`) — M4A/AAC-Audioaufnahme mit Pause/Resume, Status-Abfrage und `MicrophoneRecorded`-Event; Hintergrundaufnahme via `microphone_background`-Config.
7. **Network** (`nativephp/mobile-network`) — `Network::status()` liefert connected/type (wifi/cellular/…)/isExpensive/isConstrained (Pull-API, keine Change-Events).
8. **Share** (`nativephp/mobile-share`) — natives Share-Sheet für URLs, Texte und lokale Dateien (Fire-and-forget, kein Ergebnis-Event).
9. **System** (`nativephp/mobile-system`) — Plattformerkennung (`isIos/isAndroid/isMobile`), App-Einstellungen des OS öffnen, Taschenlampen-Toggle.

**Premium (proprietär, Lizenz + privates Composer-Repo nötig):**
10. **Biometrics** (`nativephp/mobile-biometrics`, 49 $) — Face ID/Touch ID/Fingerprint-Prompt mit System-PIN-Fallback; Ergebnis als `Completed`-Event mit `bool $success` (Convenience, keine echte Sicherheit — Autorisierung serverseitig halten).
11. **Geolocation** (`nativephp/mobile-geolocation`, 49 $) — einmalige Positionsabfrage (Netzwerk oder GPS) + Berechtigungsverwaltung; Ergebnis via `LocationReceived`-Event (lat/lng/accuracy/provider), kein kontinuierliches Tracking.
12. **Scanner** (`nativephp/mobile-scanner`, 49 $) — QR-/Barcode-Scanner (ML Kit/AVFoundation) mit Formaten qr/ean/code128/…, continuous-Mode und `CodeScanned`-Event — prädestiniert für Lightning-/LNURL-QR-Codes.
13. **SecureStorage** (`nativephp/mobile-secure-storage`, 49 $) — verschlüsselter Key-Value-Speicher (iOS Keychain / Android EncryptedSharedPreferences, AES-256-GCM) für Tokens & Secrets (nur kleine Strings, device-only).
14. **Firebase Push** (`nativephp/mobile-firebase`, 99 $) — Push-Notifications über FCM (Android) + APNs-Routing (iOS) inkl. `enroll()/getToken()`, `TokenGenerated`-/`PushNotificationReceived`-Events, Data-only-Messages, Deep-Link-Navigation und Test-Befehl `fcm:send`.

**Framework-Fähigkeiten (im Core enthalten):**
15. **EDGE-Komponenten** — echte native UI aus Blade: `<native:top-bar>` (Titel + max. 10 Actions), `<native:bottom-nav>` (max. 5 Tabs, Badges), `<native:side-nav>` (Drawer mit Header/Gruppen/Divider), Icon-Mapping (SF Symbols/Material Icons).
16. **Event-System** — native Ergebnisse als Laravel-Events, in Livewire per `#[OnNative]`, im JS per `On()/Off()` aus `#nativephp`.
17. **SQLite-Datenbank** — automatisch provisioniert, Migrationen bei jedem App-Start.
18. **Queues** — Background-Queue-Worker auf eigenem Thread (ZTS-PHP, `database`-Connection).
19. **Deep Links** — Custom-Scheme (`NATIVEPHP_DEEPLINK_SCHEME`) und Universal/App Links (`NATIVEPHP_DEEPLINK_HOST` + `.well-known`-Verifikationsdateien auf dem Server).
20. **Secure APP_KEY & Crypt** — gerätespezifischer APP_KEY im nativen Secure Storage; `Crypt::encryptString()` für größere lokale Datenmengen.

---

## 5) Build-/Run-/Hot-Reload-Workflow (täglicher Dev-Loop)

**Variante A — Jump (schnellster Loop, echtes Gerät, kein Build):**
```bash
yarn dev                          # Terminal 1: Vite mit HMR (wird automatisch über Port 3003 geproxied)
php artisan native:jump           # Terminal 2: QR-Code → mit Jump-App scannen
# Optionen: --ip=192.168.x.x (bei mehreren Interfaces), --no-mdns, Ports --http-port/--ws-port/--bridge-port/--vite-proxy-port
# Logs der Bridge: tail -f storage/logs/jump-bridge.log
```
Die meisten nativen APIs (Dialoge, Kamera, Scanner …) funktionieren in Jump; **nicht** verlässlich: alles mit langlebigem Geräte-State (Queues/Background) → dafür Variante B.

**Variante B — echter Build im Emulator/Gerät:**
```bash
yarn build --mode=android                 # IMMER vor dem Kompilieren (sonst alte Assets im Bundle!)
php artisan native:run android            # baut + deployed; ./native run android als Kurzform
php artisan native:run android --watch    # mit Hot Reloading (oder separat: php artisan native:watch android)
php artisan native:tail                   # Laravel-Logs der laufenden Android-App
php artisan native:open android           # Android Studio mit dem nativen Projekt öffnen (Debugging)
php artisan native:debug                  # Diagnose der Mobile-Umgebung
```
- `NATIVEPHP_APP_VERSION=DEBUG` in Dev belassen → Laravel-Bundle wird bei jedem Start neu geladen.
- HMR auf Echtgerät: Gerät + Rechner im selben WLAN; volles Hot Reloading funktioniert am besten im Emulator.
- Nach Plugin-Installation/-Registrierung oder NativePHP-Minor-Update: **Rebuild Pflicht** (`php artisan native:install --force` + `native:run`).

---

## 6) Stolperfallen & Einschränkungen

1. **`NATIVEPHP_APP_ID` vor dem ersten `native:install` setzen** und danach nie ändern (= Bundle-ID in beiden Stores).
2. **`nativephp/` ist ephemer** — nie committen, nie manuell editieren (geht bei `native:install --force` verloren); ebenso `public/ios-hot`/`public/android-hot` in `.gitignore`.
3. **Tailwind v4 vs. alte Android-WebViews:** `@theme` & moderne CSS-Features laufen auf alten System-WebViews nicht. Da Flux UI Pro Tailwind v4 voraussetzt: **`min_sdk` bei 33 (Android 13) belassen** — deckt sich mit der offiziellen Support-Policy (iOS 18+/Android 13+). Auf einem AVD mit der min-Version testen.
4. **Persistente Runtime:** Laravel-Kernel lebt über Requests hinweg → Singletons/statischer State können leaken; `reset_instances` ist an, bei Problemen `gc_between_dispatches=true` oder `NATIVEPHP_RUNTIME_MODE=classic`.
5. **Mindestens v3.3.5/3.3.6 verwenden:** Fixes für Livewire-4-⚡-Emoji-Dateien (iOS-Extraktion), Hot Reload der persistenten Runtime, Android-POST/`$_POST` (Livewire-Requests!), Cold-Launch-Races.
6. **Berechtigungen schlagen still fehl:** Kamera/Mikrofon/Scanner liefern bei verweigerter Permission keinen Fehler — UX mit Timeout/Fallback bauen; Scanner-/Camera-Permission in `config/nativephp.php` aktivieren.
7. **Native Ergebnisse sind asynchron:** Nie auf Rückgabewerte von `Camera::getPhoto()`, `Biometrics::prompt()` etc. bauen — immer `#[OnNative]`-Handler; Events werden zusätzlich auch an JS in der WebView zugestellt (keine Doppelverarbeitung).
8. **Dateipfade:** `storage_path()` zeigt auf Mobile **außerhalb** des App-Roots; nutzersichtbare Dateien über Disk `mobile_public` (Symlink `public/storage`), sonst gehen sie bei App-Updates verloren. Android-Kamera-Cachedateien sofort persistent wegkopieren.
9. **Migrationen laufen bei jedem App-Start:** Eine fehlerhafte Migration in einem Update kann Nutzerdaten zerstören — vor Release auf Produktions-Build testen. App-Deinstallation löscht die gesamte SQLite-DB.
10. **Secrets:** Die `.env` wird mit ausgeliefert → alles Sensible in `cleanup_env_keys`; pro Gerät einzigartige Schlüssel, Sanctum-Tokens kurzlebig; mit `Crypt` verschlüsselte Daten sind an den gerätespezifischen `APP_KEY` gebunden (Geräteverlust = Daten unentschlüsselbar).
11. **Kein MySQL/PostgreSQL, kein Remote-DB-Zugriff, kein Redis-Queue, keine echten Background-Tasks** (nur Roadmap) — Architektur strikt API-First gegen das Portal.
12. **EDGE-Einschränkungen:** nur Top-Bar/Bottom-Nav/Side-Nav/Icons, kein Tailwind-Styling, `active`-State pro Route serverseitig setzen, Links = volle Postbacks, falsche Icon-Namen ergeben stumm ein Kreis-Icon.
13. **Jump:** Gast-WLANs mit Client-Isolation funktionieren nicht; mDNS ggf. mit `--no-mdns` umgehen; Jump ersetzt keine Release-Tests (`native:run`/`native:package`).
14. **iOS generell nur auf macOS** (Build, Simulator, Packaging); Push-Notifications funktionieren nicht im iOS-Simulator; WSL wird nicht unterstützt (für Linux nativ irrelevant).
15. **Plugins:** `composer require` allein reicht nicht — ohne `native:plugin:register` + Rebuild kein nativer Code; `native:run` warnt bei unregistrierten Plugins. Premium-Plugins prüfen: Livewire-v4-Kompatibilität laut Marketplace.

---

## 7) Updates, Versionierung & App-Store-Releases

### 7.1 Paket-Updates (nativephp/mobile)
- Constraint pinnen: `"nativephp/mobile": "~3.3.6"` (Tilde: Patches automatisch, Minors bewusst).
- **Patch-Release** (nur PHP-Code): `composer update` genügt — kein Rebuild, keine Store-Submission.
- **Minor-Release** (kann Kotlin/Swift ändern): `composer update` → **`php artisan native:install --force`** (kompletter Rebuild) → `native:run` testen → neue Store-Submission nötig. Migration Guides beachten.
- Flux Pro/Livewire normal über Composer aktualisieren; danach `yarn build --mode=android` nicht vergessen.

### 7.2 App-Versionierung
- `NATIVEPHP_APP_VERSION` (öffentlich, SemVer empfohlen) und `NATIVEPHP_APP_VERSION_CODE` (interne Build-Nummer, muss pro Store-Upload strikt steigen) **nie manuell editieren**, sondern:
```bash
php artisan native:release patch|minor|major   # bumpt Version + Build-Nummer in .env
php artisan native:check-build-number          # validiert/schlägt Build-Nummern vor
```

### 7.3 Android-Release (Play Store) — auf Linux komplett möglich
```bash
# Einmalig: Keystore generieren (schreibt ANDROID_* in .env + .gitignore)
php artisan native:credentials android

# Release-Build testen
php artisan native:run android --build=release

# Signiertes App-Bundle für den Play Store
yarn build --mode=android
php artisan native:package android --build-type=bundle
#   Artefakt: nativephp/android/app/build/outputs/bundle/release/app-release.aab

# Optional: direkter Upload (Google-Service-Account-JSON nötig)
php artisan native:package android --build-type=bundle \
  --upload-to-play-store --play-store-track=internal \
  --google-service-key=/pfad/service-account.json
```
- Für Store-Builds in `config/nativephp.php`: `minify_enabled=true`, `shrink_resources=true` (kleinere APK/AAB).
- Mit `--google-service-key` wird die Build-Nummer automatisch gegen den Play Store inkrementiert; CI: `--no-tty` + Credentials als Env-Variablen (`ANDROID_KEYSTORE_FILE/_PASSWORD`, `ANDROID_KEY_ALIAS/_PASSWORD`).
- Keystore-Probleme debuggen: `keytool -list -v -keystore <pfad>`.
- **Keystore sicher backuppen** — Verlust = keine Updates der App mehr möglich.

### 7.4 🍎 iOS-Release (nur macOS)
- Voraussetzungen: Apple Developer Program, Distribution-Zertifikat (.p12), Provisioning-Profil, App-Store-Connect-API-Key (.p8 — nur einmal herunterladbar!).
- `php artisan native:package ios --export-method=app-store --upload-to-app-store …` mit `APP_STORE_API_KEY_PATH/_ID/ISSUER_ID`, `IOS_DISTRIBUTION_CERTIFICATE_*`, `IOS_TEAM_ID` in `.env` (und in `cleanup_env_keys`!).
- Hilfsflags: `--validate-profile`, `--validate-only`, `--test-upload`, `--clean-caches`, `--rebuild`.

### 7.5 Wichtige Release-Regeln
- Vor jedem Update-Release: Migrationen gegen einen Produktions-Build testen (laufen beim ersten Start beim Nutzer!).
- Support-Ziel: iOS 18+/Android 13+; Feature-Verfügbarkeit pro OS-Version einzeln testen.
- Optional: Bifrost-Dienst von NativePHP übernimmt Zertifikate/Keystores und bietet OTA-Updates (kommerzielles Add-on, nicht erforderlich).

---

## Abarbeitungs-Checkliste (Kurzfassung)

1. ☐ Lizenzen klären (Flux-Pro-Key bereitlegen; Premium-Plugins kaufen falls Biometrie/Scanner/Push/SecureStorage gewünscht)
2. ☐ Toolchain: PHP 8.4 + gd (2G memory_limit), JDK 17, Android Studio + SDK API 36 + Build/Platform-Tools, AVD anlegen, `JAVA_HOME`/`ANDROID_HOME`/PATH, `java -version` & `adb devices` ok
3. ☐ `laravel new einundzwanzig-mobile-app` (Livewire-Kit) → Flux Pro via Composer-Repo → `composer require nativephp/mobile:~3.3.6`
4. ☐ `.env`: `NATIVEPHP_APP_ID`, `NATIVEPHP_APP_VERSION=DEBUG`, `QUEUE_CONNECTION=database`, `NATIVEPHP_START_URL`
5. ☐ `php artisan native:install android` (ohne ICU) → `.gitignore` (nativephp/, *-hot)
6. ☐ `vite.config.js` mit `nativephpMobile()`/`nativephpHotFile()`; `config/nativephp.php` (watch_paths + resources, cleanup_env_keys, min_sdk 33)
7. ☐ `public/icon.png` (1024², opak) + `public/splash(.dark).png` (1080×1920)
8. ☐ App-Shell: Layout mit viewport-fit=cover, `nativephp-safe-area`, `keyboard-visible`-Variante, EDGE-Bottom-Nav/Top-Bar; Screens Dashboard/Meetups/Profil/Login (Flux)
9. ☐ Auth: Sanctum-Token-Flow gegen Portal-API, Token-Storage (SecureStorage oder Crypt), Token-Expiration + Rate-Limit serverseitig
10. ☐ Dev-Loop: `yarn build --mode=android` → `php artisan native:run android --watch`; parallel Jump für Echtgerät-Tests
11. ☐ Pest-Tests für Livewire-Komponenten; `vendor/bin/pint --dirty`
12. ☐ Release: `native:release` → `native:credentials android` → `native:package android --build-type=bundle` → Play-Store-Track `internal`

---

# Anhang: Vollständigkeits-Review

## Vollständigkeits-Review: Plan vs. Doku-Korpus

Gesamturteil: Der Plan ist sehr vollständig und in den kritischen Punkten (App-ID, ephemeres `nativephp/`, Tailwind-v4/min_sdk, Async-Events, Plugin-Lizenzen, Release-Flow) korrekt. Es gibt aber einige konkrete Korrekturen und Lücken:

### Korrekturen (falsch oder ungenau wiedergegeben)

1. **Plugin-Registrierung ist Pflicht, nicht „ggf." — und ein Schritt fehlt:** Vor der ersten Registrierung muss der Provider publiziert werden: `php artisan vendor:publish --tag=nativephp-plugins-provider` (erzeugt `app/Providers/NativeServiceProvider.php`). Erst danach `php artisan native:plugin:register vendor/plugin-name`. Dieser publish-Schritt fehlt im Plan komplett (§4-Intro sagt nur „ggf. registrieren"). Auch die Free-Core-Plugins (z. B. Camera) müssen laut Plugin-Doku explizit registriert werden.
2. **Rebuild nach Plugin-Installation:** Die Doku (Using Plugins) verlangt nach Registrierung nur einen Rebuild via `php artisan native:run` — nicht `native:install --force`. `--force` ist laut Doku nötig bei Minor-Upgrades von nativephp/mobile und bei Änderungen am nativen Code lokaler Plugins. Plan-Gotcha 15 ist hier strenger als die Doku (nicht schädlich, aber falsch begründet).
3. **`hot_reload.watch_paths`-Behauptung widersprüchlich zur Doku:** Die Configuration-Seite nennt als Default `['app','resources','routes','config','public']` — `resources` ist also laut Config-Referenz bereits enthalten; nur das Beispiel auf der Development-Seite zeigt es ohne `resources`. Die Plan-Aussage „Default enthält es nicht" sollte zu „im Vendor-Default prüfen, ggf. ergänzen" abgeschwächt werden.
4. **WS-Port-Angaben:** Config-Default für `server.ws_port` ist laut Configuration-Seite **8081**, während `native:jump --ws-port` Default 3001 nutzt. Der Plan nennt pauschal „Ports 3000–3003" — bei Nutzung der Config-Defaults (ohne Flags) kann zusätzlich 8081 relevant sein.
5. **Firebase-Plugin (Plan §4 Nr. 14) unvollständig/teils ungenau:** Es fehlen die Pflicht-Setup-Schritte: `google-services.json` (Android) und `GoogleService-Info.plist` (iOS) ins **Projekt-Root**, Service-Account-JSON via env `FIREBASE_CREDENTIALS`, sowie `checkPermission()` (Status granted/denied/not_determined/provisional/ephemeral; empfohlener Flow: erst checken, UI-Erklärung, dann `enroll()`). Wichtiger Architektur-Punkt fehlt: `#[OnNative]` greift **nur im Foreground bei gemounteter Komponente**; für Hintergrund-Verarbeitung von Data-only-Messages braucht es einen klassischen `Event::listen()`-Listener im ServiceProvider (läuft in ephemerer PHP-Runtime, background-safe). Ferner: Android-Permission-Dialog erst ab API 33 (`POST_NOTIFICATIONS`), `clearBadge()`-Plattformunterschied, und Notifications **mit** `notification`-Block lösen kein PHP-Event aus (nur Data-only mit `event`-Key).

### Fehlende dokumentierte Punkte (Ergänzungen)

6. **Offizielles Starter-Kit als Alternative:** `laravel new my-app --using=nativephp/mobile-starter` (Quick-Start-Seite) wird im Plan nicht erwähnt — bewusste Entscheidung für das Livewire-Kit wäre okay, sollte aber als Alternative genannt werden.
7. **ICU non-interactive:** Statt des Prompts kann `php artisan native:install android --without-icu` verwendet werden (passt zum geplanten skriptbaren Ablauf; Plan-Konvention `--no-interaction`).
8. **`JUMP_BRIDGE_PORT=3002`:** Pflicht-Env, wenn man mit `--no-serve` einen eigenen Laravel-Server betreibt — fehlt in §5 Variante A.
9. **Deep-Link-Gotchas (§4 Nr. 19):** Associated Domains funktionieren typischerweise **nicht im Simulator**; das OS **cached das Verifikationsergebnis** (bei Problemen App löschen + neu installieren); Custom Scheme muss eindeutig sein, `https` u. a. sind reserviert. Außerdem dokumentiert die Doku **keine** PHP-API zum Verarbeiten eingehender Deep-Links — der Plan sollte das nicht stillschweigend voraussetzen.
10. **Config-Keys in §3.2 unvollständig:** `orientation.android` (Default: nur Portrait — relevant, falls Landscape gewünscht) und `cleanup_exclude_files` (Logs/Temp-Dateien vor Bundling entfernen) fehlen. `ipad => true` inkl. „Once iPad, Always iPad"-Falle fehlt — auf Linux/Android egal, aber bei späterem iOS-Release irreversibel.
11. **Token-Strategie:** Die Doku empfiehlt konkret **Single-Use-Refresh-Tokens (~30 Tage)** zusätzlich zu kurzlebigen Auth-Tokens — der Plan sagt nur generisch „Refresh-Strategie".
12. **App-Boot-Verhalten:** Bei jedem App-Start laufen nicht nur Migrationen, sondern auch **Cache-Clearing und Anlegen der Storage-Symlinks** (Overview-Seite) — relevant fürs mentale Modell (z. B. keine persistenten Caches einplanen).
13. **Befehle, die fehlen:** `native:version` (installierte Version), `native:plugin:list` (zeigt registrierte Plugins **inkl. benötigter Berechtigungen** — nützlich für Play-Store-Privacy-Angaben), `native:run --start-url=` (Override der Start-URL pro Run), `native:jump --laravel-port=`.
14. **Plugin-Mindestversionen:** Alle Core-/Premium-Plugins verlangen laut Plugin-Seiten **iOS 18.2+ und Android API 26+** — mit min_sdk 33 unkritisch, gehört aber zur Plugin-Kaufentscheidung in §0.
15. **JS-Bridge-Einbindung:** Falls native Aufrufe aus Alpine/JS gewünscht: Einbindung der typisierten JS-Library erfolgt **nicht via npm**, sondern per `package.json`-`imports`-Eintrag (`"#nativephp": "./vendor/nativephp/mobile/resources/dist/native.js"`) — Composer-Install muss vor dem JS-Build vorhanden sein. Fehlt im Plan (für reinen Livewire-Weg optional).
16. **Top-Bar-Details (§3.5):** Max. 10 Actions steht im Plan, aber nicht das Overflow-Verhalten (Android: nur erste 3 als Icon-Buttons, Rest im ⋮-Menü; iOS: Overflow ab >5) und dass `label` dort als Anzeigetext dient; `elevation` wirkt nur auf Android; `subtitle` wird im Doku-Beispiel genutzt, ist aber nicht offiziell in der Props-Liste dokumentiert.
17. **Geolocation-Permission-Events (§4 Nr. 11):** Neben `LocationReceived` existieren `PermissionStatusReceived` und `PermissionRequestResult` mit Sonderwert `permanently_denied` (Nutzer muss in System-Einstellungen → via `System::appSettings()` dorthin leiten). Fehlt im Plan.
18. **Pest/PHPUnit-Hinweis stimmt, aber konkreter:** Die Doku nennt explizit den `function_exists('nativephp_call')`-Guard als Pattern, damit Code im Web-/Test-Kontext sauber degradiert — der Plan erwähnt das, sollte es aber auch für eigene Service-Wrapper (Token-Storage-Fallback) vorschreiben.

### Kleinigkeiten

- §1.1 „NativePHP bündelt PHP 8.4" + „PHP 8.3–8.5": korrekt laut Doku (Overview vs. Changelog), Formulierung okay.
- ICU-Größenangabe: +~30 MB gilt für Android, iOS wären +~100 MB — bei Android-only-Plan irrelevant, im iOS-Abschnitt aber erwähnenswert.
- Der Versioning-Doku-Beispiel-Constraint ist `~2.0.0` (veraltetes Beispiel); Plan macht es mit `~3.3.6` richtig.

Kein Punkt im Plan ist gravierend falsch; die wichtigsten Fixes sind **(1) publish des NativeServiceProvider + verpflichtende Plugin-Registrierung**, **(5) Firebase-Setup-Details inkl. Foreground/Background-Event-Unterschied** und **(3) die watch_paths-Behauptung**.

# NativePHP Mobile v3 — Execution Plan

> Created on 2026-06-11 from a deep scan of all 58 pages of the official documentation (nativephp.com/docs/mobile/3).

# Execution Plan: "einundzwanzig-mobile-app" — Laravel 12 + NativePHP Mobile v3 + Flux UI Pro (Linux/Android-first)

> Target version: **nativephp/mobile ~3.3.6** (current), PHP 8.3–8.5 (NativePHP bundles PHP 8.4), Laravel 12, Livewire 4, Flux UI Pro v2, Tailwind v4.
> Target path: `/home/user/Code/einundzwanzig-mobile-app`

---

## 0) License prerequisites (clarify upfront!)

| Component | License status | What is required |
|---|---|---|
| **NativePHP Mobile v3 Core** (`nativephp/mobile`) | **Free & Open Source** since v3.0 — NO private Composer repo, NO license key, no more `auth.json` credentials | nothing |
| **Free core plugins** (MIT): Browser, Camera, Device, Dialog, File, Microphone, Network, Share, System | free via Packagist | nothing |
| **Premium plugins** (proprietary, Bifrost Technology): Biometrics ($49), Geolocation ($49), Scanner ($49), SecureStorage ($49), Firebase/Push ($99) — alternatively all included in **NativePHP Ultra** (from $35/month) or the Starter Kit bundle ($199) | paid | purchase + Composer auth: `composer config repositories.nativephp-plugins composer https://plugins.nativephp.com` and `composer config http-basic.plugins.nativephp.com <license-email> <license-key>` (credentials in the NativePHP dashboard under "Purchased Plugins") |
| **Flux UI Pro** | paid license (already available, since it is used in the main project) | `composer config repositories.flux-pro composer https://composer.fluxui.dev` + `composer config http-basic.composer.fluxui.dev <email> <flux-license-key>` (alternatively `php artisan flux:activate`) |
| **Jump app** (live preview on device) | free (App Store / Google Play, https://bifrost.nativephp.com/jump) — Jump **v2+** required for NativePHP 3.3+ | nothing |
| **Apple Developer Account** *(iOS only)* | paid; mandatory for real-device testing, App Store, Push | only relevant on macOS |
| **Google Play Console** *(for release)* | one-time $25 | only at store release |

**Decision before starting:** Are Biometrics/Scanner/Geolocation/SecureStorage/Push needed? Then buy the plugins or take out an Ultra subscription — otherwise plan only for the free plugins.

---

## 1) Prerequisites & tools/SDKs (Linux, Android)

### 1.1 PHP & base tooling
- **PHP 8.3–8.5** with extensions **gd** (for icon/splash generation, `memory_limit` ≥ 2G recommended) and sqlite3. Note: `native:run` has aborted since 3.3.5 if the host PHP version does not match `nativephp.lock` — keep the host PHP consistent (NativePHP bundles PHP 8.4 → ideally use 8.4 locally, which is detected from `composer.json`).
- Composer, Laravel installer, Node + Yarn:
```bash
# CachyOS/Arch examples
sudo pacman -S --needed php php-gd php-sqlite composer nodejs yarn jdk17-openjdk android-tools
composer global require laravel/installer
php -m | grep -E 'gd|sqlite'   # verify
```
- Set `memory_limit` in the CLI php.ini to `2G` (icon/splash processing).

### 1.2 Android toolchain (mandatory for Linux development)
- Install **Android Studio 2024.2.1+** (AUR `android-studio` or JetBrains Toolbox).
- In the SDK Manager (Tools → SDK Manager):
  - "SDK Platforms" tab: at least **one platform API 29+** (recommended: API 36).
  - "SDK Tools" tab: **Android SDK Build-Tools** + **Android SDK Platform-Tools**.
- **JDK 17** (Android Studio no longer ships a JDK automatically).
- Create at least **one AVD/emulator** in the Virtual Device Manager (otherwise the error "No AVDs found"). Additionally create an AVD with the minimum supported Android version (WebView/Tailwind v4 test, see §6).
- Environment variables (fish shell, `~/.config/fish/config.fish`):
```fish
set -gx JAVA_HOME /usr/lib/jvm/java-17-openjdk
set -gx ANDROID_HOME $HOME/Android/Sdk
fish_add_path $JAVA_HOME/bin $ANDROID_HOME/emulator $ANDROID_HOME/platform-tools $ANDROID_HOME/tools $ANDROID_HOME/tools/bin
```
- Verification (both must work, otherwise the build fails):
```bash
java -version
adb devices
```
- Real device (optional): enable Developer Options + USB debugging.

### 1.3 🍎 iOS notes (separate — ONLY possible on macOS)
> iOS apps can be compiled **exclusively on an Apple Silicon Mac (M1+)**. On Linux: skip iOS entirely; only the **Jump** workflow allows testing the Laravel app on an iPhone without a build.
- Xcode 16.0+ (App Store), `xcode-select --install`, Homebrew, `brew install cocoapods`.
- Apple Developer Account: not needed for the simulator, mandatory for real device/store/push; set `NATIVEPHP_DEVELOPMENT_TEAM` (team ID from membership) in `.env`.
- iOS builds then: `php artisan native:run ios`, packaging via `native:package ios --export-method=app-store …`.

---

## 2) Exact command order for installation

```bash
# (1) Fresh Laravel 12 project with the Livewire starter kit
cd /home/user/Code
laravel new einundzwanzig-mobile-app
#   → in the wizard: Starter Kit "Livewire", choose Auth, Pest, no special test-DB case
cd /home/user/Code/einundzwanzig-mobile-app

# (2) Activate Flux UI Pro (free Flux comes with the starter kit)
composer config repositories.flux-pro composer https://composer.fluxui.dev
composer config http-basic.composer.fluxui.dev "<email>" "<flux-license-key>"
composer require livewire/flux-pro

# (3) Install NativePHP Mobile (free, no license setup)
composer require nativephp/mobile:~3.3.6

# (4) MANDATORY before native:install: set the App ID in .env (reverse domain, plan it to be immutable!)
#     Add to .env:
#     NATIVEPHP_APP_ID=com.einundzwanzig.mobileapp
#     NATIVEPHP_APP_VERSION=DEBUG
#     QUEUE_CONNECTION=database

# (5) Run the installer (Android only on Linux)
php artisan native:install android
#   → ICU prompt: choose "without ICU", as long as no intl extension is needed
#     (Flux/Livewire don't need intl; ICU = +~30 MB on Android. Filament would need ICU.)
#   → creates ./nativephp/ (ephemeral!) and the script helper ./native

# (6) Extend .gitignore
printf "nativephp/\npublic/ios-hot\npublic/android-hot\n" >> .gitignore

# (7) Wire in the Vite plugin (vite.config.js, see §3.4), then build the frontend
yarn install
yarn build --mode=android

# (8) Launch the app in the emulator
php artisan native:run android        # short form after install: ./native run android
```

**Alternative for the fastest iteration without an emulator (Jump):** Install the Jump app (v2+) on your phone, put both devices on the same Wi-Fi, then run `php artisan native:jump` and scan the QR code (this works for iPhones from a Linux machine too!). Firewall: open inbound ports 3000–3003.

**Optional: Laravel Boost** for AI support: `php artisan boost:install` (also loads the NativePHP guidelines).

---

## 3) Project structure & configuration steps

### 3.1 `.env` (development)
```env
NATIVEPHP_APP_ID=com.einundzwanzig.mobileapp     # NEVER change again (bundle ID iOS+Android)
NATIVEPHP_APP_VERSION=DEBUG                       # leave like this in dev → the app bundle is always reloaded
NATIVEPHP_START_URL=/dashboard                    # initial route at app start
NATIVEPHP_DEEPLINK_SCHEME=einundzwanzig           # for later OAuth/deep-link flows
QUEUE_CONNECTION=database                         # enables the native background queue worker (ZTS PHP)
FILESYSTEM_DISK=mobile_public                     # user-generated files survive app updates
# NATIVEPHP_ANDROID_MIN_SDK=33                    # default 33; do NOT lower below 33 → Tailwind v4! (see §6)
```

### 3.2 Check/set `config/nativephp.php`
- `runtime.mode = 'persistent'` (default since 3.1, ~5–30 ms response time). For state issues: `reset_instances=true` (default), or as a last resort `gc_between_dispatches=true` or `classic` mode.
- `android`: `compile_sdk=36`, `target_sdk=36`, `min_sdk=33` (relation: compile ≥ target ≥ min; absolute minimum 26 — but see the Tailwind v4 trap §6).
- `android.status_bar_style`: `auto|light|dark` matching the Flux dark mode behavior.
- `hot_reload.watch_paths`: **add `'resources'`** (the default does not include it — Livewire views/Blade live there!): `['app','resources','routes','config','database','public']`.
- `cleanup_env_keys`: add all secrets (e.g. `APP_STORE_*`, `ANDROID_KEYSTORE_*`, API keys) — otherwise the `.env` is shipped along with the app bundle!
- `permissions` (iOS usage strings) only become relevant once plugins with permissions are added.
- Pull in any newer keys from `vendor/nativephp/mobile/config/nativephp.php` (or `php artisan vendor:publish --tag=nativephp-mobile-config --force` — caution: this overwrites your own changes).

### 3.3 Assets/branding (convention, no config)
- `public/icon.png` — PNG, **exactly 1024×1024, no transparency** (EINUNDZWANZIG logo on a full-bleed background).
- `public/splash.png` + `public/splash-dark.png` — PNG, at least **1080×1920** (portrait).

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
Always build platform-specifically: `yarn build --mode=android` (on macOS additionally `--mode=ios`).

### 3.5 Mobile layout (app shell) — `resources/views/components/layouts/app.blade.php`
- Viewport meta for a native feel + edge-to-edge:
  `<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no, viewport-fit=cover">`
- `<body class="nativephp-safe-area …">` + safe-area CSS variables (`--inset-top/-bottom/-left/-right`) for fixed headers/footers.
- Tailwind v4 variant for the keyboard in `resources/css/app.css`:
```css
@import "tailwindcss";
@custom-variant keyboard-visible (&:where(body.keyboard-visible *));
```
  → e.g. `class="… keyboard-visible:translate-y-full"` on the bottom nav.
- **EDGE components in the layout** (rendered on every request, native — no Tailwind styling possible, max. 5 bottom-nav items, required props `id/icon/label/url`):
```blade
<native:top-bar title="EINUNDZWANZIG" show-navigation-icon="false" />
<native:bottom-nav label-visibility="labeled">
    <native:bottom-nav-item id="home"    icon="home"     label="Home"    url="/dashboard" :active="request()->is('dashboard')" />
    <native:bottom-nav-item id="meetups" icon="people"   label="Meetups" url="/meetups"   :active="request()->is('meetups*')" />
    <native:bottom-nav-item id="news"    icon="newspaper" label="News"   url="/news"      :active="request()->is('news*')" />
    <native:bottom-nav-item id="profile" icon="person"   label="Profil"  url="/profile"   :active="request()->is('profile*')" />
</native:bottom-nav>
```
  Note: EDGE links make full requests (normal/fine for Livewire); URLs outside the WebView domain open in the system browser.

### 3.6 Example screens (Livewire 4 + Flux Pro)
```bash
php artisan make:livewire Pages\\Dashboard --no-interaction
php artisan make:livewire Pages\\Meetups\\Index --no-interaction
php artisan make:livewire Pages\\Profile --no-interaction
php artisan make:livewire Auth\\Login --no-interaction
```
- Routes in `routes/web.php` as full-page components; `/` → redirect to the `NATIVEPHP_START_URL` target.
- UI consistently with Flux (`flux:card`, `flux:button`, `flux:input`, `flux:navbar` etc.), dark mode via `dark:` (EDGE components automatically follow the system dark mode — align Flux to it).
- Platform branching server-side: `Native\Mobile\Facades\System::isIos()/isAndroid()` (System plugin, see §4).

### 3.7 Auth preparation (Sanctum token against the existing Portal API)
Architectural principle from the docs: **local data does not prove authentication** — auth runs against an external service (here: the existing `einundzwanzig-app` API with Sanctum).
1. Login screen (Livewire): email/password → `Http::post('https://portal…/api/v1/auth/token', …)` → token returned.
2. Token storage:
   - **With the SecureStorage plugin ($49, recommended):** `SecureStorage::set('auth_token', $token)` (Android Keystore / iOS Keychain).
   - **Without the premium plugin (fallback):** store the token encrypted with `Crypt::encryptString()` in a local SQLite/file — NativePHP generates a unique `APP_KEY` per device in the secure storage.
3. Server-side in the Portal: **enable Sanctum token expiration** (default: never expires!), short-lived tokens (< 48 h) + refresh strategy, rate limiting on the auth endpoint (there is no CSRF in an API context).
4. App-side: before API calls, check `Network::status()` (offline fallback to the local SQLite cache); 401 → re-login flow.
5. Optional later: Lightning login via `Browser::auth(...)` + `NATIVEPHP_DEEPLINK_SCHEME` (redirect `einundzwanzig://auth/handle`; register the redirect URI with the auth service in advance).

### 3.8 Database & queues
- **SQLite only**, fully automatic: NativePHP switches the connection at build time, creates the DB in the app container, and runs **pending migrations on every app start**. No remote DB access — central data exclusively via the Sanctum API (API-first, local DB = offline cache).
- Seed data as a **seed migration** (`php artisan make:migration seed_app_settings`) instead of a seeder (runs exactly once per installation).
- Jobs: regular `ShouldQueue` jobs + `QUEUE_CONNECTION=database`; the worker starts automatically in its own thread (`queue:work --once` loop). Only the `database` connection is supported; jobs survive app restarts but run primarily while the app is active (true background tasks are only on the roadmap in v3!).

### 3.9 Tests
- Pest feature tests for Livewire components as usual (`php artisan test --compact`); guard native calls in tests with `function_exists('nativephp_call')` checks or facade mocks.

---

## 4) Available native APIs (complete list)

In v3, all native features are **plugins** (Composer packages; after `composer require`, register them if needed via `php artisan native:plugin:register`, then rebuild). Usage pattern in Livewire: facade call + asynchronous result via `#[OnNative(EventClass::class)]` (from `Native\Mobile\Attributes\OnNative`).

**Free (MIT):**
1. **Browser** (`nativephp/mobile-browser`) — opens URLs in-app (Custom Tabs/SFSafariViewController), in the system browser, or as an OAuth flow with `Browser::auth()` and automatic deep-link redirect.
2. **Camera** (`nativephp/mobile-camera`) — photo capture, video recording, and gallery picker; results via `PhotoTaken`/`VideoRecorded`/`MediaSelected` events (note: an Android photo lands as a fixed `{cache}/captured.jpg` → copy it away immediately).
3. **Device** (`nativephp/mobile-device`) — vibration, flashlight, unique device ID, device info, and battery status as synchronous return values (info fields are JSON strings).
4. **Dialog** (`nativephp/mobile-dialog`) — native alert dialogs with buttons (`ButtonPressed` event) and toasts/snackbars (`Dialog::toast()`, synchronous).
5. **File** (`nativephp/mobile-file`) — move/copy files in the app sandbox filesystem with automatic directory creation and integrity checking.
6. **Microphone** (`nativephp/mobile-microphone`) — M4A/AAC audio recording with pause/resume, status query, and `MicrophoneRecorded` event; background recording via the `microphone_background` config.
7. **Network** (`nativephp/mobile-network`) — `Network::status()` returns connected/type (wifi/cellular/…)/isExpensive/isConstrained (pull API, no change events).
8. **Share** (`nativephp/mobile-share`) — native share sheet for URLs, text, and local files (fire-and-forget, no result event).
9. **System** (`nativephp/mobile-system`) — platform detection (`isIos/isAndroid/isMobile`), opening the OS app settings, flashlight toggle.

**Premium (proprietary, license + private Composer repo required):**
10. **Biometrics** (`nativephp/mobile-biometrics`, $49) — Face ID/Touch ID/fingerprint prompt with system PIN fallback; result as a `Completed` event with `bool $success` (a convenience, not real security — keep authorization server-side).
11. **Geolocation** (`nativephp/mobile-geolocation`, $49) — one-time position query (network or GPS) + permission management; result via `LocationReceived` event (lat/lng/accuracy/provider), no continuous tracking.
12. **Scanner** (`nativephp/mobile-scanner`, $49) — QR/barcode scanner (ML Kit/AVFoundation) with formats qr/ean/code128/…, continuous mode, and `CodeScanned` event — ideal for Lightning/LNURL QR codes.
13. **SecureStorage** (`nativephp/mobile-secure-storage`, $49) — encrypted key-value store (iOS Keychain / Android EncryptedSharedPreferences, AES-256-GCM) for tokens & secrets (small strings only, device-only).
14. **Firebase Push** (`nativephp/mobile-firebase`, $99) — push notifications via FCM (Android) + APNs routing (iOS) including `enroll()/getToken()`, `TokenGenerated`/`PushNotificationReceived` events, data-only messages, deep-link navigation, and the test command `fcm:send`.

**Framework capabilities (included in the core):**
15. **EDGE components** — true native UI from Blade: `<native:top-bar>` (title + max. 10 actions), `<native:bottom-nav>` (max. 5 tabs, badges), `<native:side-nav>` (drawer with header/groups/divider), icon mapping (SF Symbols/Material Icons).
16. **Event system** — native results as Laravel events, in Livewire via `#[OnNative]`, in JS via `On()/Off()` from `#nativephp`.
17. **SQLite database** — automatically provisioned, migrations on every app start.
18. **Queues** — background queue worker on its own thread (ZTS PHP, `database` connection).
19. **Deep Links** — custom scheme (`NATIVEPHP_DEEPLINK_SCHEME`) and Universal/App Links (`NATIVEPHP_DEEPLINK_HOST` + `.well-known` verification files on the server).
20. **Secure APP_KEY & Crypt** — device-specific APP_KEY in the native secure storage; `Crypt::encryptString()` for larger local data sets.

---

## 5) Build/run/hot-reload workflow (daily dev loop)

**Variant A — Jump (fastest loop, real device, no build):**
```bash
yarn dev                          # Terminal 1: Vite with HMR (automatically proxied over port 3003)
php artisan native:jump           # Terminal 2: QR code → scan with the Jump app
# Options: --ip=192.168.x.x (with multiple interfaces), --no-mdns, ports --http-port/--ws-port/--bridge-port/--vite-proxy-port
# Bridge logs: tail -f storage/logs/jump-bridge.log
```
Most native APIs (dialogs, camera, scanner …) work in Jump; **not** reliable: anything with long-lived device state (queues/background) → use Variant B for that.

**Variant B — real build in the emulator/on device:**
```bash
yarn build --mode=android                 # ALWAYS before compiling (otherwise old assets in the bundle!)
php artisan native:run android            # builds + deploys; ./native run android as the short form
php artisan native:run android --watch    # with hot reloading (or separately: php artisan native:watch android)
php artisan native:tail                   # Laravel logs of the running Android app
php artisan native:open android           # open Android Studio with the native project (debugging)
php artisan native:debug                  # diagnostics of the mobile environment
```
- Leave `NATIVEPHP_APP_VERSION=DEBUG` in dev → the Laravel bundle is reloaded on every start.
- HMR on a real device: device + machine on the same Wi-Fi; full hot reloading works best in the emulator.
- After plugin installation/registration or a NativePHP minor update: **rebuild mandatory** (`php artisan native:install --force` + `native:run`).

---

## 6) Pitfalls & limitations

1. **Set `NATIVEPHP_APP_ID` before the first `native:install`** and never change it afterward (= the bundle ID in both stores).
2. **`nativephp/` is ephemeral** — never commit it, never edit it manually (it is lost on `native:install --force`); likewise put `public/ios-hot`/`public/android-hot` in `.gitignore`.
3. **Tailwind v4 vs. old Android WebViews:** `@theme` & modern CSS features do not run on old system WebViews. Since Flux UI Pro requires Tailwind v4: **keep `min_sdk` at 33 (Android 13)** — this aligns with the official support policy (iOS 18+/Android 13+). Test on an AVD with the minimum version.
4. **Persistent runtime:** the Laravel kernel lives across requests → singletons/static state can leak; `reset_instances` is on, for problems use `gc_between_dispatches=true` or `NATIVEPHP_RUNTIME_MODE=classic`.
5. **Use at least v3.3.5/3.3.6:** fixes for Livewire 4 ⚡ emoji files (iOS extraction), hot reload of the persistent runtime, Android POST/`$_POST` (Livewire requests!), cold-launch races.
6. **Permissions fail silently:** camera/microphone/scanner return no error when permission is denied — build UX with a timeout/fallback; enable the scanner/camera permission in `config/nativephp.php`.
7. **Native results are asynchronous:** never rely on return values from `Camera::getPhoto()`, `Biometrics::prompt()` etc. — always use `#[OnNative]` handlers; events are additionally delivered to JS in the WebView (no double processing).
8. **File paths:** `storage_path()` points **outside** the app root on mobile; serve user-visible files via the `mobile_public` disk (symlink `public/storage`), otherwise they are lost on app updates. Copy Android camera cache files away to a persistent location immediately.
9. **Migrations run on every app start:** a faulty migration in an update can destroy user data — test against a production build before release. Uninstalling the app deletes the entire SQLite DB.
10. **Secrets:** the `.env` is shipped along → put everything sensitive in `cleanup_env_keys`; keys are unique per device, Sanctum tokens short-lived; data encrypted with `Crypt` is bound to the device-specific `APP_KEY` (device loss = data undecryptable).
11. **No MySQL/PostgreSQL, no remote DB access, no Redis queue, no true background tasks** (roadmap only) — strictly API-first architecture against the Portal.
12. **EDGE limitations:** only top-bar/bottom-nav/side-nav/icons, no Tailwind styling, set the `active` state per route server-side, links = full postbacks, wrong icon names silently produce a circle icon.
13. **Jump:** guest Wi-Fi networks with client isolation don't work; work around mDNS with `--no-mdns` if needed; Jump does not replace release tests (`native:run`/`native:package`).
14. **iOS generally only on macOS** (build, simulator, packaging); push notifications don't work in the iOS simulator; WSL is not supported (irrelevant for native Linux).
15. **Plugins:** `composer require` alone is not enough — without `native:plugin:register` + rebuild there is no native code; `native:run` warns about unregistered plugins. Check premium plugins: Livewire v4 compatibility per the marketplace.

---

## 7) Updates, versioning & App Store releases

### 7.1 Package updates (nativephp/mobile)
- Pin the constraint: `"nativephp/mobile": "~3.3.6"` (tilde: patches automatically, minors deliberately).
- **Patch release** (PHP code only): `composer update` is enough — no rebuild, no store submission.
- **Minor release** (can change Kotlin/Swift): `composer update` → **`php artisan native:install --force`** (complete rebuild) → test `native:run` → a new store submission is required. Follow the migration guides.
- Update Flux Pro/Livewire normally via Composer; afterward don't forget `yarn build --mode=android`.

### 7.2 App versioning
- **Never edit manually** `NATIVEPHP_APP_VERSION` (public, SemVer recommended) and `NATIVEPHP_APP_VERSION_CODE` (internal build number, must strictly increase per store upload), but instead:
```bash
php artisan native:release patch|minor|major   # bumps version + build number in .env
php artisan native:check-build-number          # validates/suggests build numbers
```

### 7.3 Android release (Play Store) — fully possible on Linux
```bash
# One-time: generate the keystore (writes ANDROID_* into .env + .gitignore)
php artisan native:credentials android

# Test the release build
php artisan native:run android --build=release

# Signed app bundle for the Play Store
yarn build --mode=android
php artisan native:package android --build-type=bundle
#   Artifact: nativephp/android/app/build/outputs/bundle/release/app-release.aab

# Optional: direct upload (Google service account JSON required)
php artisan native:package android --build-type=bundle \
  --upload-to-play-store --play-store-track=internal \
  --google-service-key=/path/service-account.json
```
- For store builds in `config/nativephp.php`: `minify_enabled=true`, `shrink_resources=true` (smaller APK/AAB).
- With `--google-service-key` the build number is automatically incremented against the Play Store; CI: `--no-tty` + credentials as env variables (`ANDROID_KEYSTORE_FILE/_PASSWORD`, `ANDROID_KEY_ALIAS/_PASSWORD`).
- Debug keystore problems: `keytool -list -v -keystore <path>`.
- **Back up the keystore securely** — losing it = no more app updates are possible.

### 7.4 🍎 iOS release (macOS only)
- Prerequisites: Apple Developer Program, distribution certificate (.p12), provisioning profile, App Store Connect API key (.p8 — downloadable only once!).
- `php artisan native:package ios --export-method=app-store --upload-to-app-store …` with `APP_STORE_API_KEY_PATH/_ID/ISSUER_ID`, `IOS_DISTRIBUTION_CERTIFICATE_*`, `IOS_TEAM_ID` in `.env` (and in `cleanup_env_keys`!).
- Helper flags: `--validate-profile`, `--validate-only`, `--test-upload`, `--clean-caches`, `--rebuild`.

### 7.5 Important release rules
- Before every update release: test migrations against a production build (they run on the user's first start!).
- Support target: iOS 18+/Android 13+; test feature availability per OS version individually.
- Optional: NativePHP's Bifrost service takes over certificates/keystores and offers OTA updates (commercial add-on, not required).

---

## Execution checklist (short version)

1. ☐ Clarify licenses (have the Flux Pro key ready; buy premium plugins if Biometrics/Scanner/Push/SecureStorage are desired)
2. ☐ Toolchain: PHP 8.4 + gd (2G memory_limit), JDK 17, Android Studio + SDK API 36 + Build/Platform-Tools, create AVD, `JAVA_HOME`/`ANDROID_HOME`/PATH, `java -version` & `adb devices` ok
3. ☐ `laravel new einundzwanzig-mobile-app` (Livewire kit) → Flux Pro via Composer repo → `composer require nativephp/mobile:~3.3.6`
4. ☐ `.env`: `NATIVEPHP_APP_ID`, `NATIVEPHP_APP_VERSION=DEBUG`, `QUEUE_CONNECTION=database`, `NATIVEPHP_START_URL`
5. ☐ `php artisan native:install android` (without ICU) → `.gitignore` (nativephp/, *-hot)
6. ☐ `vite.config.js` with `nativephpMobile()`/`nativephpHotFile()`; `config/nativephp.php` (watch_paths + resources, cleanup_env_keys, min_sdk 33)
7. ☐ `public/icon.png` (1024², opaque) + `public/splash(.dark).png` (1080×1920)
8. ☐ App shell: layout with viewport-fit=cover, `nativephp-safe-area`, `keyboard-visible` variant, EDGE bottom-nav/top-bar; screens Dashboard/Meetups/Profile/Login (Flux)
9. ☐ Auth: Sanctum token flow against the Portal API, token storage (SecureStorage or Crypt), token expiration + rate limit server-side
10. ☐ Dev loop: `yarn build --mode=android` → `php artisan native:run android --watch`; Jump in parallel for real-device tests
11. ☐ Pest tests for Livewire components; `vendor/bin/pint --dirty`
12. ☐ Release: `native:release` → `native:credentials android` → `native:package android --build-type=bundle` → Play Store track `internal`

---

# Appendix: Completeness Review

## Completeness review: plan vs. documentation corpus

Overall verdict: The plan is very complete and correct on the critical points (App ID, ephemeral `nativephp/`, Tailwind v4/min_sdk, async events, plugin licenses, release flow). However, there are a few concrete corrections and gaps:

### Corrections (stated incorrectly or imprecisely)

1. **Plugin registration is mandatory, not "if needed" — and one step is missing:** Before the first registration, the provider must be published: `php artisan vendor:publish --tag=nativephp-plugins-provider` (creates `app/Providers/NativeServiceProvider.php`). Only then `php artisan native:plugin:register vendor/plugin-name`. This publish step is missing entirely from the plan (the §4 intro only says "register if needed"). The free core plugins (e.g. Camera) also have to be registered explicitly per the plugin docs.
2. **Rebuild after plugin installation:** The docs (Using Plugins) require only a rebuild via `php artisan native:run` after registration — not `native:install --force`. According to the docs, `--force` is necessary for minor upgrades of nativephp/mobile and for changes to the native code of local plugins. Plan gotcha 15 is stricter here than the docs (not harmful, but incorrectly justified).
3. **`hot_reload.watch_paths` claim contradicts the docs:** The Configuration page names the default as `['app','resources','routes','config','public']` — so per the config reference `resources` is already included; only the example on the Development page shows it without `resources`. The plan's claim "the default does not include it" should be softened to "check in the vendor default, add if needed".
4. **WS port details:** The config default for `server.ws_port` is **8081** per the Configuration page, while `native:jump --ws-port` uses default 3001. The plan generally names "ports 3000–3003" — when using the config defaults (without flags), 8081 may also be relevant.
5. **Firebase plugin (plan §4 no. 14) incomplete/partly inaccurate:** The mandatory setup steps are missing: `google-services.json` (Android) and `GoogleService-Info.plist` (iOS) in the **project root**, the service account JSON via env `FIREBASE_CREDENTIALS`, as well as `checkPermission()` (status granted/denied/not_determined/provisional/ephemeral; recommended flow: check first, UI explanation, then `enroll()`). An important architectural point is missing: `#[OnNative]` only fires **in the foreground when the component is mounted**; for background processing of data-only messages you need a classic `Event::listen()` listener in the ServiceProvider (runs in the ephemeral PHP runtime, background-safe). Furthermore: the Android permission dialog only from API 33 (`POST_NOTIFICATIONS`), the `clearBadge()` platform difference, and notifications **with** a `notification` block do not trigger a PHP event (only data-only with an `event` key).

### Missing documented points (additions)

6. **Official starter kit as an alternative:** `laravel new my-app --using=nativephp/mobile-starter` (Quick Start page) is not mentioned in the plan — a deliberate decision for the Livewire kit would be fine, but it should be mentioned as an alternative.
7. **ICU non-interactive:** Instead of the prompt, you can use `php artisan native:install android --without-icu` (fits the intended scriptable flow; plan convention `--no-interaction`).
8. **`JUMP_BRIDGE_PORT=3002`:** Mandatory env when running your own Laravel server with `--no-serve` — missing in §5 Variant A.
9. **Deep-link gotchas (§4 no. 19):** Associated Domains typically do **not** work in the simulator; the OS **caches the verification result** (for problems delete + reinstall the app); the custom scheme must be unique, `https` among others is reserved. Furthermore, the docs document **no** PHP API for handling incoming deep links — the plan should not silently assume one.
10. **Config keys in §3.2 incomplete:** `orientation.android` (default: portrait only — relevant if landscape is desired) and `cleanup_exclude_files` (remove logs/temp files before bundling) are missing. `ipad => true` including the "Once iPad, Always iPad" trap is missing — irrelevant on Linux/Android, but irreversible for a later iOS release.
11. **Token strategy:** The docs specifically recommend **single-use refresh tokens (~30 days)** in addition to short-lived auth tokens — the plan only generically says "refresh strategy".
12. **App boot behavior:** On every app start, not only migrations run, but also **cache clearing and creation of the storage symlinks** (Overview page) — relevant for the mental model (e.g. don't plan for persistent caches).
13. **Missing commands:** `native:version` (installed version), `native:plugin:list` (shows registered plugins **including required permissions** — useful for Play Store privacy declarations), `native:run --start-url=` (override the start URL per run), `native:jump --laravel-port=`.
14. **Plugin minimum versions:** All core/premium plugins require **iOS 18.2+ and Android API 26+** per the plugin pages — unproblematic with min_sdk 33, but it belongs in the plugin purchase decision in §0.
15. **JS bridge integration:** If native calls from Alpine/JS are desired: the typed JS library is integrated **not via npm**, but via a `package.json` `imports` entry (`"#nativephp": "./vendor/nativephp/mobile/resources/dist/native.js"`) — the Composer install must be present before the JS build. Missing from the plan (optional for the pure Livewire path).
16. **Top-bar details (§3.5):** Max. 10 actions is stated in the plan, but not the overflow behavior (Android: only the first 3 as icon buttons, the rest in the ⋮ menu; iOS: overflow above >5) and that `label` serves as the display text there; `elevation` only takes effect on Android; `subtitle` is used in the doc example but is not officially documented in the props list.
17. **Geolocation permission events (§4 no. 11):** Besides `LocationReceived`, there are `PermissionStatusReceived` and `PermissionRequestResult` with the special value `permanently_denied` (the user must be directed to the system settings → via `System::appSettings()`). Missing from the plan.
18. **Pest/PHPUnit note is correct, but more concretely:** The docs explicitly name the `function_exists('nativephp_call')` guard as a pattern so that code degrades cleanly in the web/test context — the plan mentions it but should also prescribe it for your own service wrappers (token storage fallback).

### Minor points

- §1.1 "NativePHP bundles PHP 8.4" + "PHP 8.3–8.5": correct per the docs (Overview vs. Changelog), wording fine.
- ICU size figure: +~30 MB applies to Android, iOS would be +~100 MB — irrelevant in an Android-only plan, but worth mentioning in the iOS section.
- The versioning doc example constraint is `~2.0.0` (outdated example); the plan does it correctly with `~3.3.6`.

No point in the plan is seriously wrong; the most important fixes are **(1) publishing the NativeServiceProvider + mandatory plugin registration**, **(5) Firebase setup details including the foreground/background event difference**, and **(3) the watch_paths claim**.

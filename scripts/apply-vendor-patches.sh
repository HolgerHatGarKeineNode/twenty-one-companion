#!/usr/bin/env bash
#
# Wendet die OPTIMIZE-Bootzeit-Patches idempotent auf die NativePHP-Kotlin-Dateien an.
#
# ZWEI ZIELE:
#   1. vendor/nativephp/mobile/resources/androidstudio/…  (Template — Quelle beim
#      ERSTEN Scaffold; git-ignored, wird von composer install überschrieben)
#   2. nativephp/android/…  (generiertes Projekt, das gradlew BAUT — native:run
#      regeneriert es NICHT, wenn es schon existiert, also hier direkt patchen)
#
# NUTZUNG: nach composer install/update UND vor jedem gradlew-Build ausführen:
#   bash scripts/apply-vendor-patches.sh
#
# Idempotent. Siehe OPTIMIZE.md.
set -euo pipefail
cd "$(dirname "$0")/.."

REL_ENV="app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt"
REL_MAIN="app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt"
REL_WEBVIEW="app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt"
REL_ICONBG="app/src/main/res/drawable/ic_launcher_background.xml"
REL_GRADLE="app/build.gradle.kts"

# Drittes Ziel, PHP statt Kotlin und OHNE generiertes Pendant: der Deeplink-Patch
# sitzt im Vendor-Quelltext selbst, weil dieser das AndroidManifest bei jedem
# native:run/native:package neu erzeugt.
DEEPLINK_PHP="vendor/nativephp/mobile/src/Traits/RunsAndroid.php"

# (Basisverzeichnis, Label) — nur existierende werden gepatcht.
TARGETS=(
  "vendor/nativephp/mobile/resources/androidstudio|Template (vendor)"
  "nativephp/android|Build (nativephp)"
)

patch_env() {  # $1 = Pfad zu LaravelEnvironment.kt
  local f="$1"
  # Phase 3: opcache.file_cache in die on-device php.ini. Ein awk-Pass, zwei Anker:
  # mkdirs vor `val phpIni = """`, die opcache-Direktiven nach der openssl.cafile-Zeile.
  # (config:cache/view:cache/event:cache bewusst NICHT gepatcht — config:cache friert
  # nativephp-internal.running=false ein und sperrt den Chat; siehe OPTIMIZE.md Phase 5.)
  if ! grep -q 'opcache.file_cache' "$f"; then
    awk '
      /val phpIni = """/ && !d1 {
        print "                File(context.filesDir, \"opcache\").mkdirs() // OPTIMIZE"; d1=1 }
      /openssl\.cafile=/ && !d2 {
        print
        print "opcache.enable=1"; print "opcache.enable_cli=1"
        print "opcache.file_cache=\"${context.filesDir.absolutePath}/opcache\""
        print "opcache.file_cache_only=1"; print "opcache.validate_timestamps=0"
        d2=1; next }
      { print }
    ' "$f" > "$f.tmp" && mv "$f.tmp" "$f"
    grep -q 'opcache.file_cache' "$f" && grep -q 'mkdirs() // OPTIMIZE' "$f" \
      || { echo "FEHLER: opcache-Patch griff nicht ($f) — Anker gedriftet (NativePHP-Update?)."; exit 1; }
    echo "    [+] Phase 3 opcache.file_cache"
  fi
  # Phase 3b: opcache-file_cache bei JEDER Bundle-Extraktion wipen. Sonst serviert
  # opcache (validate_timestamps=0) nach einem App-Update stalen Bytecode der
  # Vorversion (gleiche Dateipfade, neuer Inhalt) — u.a. kompilierte Blades mit
  # veralteten @vite-Refs -> ViteException/500. Versions-scoped statt per-Request-stat.
  # rm -rf statt File.deleteRecursively(): der Codebase misstraut deleteRecursively
  # (folgt dem storage-Symlink -> löscht persisted_data, siehe Kommentar in extract).
  # Der opcache-Ordner hat zwar keine Symlinks, aber wir nutzen den vertrauten Weg.
  if ! grep -q 'OPTIMIZE-opcache-wipe' "$f"; then
    awk '
      /val didExtract = extractLaravelBundle\(\)/ && !d {
        print
        print "            if (didExtract) runCatching { Runtime.getRuntime().exec(arrayOf(\"rm\", \"-rf\", File(context.filesDir, \"opcache\").absolutePath)).waitFor() } // OPTIMIZE-opcache-wipe: kein stale Bytecode bei Updates"
        d=1; next }
      { print }
    ' "$f" > "$f.tmp" && mv "$f.tmp" "$f"
    grep -q 'OPTIMIZE-opcache-wipe' "$f" \
      || { echo "FEHLER: opcache-Wipe-Patch griff nicht ($f) — Anker gedriftet (NativePHP-Update?)."; exit 1; }
    echo "    [+] Phase 3b opcache-Wipe bei Extraktion"
  fi
  # EXTRACT-GATE-FIX: NativePHP-Bug — das Bundle wird nach der ersten Extraktion
  # NIE wieder entpackt, d.h. jede Blade-/PHP-Änderung ist auf dem Gerät unsichtbar.
  #
  # Ursache: der Gate vergleicht ZWEI QUELLEN, die um genau 1 auseinanderlaufen.
  #   embeddedId := bundle_meta.json          → N
  #   currentId  := extrahierte .env          → N+1
  # `native:package` erhöht NATIVEPHP_APP_VERSION_CODE in .env VOR dem Zippen, also
  # trägt die gebundelte .env immer meta+1. Nach der Extraktion von Build N steht in
  # laravel/.env N+1 — und Build N+1 liefert embeddedId N+1. Beide gleich →
  # isUpToDate=true → shouldExtract=false. Da der Build immer um 1 zählt, greift das
  # bei JEDEM Folge-Build. (Produktion merkt es nicht: dort ändert sich der
  # Versions-STRING mit, also unterscheiden sich die Ids trotzdem.)
  #
  # Fix: nur noch EINE Quelle. `.version` (wird ohnehin geschrieben, aber nie gelesen)
  # trägt künftig die embeddedId, und der Gate vergleicht dagegen.
  if ! grep -q 'EXTRACT-GATE-FIX' "$f"; then
    # Der äußere if/else-Block endet auf einem `}` mit GENAU 8 Spaces Einrückung —
    # daran wird verankert. Ein non-greedy `.*?} else {` träfe sonst das innere.
    perl -0777 -i -pe '
      s{
        ^\ {8}val\ currentId\ =\ if\ \(laravelDir\.exists\(\)\)\ \{\n
        .*?
        ^\ {8}\}\n
      }{        val currentId = File(laravelDir, VERSION_FILE).takeIf { it.exists() }?.readText()?.trim()?.ifEmpty { null } // EXTRACT-GATE-FIX: gegen .version (embeddedId) statt gegen die .env (die trägt meta+1)\n}smx;
      s{
        val\ installedId\ =\ buildVersionId\(getVersionFromEnvFile\(envFile\),\ getVersionCodeFromEnvFile\(envFile\)\)
      }{val installedId = embeddedId // EXTRACT-GATE-FIX: dieselbe Quelle wie der Vergleich}x;
    ' "$f"
    grep -q 'EXTRACT-GATE-FIX: gegen .version' "$f" && grep -q 'EXTRACT-GATE-FIX: dieselbe Quelle' "$f" \
      || { echo "FEHLER: Extract-Gate-Patch griff nicht ($f) — Anker gedriftet (NativePHP-Update?)."; exit 1; }
    echo "    [+] Extract-Gate-Fix (Bundle wird bei jedem version_code-Bump neu entpackt)"
  fi
}

patch_iconbg() {  # $1 = Pfad zu ic_launcher_background.xml
  local f="$1"
  # Adaptive-Icon-Hintergrund von weiß (NativePHP-Default) auf schwarz. Der
  # Foreground ist eine schwarze Rundecken-Form mit transparenten Ecken — auf
  # weißem BG scheinen dort weiße Ecken durch (Bug-Report). Schwarz macht es nahtlos.
  if grep -q '#ffffff' "$f"; then
    sed -i 's/#ffffff/#000000/' "$f"
    grep -q '#000000' "$f" \
      || { echo "FEHLER: Icon-BG-Patch griff nicht ($f) — Datei geändert (NativePHP-Update?)."; exit 1; }
    echo "    [+] Icon-Hintergrund schwarz (#000000)"
  fi
}

patch_main() {  # $1 = Pfad zu MainActivity.kt
  local f="$1"
  # Phase 4: Queue-Worker-Doppelboot verzögern
  if ! grep -q 'postDelayed({ queueWorker' "$f"; then
    perl -i -pe 's/queueWorker = PHPQueueWorker\(phpBridge\)\.also \{ it\.start\(\) \}/queueWorker = PHPQueueWorker(phpBridge) \/\/ OPTIMIZE Phase 4\n                    Handler(Looper.getMainLooper()).postDelayed({ queueWorker?.start() }, 6000)/' "$f"
    grep -q 'postDelayed({ queueWorker' "$f" \
      || { echo "FEHLER: Queue-Worker-Patch griff nicht ($f) — Anker gedriftet (NativePHP-Update?)."; exit 1; }
    echo "    [+] Phase 4 Queue-Worker +6s verzögert"
  fi
}

patch_filechooser_webview() {  # $1 = Pfad zu WebViewManager.kt
  local f="$1"
  # Der NativePHP-WebView verdrahtet onShowFileChooser NICHT → ein HTML-
  # <input type=file> öffnet auf dem Gerät nichts (Android-Default gibt false
  # zurück). Das lähmt u.a. den Chat-„Bild anhängen"-Button. Fix: Callback im
  # companion object halten + Override, der den nativen Picker via
  # FileChooserParams.createIntent() über die Activity startet.
  if ! grep -q 'FILE_CHOOSER_REQUEST_CODE' "$f"; then
    awk '
      /var shared: WebViewManager\? = null/ && !d {
        print
        print "        var fileChooserCallback: ValueCallback<Array<Uri>>? = null // FILECHOOSER"
        print "        const val FILE_CHOOSER_REQUEST_CODE = 51426 // FILECHOOSER"
        d=1; next }
      { print }
    ' "$f" > "$f.tmp" && mv "$f.tmp" "$f"
    grep -q 'FILE_CHOOSER_REQUEST_CODE' "$f" \
      || { echo "FEHLER: FileChooser-Companion-Patch griff nicht ($f) — Anker gedriftet (NativePHP-Update?)."; exit 1; }
    echo "    [+] FileChooser Companion-Halter"
  fi
  if ! grep -q 'onShowFileChooser' "$f"; then
    awk '
      /return object : WebChromeClient\(\) \{/ && !d {
        print
        print "            override fun onShowFileChooser("
        print "                webView: WebView?,"
        print "                filePathCallback: ValueCallback<Array<Uri>>?,"
        print "                fileChooserParams: FileChooserParams?"
        print "            ): Boolean {"
        print "                WebViewManager.fileChooserCallback?.onReceiveValue(null)"
        print "                WebViewManager.fileChooserCallback = filePathCallback"
        print "                return try {"
        print "                    (context as? Activity)?.startActivityForResult("
        print "                        fileChooserParams?.createIntent(), WebViewManager.FILE_CHOOSER_REQUEST_CODE"
        print "                    )"
        print "                    true"
        print "                } catch (e: Exception) {"
        print "                    WebViewManager.fileChooserCallback = null"
        print "                    false"
        print "                }"
        print "            }"
        d=1; next }
      { print }
    ' "$f" > "$f.tmp" && mv "$f.tmp" "$f"
    grep -q 'onShowFileChooser' "$f" \
      || { echo "FEHLER: onShowFileChooser-Patch griff nicht ($f) — Anker gedriftet (NativePHP-Update?)."; exit 1; }
    echo "    [+] onShowFileChooser-Override"
  fi
}

patch_filechooser_main() {  # $1 = Pfad zu MainActivity.kt
  local f="$1"
  # Ergebnis des FileChooser-Intents zurück an den WebView-Callback routen.
  # MainActivity hat (Stand 3.x) kein onActivityResult — die Kamera nutzt eigene
  # Launcher. Darum hier eines ergänzen, das nur unseren Request-Code behandelt.
  if ! grep -q 'FILE_CHOOSER_REQUEST_CODE' "$f"; then
    awk '
      /class MainActivity : FragmentActivity\(\), WebViewProvider \{/ && !d {
        print
        print "    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {"
        print "        super.onActivityResult(requestCode, resultCode, data)"
        print "        if (requestCode == WebViewManager.FILE_CHOOSER_REQUEST_CODE) {"
        print "            val results = WebChromeClient.FileChooserParams.parseResult(resultCode, data)"
        print "            WebViewManager.fileChooserCallback?.onReceiveValue(results)"
        print "            WebViewManager.fileChooserCallback = null"
        print "        }"
        print "    }"
        d=1; next }
      { print }
    ' "$f" > "$f.tmp" && mv "$f.tmp" "$f"
    grep -q 'FILE_CHOOSER_REQUEST_CODE' "$f" \
      || { echo "FEHLER: onActivityResult-FileChooser-Patch griff nicht ($f) — Anker gedriftet (NativePHP-Update?)."; exit 1; }
    echo "    [+] onActivityResult FileChooser-Routing"
  fi
}

patch_gradle_profileable() {  # $1 = Pfad zu app/build.gradle.kts
  local f="$1"
  # Eine release-optimierte, aber PROFILIERBARE Variante — aus NativePHP 4.3.0
  # zurueckportiert (dort resources/androidstudio/app/build.gradle.kts:98-115).
  # Wir bleiben auf 3.3.7 (siehe docs/plans/…-nativephp-ernte…), aber dieser Block
  # haengt an nichts Versionsspezifischem: er ist reines AGP.
  #
  # Wozu: `isProfileable` injiziert <profileable shell="true"> NUR fuer diese Variante,
  # sodass Macrobenchmark, simpleperf und Perfetto sich an einen Build haengen koennen,
  # der dem ausgelieferten entspricht. Debug-signiert, damit `adb install` reicht — kein
  # Release-Keystore, kein manuelles zipalign/apksigner.
  #
  # Warum immer R8: ein unminifizierter Profil-Build misst einen Kaltstart, den kein
  # Nutzer je sieht. Upstream beziffert den Unterschied mit ~58 MB Dex gegen ~9 MB und
  # ~+90 ms bindApplication auf einem Pixel 9.
  #
  # WARUM ALS PATCH und nicht von Hand: `native:install` loescht nativephp/android
  # vollstaendig. Eine Hand-Aenderung an build.gradle.kts ueberlebt das nicht.
  if grep -q 'create("profileable")' "$f"; then
    echo "    [=] profileable-Variante bereits vorhanden"
    return 0
  fi
  # Anker: das Ende des debug-Blocks innerhalb von buildTypes. Wir haengen die neue
  # Variante direkt davor an das schliessende `}` von buildTypes.
  if ! grep -qE '^\s*debug \{' "$f"; then
    echo "    [!] buildTypes/debug-Anker nicht gefunden in $f" >&2
    echo "        Ohne die profileable-Variante ist die Bootzeit nicht am echten" >&2
    echo "        Release-Pfad messbar (siehe P2 im Plan)." >&2
    exit 1
  fi
  perl -0777 -i -pe '
    s{(\n)(\s*)(debug \{.*?\n\2\})(\n\s*\})}
     {$1$2$3$1$2// OPTIMIZE-PROFILEABLE: aus NativePHP 4.3.0 zurueckportiert.\n$2// Release-optimiert, aber fuer Macrobenchmark/simpleperf/Perfetto\n$2// attachbar. Debug-signiert, damit `adb install` genuegt.\n$2// Bauen mit: ./gradlew assembleProfileable\n$2create("profileable") \{\n$2    initWith(getByName("release"))\n$2    isDebuggable = false\n$2    isProfileable = true\n$2    // Immer R8 — ein unminifizierter Build misst einen Kaltstart,\n$2    // den kein Nutzer je sieht.\n$2    isMinifyEnabled = true\n$2    signingConfig = signingConfigs.getByName("debug")\n$2    matchingFallbacks += listOf("release")\n$2\}$4}s' "$f"
  grep -q 'create("profileable")' "$f" || { echo "    [!] profileable-Patch griff nicht" >&2; exit 1; }
  echo "    [+] profileable-Build-Variante ergaenzt"
}

patch_gradle_strip() {  # $1 = Pfad zu app/build.gradle.kts
  local f="$1"
  # AGP strippt native Bibliotheken beim Verpacken — der Task `stripReleaseDebugSymbols`
  # laeuft auch. `keepDebugSymbols.add("**/*.so")` macht ihn wirkungslos: das Ergebnis in
  # intermediates/stripped_native_libs/ meldet trotzdem "not stripped".
  #
  # Gemessen am 2026-08-28 in libphp_wrapper.so (32,17 MB unkomprimiert im APK):
  #   .symtab 1,83 MB · .strtab 1,10 MB · dazu .debug_info/-str/-line/-loc/-ranges
  # Auch libc++_shared.so ist ungestrippt. Die uebrigen vier .so kommen bereits
  # gestrippt aus ihren Quellen — der Patch aendert an ihnen nichts.
  #
  # Warum das gefahrlos ist: strip entfernt `.symtab`, `.strtab` und die `.debug_*`-
  # Sektionen. Die DYNAMISCHE Symboltabelle `.dynsym`, ueber die dlopen/dlsym und der
  # Linker aufloesen, bleibt unangetastet — PHPs Extension-Laden haengt an ihr.
  #
  # Warum wir die Symbole nicht brauchen: es gibt in diesem Projekt KEIN
  # Crash-Reporting (kein Crashlytics/Sentry/Bugsnag/ACRA — gegengeprueft), und es gibt
  # keine Play Console, die eine Symboldatei entgegennaehme. Ein nativer Stacktrace
  # waere ohnehin nur lokal per ndk-stack auswertbar, und dafuer steht die
  # unstrippierte Binary im Build-Ordner weiterhin bereit.
  if grep -q 'OPTIMIZE-STRIP' "$f"; then
    echo "    [=] keepDebugSymbols bereits eingeschraenkt"
    return 0
  fi
  local anchor='keepDebugSymbols.add("**/*.so")'
  if ! grep -qF "$anchor" "$f"; then
    echo "    [!] keepDebugSymbols-Anker nicht gefunden in $f" >&2
    echo "        NativePHP hat das Gradle-Template geaendert. Pruefen, ob die" >&2
    echo "        Bibliotheken noch ungestrippt ausgeliefert werden:" >&2
    echo "        file <apk-entpackt>/lib/arm64-v8a/libphp_wrapper.so" >&2
    exit 1
  fi
  perl -0777 -i -pe 's{^([ \t]*)\Qkeep\EDebugSymbols\.add\("\*\*/\*\.so"\)[ \t]*$}
                     {$1// OPTIMIZE-STRIP: Zeile bewusst entfernt — sie machte den\n$1// stripReleaseDebugSymbols-Task wirkungslos und lieferte 2,9 MB\n$1// Symboltabellen mit aus. Begruendung in scripts/apply-vendor-patches.sh.}mx' "$f"
  grep -q 'OPTIMIZE-STRIP' "$f" || { echo "    [!] Strip-Patch griff nicht" >&2; exit 1; }
  echo "    [+] keepDebugSymbols entfernt (native Bibliotheken werden gestrippt)"
}

patch_deeplinks() {  # $1 = Pfad zu RunsAndroid.php
  local f="$1"
  # NativePHP beansprucht bei gesetztem NATIVEPHP_DEEPLINK_HOST IMMER den ganzen
  # Host (pathPrefix="/"). Das faengt die eigenen Browser::inApp/open-Aufrufe der
  # App ins Portal wieder ab — die mobile Login-Seite liesse sich nie im Browser
  # oeffnen —, und der Signer-Callback /auth/mobile/signed/… traegt das komplette
  # URL-kodierte Event: ihn in den eingebetteten WebView zu laden, crasht ihn
  # (SIGILL). Deshalb nur die Pfade aus config('nativephp.deeplink_path_prefixes').
  #
  # Dieser Patch existierte seit 6e93763 nur von Hand und stand in KEINEM Skript.
  # Am 2026-08-28 hat `composer update` (nativephp/mobile 3.3.6 -> 3.3.7) ihn
  # ueberschrieben, und das frisch gebaute v1.9.4-APK trug wieder pathPrefix="/",
  # waehrend das ausgelieferte v1.9.3 noch "/app/" hatte. Nachweis am Artefakt:
  #   aapt2 dump xmltree <apk> --file AndroidManifest.xml | grep pathPrefix
  if grep -q 'deeplink_path_prefixes' "$f"; then
    echo "    [=] Deeplink-Pfade bereits eingeschraenkt"
    return 0
  fi
  if ! grep -qF 'android:pathPrefix="/" />' "$f"; then
    echo "    [!] Deeplink-Anker nicht gefunden in $f" >&2
    echo "        NativePHP hat generateDeepLinkFilters() geaendert. Der Patch MUSS" >&2
    echo "        von Hand nachgezogen werden, sonst beansprucht die App den ganzen" >&2
    echo "        Portal-Host als App-Link (WebView-Crash beim Signer-Callback)." >&2
    exit 1
  fi
  perl -0777 -i -pe '
    # 1. Die eine <data>-Zeile durch die interpolierte Liste ersetzen.
    s{^[ \t]*<data android:scheme="https" android:host="\{\$host\}" android:pathPrefix="/" />[ \t]*$}
     {\{\$dataTags\}}m;
    # 2. Die Berechnung direkt vor den XML-Heredoc des $host-Zweigs setzen.
    s{(if \(\$host\) \{\n)(\s*)(\$filters\[\] = <<<XML)}
     {$1$2// PATCH (twenty-one-companion, scripts/apply-vendor-patches.sh):\n$2// nur die konfigurierten Pfade beanspruchen statt des ganzen Hosts.\n$2\$prefixes = config('"'"'nativephp.deeplink_path_prefixes'"'"') ?: ['"'"'/'"'"'];\n$2\$dataTags = implode("\\n", array_map(\n$2    fn (\$prefix) => '"'"'                <data android:scheme="https" android:host="'"'"'\n$2        .\$host.'"'"'" android:pathPrefix="'"'"'.\$prefix.'"'"'" />'"'"',\n$2    \$prefixes\n$2));\n$2$3}s;
  ' "$f"
  php -l "$f" >/dev/null || { echo "    [!] Patch erzeugt ungueltiges PHP" >&2; exit 1; }
  grep -q 'deeplink_path_prefixes' "$f" || { echo "    [!] Patch griff nicht" >&2; exit 1; }
  echo "    [+] Deeplink-Pfade auf config('nativephp.deeplink_path_prefixes') eingeschraenkt"
}

any=0
for entry in "${TARGETS[@]}"; do
  base="${entry%%|*}"; label="${entry##*|}"
  env_f="$base/$REL_ENV"; main_f="$base/$REL_MAIN"
  if [ -f "$env_f" ] && [ -f "$main_f" ]; then
    echo "  $label:"
    patch_env "$env_f"
    patch_main "$main_f"
    patch_filechooser_main "$main_f"
    [ -f "$base/$REL_WEBVIEW" ] && patch_filechooser_webview "$base/$REL_WEBVIEW"
    [ -f "$base/$REL_ICONBG" ] && patch_iconbg "$base/$REL_ICONBG"
    [ -f "$base/$REL_GRADLE" ] && patch_gradle_strip "$base/$REL_GRADLE"
    [ -f "$base/$REL_GRADLE" ] && patch_gradle_profileable "$base/$REL_GRADLE"
    any=1
  else
    echo "  $label: übersprungen (nicht vorhanden)"
  fi
done
[ $any -eq 1 ] || { echo "Kein Ziel gefunden — composer install / native:run gelaufen?"; exit 1; }

if [ -f "$DEEPLINK_PHP" ]; then
  echo "  Deeplinks (vendor PHP):"
  patch_deeplinks "$DEEPLINK_PHP"
else
  echo "  Deeplinks (vendor PHP): übersprungen (nicht vorhanden)"
fi
echo "Fertig."

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

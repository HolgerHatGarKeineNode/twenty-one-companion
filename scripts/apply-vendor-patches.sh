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
#
# NativePHP 4.x hat den Trait nach src/Concerns/ verschoben (gemessen an 4.3.1:
# src/Traits/ existiert dort nicht mehr). Der Anker im Trait selbst ist unveraendert.
DEEPLINK_PHP="vendor/nativephp/mobile/src/Concerns/RunsAndroid.php"

# (Basisverzeichnis, Label) — nur existierende werden gepatcht.
TARGETS=(
  "vendor/nativephp/mobile/resources/androidstudio|Template (vendor)"
  "nativephp/android|Build (nativephp)"
)

# Zustand des opcache-Wipes in einer LaravelEnvironment.kt, als drei Zahlen:
#   <Extraktionsstellen> <Wipe-Zeilen> <Wipe-Zeilen innerhalb von fun initialize()>
# Die dritte Zahl ist die eigentliche Aussage: NUR `fun initialize()` ist der
# Kaltstart-Pfad (MainActivity.kt ruft ihn zweimal). Eine Wipe-Zeile irgendwo
# sonst in der Datei beweist nichts.
opcache_wipe_zustand() {  # $1 = Pfad zu LaravelEnvironment.kt
  awk '
    /^[ \t]*fun initialize\(\) \{/ { match($0, /^[ \t]*/); ein = substr($0, 1, RLENGTH); in_init = 1 }
    in_init && $0 == (ein "}") { in_init = 0 }
    /val didExtract = extractLaravelBundle(Unlocked)?\(\)/ { anker++ }
    /OPTIMIZE-opcache-wipe/ { wipes++; if (in_init) kalt++ }
    END { printf "%d %d %d\n", anker+0, wipes+0, kalt+0 }
  ' "$1"
}

patch_opcache_wipe() {  # $1 = Pfad zu LaravelEnvironment.kt
  local f="$1"
  # Phase 3b: opcache-file_cache bei JEDER Bundle-Extraktion wipen. Sonst serviert
  # opcache (validate_timestamps=0) nach einem App-Update stalen Bytecode der
  # Vorversion (gleiche Dateipfade, neuer Inhalt) — u.a. kompilierte Blades mit
  # veralteten @vite-Refs -> ViteException/500. Versions-scoped statt per-Request-stat.
  # rm -rf statt File.deleteRecursively(): der Codebase misstraut deleteRecursively
  # (folgt dem storage-Symlink -> löscht persisted_data, siehe Kommentar in extract).
  # Der opcache-Ordner hat zwar keine Symlinks, aber wir nutzen den vertrauten Weg.
  #
  # ANKER NACHGEZOGEN am 2026-09-01 (NativePHP 4.3.1) — und das ist der Grund, warum
  # diese Funktion so viel mehr prueft als "steht der Marker drin":
  # Unter 3.3.7 sass `val didExtract = extractLaravelBundle()` bei :172 IM Kaltstart-
  # Pfad, der Patch griff dort. 4.3.1 hat den Pfad umgebaut auf
  # `extractionLock.withLock { extractLaravelBundleUnlocked() }` (:207-208); der alte
  # Anker traf danach nur noch `initializeForBackground()` (:1004) — eine Funktion mit
  # NULL Aufrufern in 4.3.1 und im generierten Projekt (gegengeprueft mit
  # `initializeEnvironmentAsync` als Positivkontrolle: Definition UND Aufrufstelle).
  # Der Wipe deckte also keinen einzigen ausgefuehrten Pfad mehr ab — und meldete
  # weiter `[+]`. Genau die Klasse "gruenes Licht auf einem Defekt", gegen die der
  # Rest dieser Datei gebaut ist, eine Ebene hoeher.
  #
  # Deshalb wird nicht die ANWESENHEIT des Markers geprueft, sondern seine LAGE:
  # mindestens eine Wipe-Zeile muss innerhalb von `fun initialize()` stehen, und
  # jede Extraktionsstelle muss eine tragen (sonst waere es ein Halbstand). Die
  # Idempotenz-Abfrage prueft dasselbe — ein alter, falsch platzierter Wipe darf
  # nicht als "bereits vorhanden" durchgehen, sonst zementiert der naechste Lauf
  # genau den Zustand, der hier behoben wird.
  local anker wipes kalt alt=0 fehler=""
  read -r anker wipes kalt <<<"$(opcache_wipe_zustand "$f")"
  if [ "$kalt" -ge 1 ] && [ "$wipes" -eq "$anker" ]; then
    echo "    [=] Phase 3b opcache-Wipe bereits vorhanden"
    return 0
  fi
  cp "$f" "$f.vor-patch"
  # Reste einer frueheren Skriptfassung entfernen, bevor neu eingesetzt wird. Die
  # Wipe-Zeile ist immer eine GANZE Zeile mit diesem Marker und stammt immer aus
  # diesem Skript — Vendor-Code traegt ihn nie. Ohne diesen Schritt stuende der
  # tote Wipe aus 3.3.7 zusaetzlich zum neuen in der Datei.
  if [ "$wipes" -gt 0 ]; then
    alt=1
    { grep -v 'OPTIMIZE-opcache-wipe' "$f" || true; } > "$f.tmp" && mv "$f.tmp" "$f"
  fi
  awk '
    /val didExtract = extractLaravelBundle(Unlocked)?\(\)/ {
      print
      match($0, /^[ \t]*/)
      print substr($0, 1, RLENGTH) "if (didExtract) runCatching { Runtime.getRuntime().exec(arrayOf(\"rm\", \"-rf\", File(context.filesDir, \"opcache\").absolutePath)).waitFor() } // OPTIMIZE-opcache-wipe: kein stale Bytecode bei Updates"
      next }
    { print }
  ' "$f" > "$f.tmp" && mv "$f.tmp" "$f"
  read -r anker wipes kalt <<<"$(opcache_wipe_zustand "$f")"
  if [ "$anker" -eq 0 ]; then
    fehler="keine Extraktionsstelle gefunden (val didExtract = extractLaravelBundle[Unlocked]())"
  elif [ "$kalt" -eq 0 ]; then
    fehler="der Wipe landet ausserhalb von fun initialize() — der Kaltstart-Pfad bliebe ungedeckt"
  elif [ "$wipes" -ne "$anker" ]; then
    fehler="nur $wipes von $anker Extraktionsstellen gedeckt — Halbstand"
  fi
  if [ -n "$fehler" ]; then
    mv "$f.vor-patch" "$f"
    echo "FEHLER: opcache-Wipe-Patch griff nicht ($f) — $fehler." >&2
    echo "        NativePHP hat den Extraktionspfad umgebaut. Der Wipe MUSS im" >&2
    echo "        Kaltstart-Pfad sitzen: ohne ihn ueberlebt nach einem App-Update" >&2
    echo "        der Bytecode der Vorversion (validate_timestamps=0, file_cache in" >&2
    echo "        filesDir) und trifft auf ein neues Bundle." >&2
    echo "        Datei auf den Vendor-Stand zurueckgesetzt — kein Halbstand." >&2
    exit 1
  fi
  rm -f "$f.vor-patch"
  if [ "$alt" -eq 1 ]; then
    echo "    [+] Phase 3b opcache-Wipe neu gesetzt (alte Lage deckte den Kaltstart-Pfad nicht)"
  else
    echo "    [+] Phase 3b opcache-Wipe bei Extraktion"
  fi
}

patch_env() {  # $1 = Pfad zu LaravelEnvironment.kt
  local f="$1"
  # Phase 3: opcache.file_cache in die on-device php.ini. Ein awk-Pass, zwei Anker:
  # mkdirs vor `val phpIni = """`, die opcache-Direktiven nach der openssl.cafile-Zeile.
  # (config:cache/view:cache/event:cache bewusst NICHT gepatcht — config:cache friert
  # nativephp-internal.running=false ein und sperrt den Chat; siehe OPTIMIZE.md Phase 5.)
  if ! grep -q 'opcache.file_cache' "$f"; then
    # EIN awk-Pass, aber ZWEI unabhaengige Anker — und bis zum 2026-09-01 lief das
    # `mv` VOR der Pruefung. Traf nur einer der beiden, blieb der Halbstand liegen,
    # und der naechste Lauf las ihn ueber `grep -q opcache.file_cache` als "schon
    # gepatcht": `[=]`, und die fehlende Haelfte kam nie zurueck. In der anderen
    # Richtung (nur mkdirs traf) haette jeder Folgelauf eine WEITERE mkdirs-Zeile
    # eingefuegt. Deshalb dieselbe Bauform wie in patch_deeplinks: Sicherungskopie,
    # beide Haelften einzeln geprueft, bei jedem Fehlschlag der Vendor-Stand zurueck.
    cp "$f" "$f.vor-patch"
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
    local fehler=""
    if ! grep -q 'mkdirs() // OPTIMIZE' "$f"; then
      fehler="Anker 1 (val phpIni) nicht getroffen — das opcache-Verzeichnis wuerde nie angelegt"
    elif ! grep -q 'opcache.file_cache' "$f"; then
      fehler="Anker 2 (openssl.cafile) nicht getroffen — keine opcache-Direktiven in der php.ini"
    fi
    if [ -n "$fehler" ]; then
      mv "$f.vor-patch" "$f"
      echo "FEHLER: opcache-Patch griff nicht ($f) — $fehler." >&2
      echo "        Anker gedriftet (NativePHP-Update?). Datei auf den Vendor-Stand" >&2
      echo "        zurueckgesetzt — kein Halbstand." >&2
      exit 1
    fi
    rm -f "$f.vor-patch"
    echo "    [+] Phase 3 opcache.file_cache"
  else
    echo "    [=] Phase 3 opcache.file_cache bereits gesetzt"
  fi
  patch_opcache_wipe "$f"
  # ENTFERNT am 2026-09-01 (NativePHP 4.3.1): der Extract-Gate-Patch. Upstream
  # loest denselben Bug jetzt selbst — LaravelEnvironment.kt:281-293 liest
  # `currentId` primaer aus der `.version`-Datei, die bei der Extraktion mit der
  # embeddedId beschrieben wird (:346); der Rueckfall auf die .env-Neuberechnung
  # ist dort ausdruecklich als Legacy markiert. Das ist dieselbe Aufloesung, die
  # unser Patch erzwungen hat.
}

patch_iconbg() {  # $1 = Pfad zu ic_launcher_background.xml
  local f="$1"
  # Adaptive-Icon-Hintergrund von weiß (NativePHP-Default) auf schwarz. Der
  # Foreground ist eine schwarze Rundecken-Form mit transparenten Ecken — auf
  # weißem BG scheinen dort weiße Ecken durch (Bug-Report). Schwarz macht es nahtlos.
  if grep -q '#ffffff' "$f"; then
    # `sed` laeuft hier ohne /g und ersetzt nur das ERSTE Vorkommen. Traegt die
    # Datei nach einem NativePHP-Update zwei weisse Farbwerte (Verlauf, zweite
    # Ebene), meldete der alte Test `grep -q '#000000'` trotzdem Erfolg, waehrend
    # die zweite Zeile weiss blieb. Deshalb zusaetzlich die ABWESENHEIT des alten
    # Wertes pruefen und im Fehlerfall zurueckrollen.
    cp "$f" "$f.vor-patch"
    sed -i 's/#ffffff/#000000/' "$f"
    if ! grep -q '#000000' "$f" || grep -q '#ffffff' "$f"; then
      mv "$f.vor-patch" "$f"
      echo "FEHLER: Icon-BG-Patch griff nicht ($f) — Datei geändert (NativePHP-Update?)." >&2
      echo "        Datei auf den Vendor-Stand zurueckgesetzt — kein Halbstand." >&2
      exit 1
    fi
    rm -f "$f.vor-patch"
    echo "    [+] Icon-Hintergrund schwarz (#000000)"
  else
    echo "    [=] Icon-Hintergrund bereits schwarz"
  fi
}

# ENTFERNT am 2026-09-01 (NativePHP 4.3.1): patch_main(), der den Queue-Worker um
# 6 s verzoegerte. Upstream verzoegert selbst — MainActivity.kt:120
# `WORKER_START_DELAY_MS = 2500L`, angewandt auf den Kaltstart-Pfad in :240-244;
# :546-549 haelt fest, dass der Worker bewusst nicht mehr in LaravelInit startet.
#
# Der Patch war nicht nur ueberfluessig, sondern in 4.3.1 schaedlich: sein Anker
# `queueWorker = PHPQueueWorker(phpBridge).also { it.start() }` trifft dort DREI
# Stellen statt einer (gemessen am gepatchten Ergebnis: :251, :941, :1025). Die
# erste liegt INNERHALB von Upstreams eigenem 2500-ms-postDelayed, macht daraus
# also 8,5 s; die beiden anderen sitzen an den Hot-Reload-Neustarts nach
# shutdownPersistentRuntime()/bootPersistentRuntime(), wo eine Kaltstart-
# Verzoegerung nie hingehoerte. Deshalb entfernt und nicht angepasst.

patch_filechooser_webview() {  # $1 = Pfad zu WebViewManager.kt
  local f="$1"
  # Der NativePHP-WebView verdrahtet onShowFileChooser NICHT → ein HTML-
  # <input type=file> öffnet auf dem Gerät nichts (Android-Default gibt false
  # zurück). Das lähmt u.a. den Chat-„Bild anhängen"-Button. Fix: Callback im
  # companion object halten + Override, der den nativen Picker via
  # FileChooserParams.createIntent() über die Activity startet.
  # ZWEI Bloecke, aber EIN Feature: der Override aus Block 2 referenziert die
  # Felder aus Block 1. Traf nur Block 1, meldete der Lauf zwar exit 1, liess die
  # Datei aber halb gepatcht liegen — und `grep -q FILE_CHOOSER_REQUEST_CODE` liest
  # diesen Rest beim naechsten Lauf als "bereits vorhanden". Deshalb eine
  # Sicherungskopie fuer die GANZE Funktion: scheitert Block 2, geht auch Block 1
  # zurueck. Gleiche Bauform wie patch_deeplinks.
  cp "$f" "$f.vor-patch"
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
      || { mv "$f.vor-patch" "$f"
           echo "FEHLER: FileChooser-Companion-Patch griff nicht ($f) — Anker gedriftet (NativePHP-Update?)." >&2
           echo "        Datei auf den Vendor-Stand zurueckgesetzt — kein Halbstand." >&2
           exit 1; }
    echo "    [+] FileChooser Companion-Halter"
  else
    echo "    [=] FileChooser Companion-Halter bereits vorhanden"
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
      || { mv "$f.vor-patch" "$f"
           echo "FEHLER: onShowFileChooser-Patch griff nicht ($f) — Anker gedriftet (NativePHP-Update?)." >&2
           echo "        Datei auf den Vendor-Stand zurueckgesetzt — auch der Companion-" >&2
           echo "        Halter aus Block 1, denn ohne den Override ist er toter Code." >&2
           exit 1; }
    echo "    [+] onShowFileChooser-Override"
  else
    echo "    [=] onShowFileChooser-Override bereits vorhanden"
  fi
  rm -f "$f.vor-patch"
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
  else
    echo "    [=] onActivityResult FileChooser-Routing bereits vorhanden"
  fi
}

# ENTFERNT am 2026-09-01 (NativePHP 4.3.1): patch_gradle_profileable(). Der Block
# war aus 4.3.0 zurueckportiert und steht jetzt im Original — app/build.gradle.kts:98
# `create("profileable")` inklusive isMinifyEnabled, Debug-Signing und
# matchingFallbacks. Der Patch meldete dementsprechend schon `[=]`.

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
  cp "$f" "$f.vor-patch"
  perl -0777 -i -pe 's{^([ \t]*)\Qkeep\EDebugSymbols\.add\("\*\*/\*\.so"\)[ \t]*$}
                     {$1// OPTIMIZE-STRIP: Zeile bewusst entfernt — sie machte den\n$1// stripReleaseDebugSymbols-Task wirkungslos und lieferte 2,9 MB\n$1// Symboltabellen mit aus. Begruendung in scripts/apply-vendor-patches.sh.}mx' "$f"
  # Zwei Pruefungen statt einer: `s///` laeuft ohne /g und ersetzt nur das ERSTE
  # Vorkommen. Stuenden zwei keepDebugSymbols-Zeilen im Gradle-Block, waere der
  # Marker gesetzt (ab dem naechsten Lauf also `[=]`) und die zweite Zeile machte
  # den Patch trotzdem wirkungslos — ein Halbstand, den nie wieder jemand sieht.
  # Deshalb auch die ABWESENHEIT des Ankers pruefen, sonst der Vendor-Stand zurueck.
  if ! grep -q 'OPTIMIZE-STRIP' "$f" || grep -qF "$anchor" "$f"; then
    mv "$f.vor-patch" "$f"
    echo "    [!] Strip-Patch griff nicht oder nur teilweise — keepDebugSymbols steht" >&2
    echo "        weiterhin in $f. Datei auf den Vendor-Stand zurueckgesetzt." >&2
    exit 1
  fi
  rm -f "$f.vor-patch"
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
  # Der Patch besteht aus ZWEI Ersetzungen, und bis zum 2026-09-01 wurde nur EINE
  # von beiden geprueft. Das ist dieselbe Bauform, an der der Extract-Gate-Patch
  # gescheitert ist: seine erste Ersetzung griff, die zweite nicht, und die Datei
  # blieb HALB gepatcht liegen (P1-Befund, 2026-09-01). Hier waere es teurer
  # gewesen, denn die beiden Halbstaende sind nicht symmetrisch:
  #   - nur (1): `{$dataTags}` steht im Heredoc, aber nichts definiert es. `php -l`
  #     ist zufrieden (Syntax ist gueltig), die Variable interpoliert zur Laufzeit
  #     zu Leerstring -> das App-Link-Intent-Filter hat GAR keine <data>-Zeile mehr.
  #   - nur (2): die Berechnung steht da, die <data>-Zeile aber unveraendert auf
  #     pathPrefix="/" — und `grep -q deeplink_path_prefixes` meldet trotzdem
  #     Erfolg. Das waere ein STILLES Falsch-Gruen genau an der Stelle, die den
  #     v1.9.4-Fehler ausgeloest hat.
  # Deshalb: Sicherungskopie vor dem Eingriff, beide Haelften einzeln geprueft,
  # und bei jedem Fehlschlag der Originalzustand zurueck statt eines Halbstands.
  cp "$f" "$f.vor-patch"
  perl -0777 -i -pe '
    # 1. Die eine <data>-Zeile durch die interpolierte Liste ersetzen.
    s{^[ \t]*<data android:scheme="https" android:host="\{\$host\}" android:pathPrefix="/" />[ \t]*$}
     {\{\$dataTags\}}m;
    # 2. Die Berechnung direkt vor den XML-Heredoc des $host-Zweigs setzen.
    s{(if \(\$host\) \{\n)(\s*)(\$filters\[\] = <<<XML)}
     {$1$2// PATCH (twenty-one-companion, scripts/apply-vendor-patches.sh):\n$2// nur die konfigurierten Pfade beanspruchen statt des ganzen Hosts.\n$2\$prefixes = config('"'"'nativephp.deeplink_path_prefixes'"'"') ?: ['"'"'/'"'"'];\n$2\$dataTags = implode("\\n", array_map(\n$2    fn (\$prefix) => '"'"'                <data android:scheme="https" android:host="'"'"'\n$2        .\$host.'"'"'" android:pathPrefix="'"'"'.\$prefix.'"'"'" />'"'"',\n$2    \$prefixes\n$2));\n$2$3}s;
  ' "$f"
  local fehler=""
  if ! php -l "$f" >/dev/null 2>&1; then
    fehler="Patch erzeugt ungueltiges PHP"
  elif ! grep -qF '{$dataTags}' "$f"; then
    fehler="Ersetzung 1 griff nicht — die <data>-Zeile steht unveraendert"
  elif ! grep -q 'deeplink_path_prefixes' "$f"; then
    fehler="Ersetzung 2 griff nicht — \$dataTags waere undefiniert"
  elif grep -qF 'android:pathPrefix="/" />' "$f"; then
    fehler="pathPrefix=\"/\" steht immer noch in der Datei"
  fi
  if [ -n "$fehler" ]; then
    mv "$f.vor-patch" "$f"
    echo "    [!] $fehler" >&2
    echo "        Datei auf den Vendor-Stand zurueckgesetzt — kein Halbstand." >&2
    echo "        NativePHP hat generateDeepLinkFilters() geaendert; der Patch MUSS" >&2
    echo "        von Hand nachgezogen werden, sonst beansprucht die App den ganzen" >&2
    echo "        Portal-Host als App-Link (WebView-Crash beim Signer-Callback)." >&2
    exit 1
  fi
  rm -f "$f.vor-patch"
  echo "    [+] Deeplink-Pfade auf config('nativephp.deeplink_path_prefixes') eingeschraenkt"
}

# ── Messschalter ───────────────────────────────────────────────────────────────
# OPTIMIZE_SKIP="opcache" laesst einzelne Patches AUS, damit ihr Nutzen
# gegen eine Baseline messbar wird. Ohne das laesst sich nicht pruefen, ob ein
# Bootzeit-Patch ueberhaupt noch etwas bringt — und ein Patch, der nichts bringt,
# kostet trotzdem Pflege und verteuert jeden spaeteren NativePHP-Umstieg.
# NUR fuer Messlaeufe. Ein Release-Build laeuft ohne diese Variable.
# Seit dem Wegfall des Queue-Delay-Patches (4.3.1) ist `opcache` der einzige
# Schluessel; die Mechanik bleibt, weil der naechste Bootzeit-Patch sie braucht.
uebersprungen() {  # $1 = Schluessel
  case " ${OPTIMIZE_SKIP:-} " in *" $1 "*) return 0 ;; *) return 1 ;; esac
}
[ -n "${OPTIMIZE_SKIP:-}" ] && echo "  ⚠️  MESSLAUF — uebersprungen: $OPTIMIZE_SKIP"

any=0
for entry in "${TARGETS[@]}"; do
  base="${entry%%|*}"; label="${entry##*|}"
  env_f="$base/$REL_ENV"; main_f="$base/$REL_MAIN"
  if [ -f "$env_f" ] && [ -f "$main_f" ]; then
    echo "  $label:"
    uebersprungen opcache || patch_env "$env_f"
    patch_filechooser_main "$main_f"
    [ -f "$base/$REL_WEBVIEW" ] && patch_filechooser_webview "$base/$REL_WEBVIEW"
    [ -f "$base/$REL_ICONBG" ] && patch_iconbg "$base/$REL_ICONBG"
    [ -f "$base/$REL_GRADLE" ] && patch_gradle_strip "$base/$REL_GRADLE"
    any=1
  else
    echo "  $label: übersprungen (nicht vorhanden)"
  fi
done
[ $any -eq 1 ] || { echo "Kein Ziel gefunden — composer install / native:run gelaufen?"; exit 1; }

echo "  Deeplinks (vendor PHP):"
# KEIN OPTIMIZE_SKIP-Schluessel fuer diesen Patch — bewusst. OPTIMIZE_SKIP dient
# dazu, einen BOOTZEIT-Patch gegen eine Baseline zu messen; der Deeplink-Patch
# misst nichts, er entscheidet, ob die App den ganzen Portal-Host beansprucht.
# Ein Schalter dafuer waere genau der Schalter, der in einem Release-Lauf gesetzt
# bliebe und den v1.9.4-Fehler wiederholte.
#
# Bis 2026-09-01 stand hier ein stilles "uebersprungen (nicht vorhanden)": ein
# fehlendes Ziel druckte eine Zeile und der Lauf endete mit exit 0. Jeder andere
# Patch in dieser Datei faellt bei Anker-Drift laut aus, dieser eine nicht — und
# genau er haelt den Host-Anspruch klein. NativePHP 4.x verschiebt die Datei nach
# src/Concerns/RunsAndroid.php; unter der alten Fassung waere der Patch beim
# Upgrade lautlos weggefallen.
if [ ! -f "$DEEPLINK_PHP" ]; then
  echo "    [!] Deeplink-Ziel nicht vorhanden: $DEEPLINK_PHP" >&2
  echo "        Ohne diesen Patch beansprucht die App den GANZEN Portal-Host als" >&2
  echo "        App-Link (pathPrefix=\"/\") — das faengt Browser::inApp/open ab und" >&2
  echo "        crasht den WebView beim Signer-Callback (SIGILL, v1.9.4)." >&2
  echo "        Wahrscheinliche Ursache: ein NativePHP-Update hat die Datei" >&2
  echo "        verschoben oder umbenannt (4.x: src/Concerns/RunsAndroid.php)." >&2
  echo "        Wo sie jetzt liegt, zeigt:" >&2
  echo "          ls vendor/nativephp/mobile/src/*/RunsAndroid.php" >&2
  echo "        Danach DEEPLINK_PHP in diesem Skript nachziehen." >&2
  exit 1
fi
patch_deeplinks "$DEEPLINK_PHP"
echo "Fertig."

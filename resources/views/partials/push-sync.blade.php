{{--
    Gleicht den Hintergrund-Worker mit dem Push-Schalter ab. Hängt in beiden
    Layouts (layouts::mobile und group::einundzwanzig): die Launch-Weiche
    schickt eingeloggte Nutzer direkt in den Chat, die sähen das mobile Layout
    sonst nie.

    Der Zustand MUSS vom Client kommen — Login, aktiver Space und
    Raum-Mitgliedschaft leben auf Mobile nur im Browser (localStorage bzw. der
    IndexedDB-Cache), PHP kennt nichts davon. `pushSync` schreibt das Chat-JS
    (groups.ts); im mobilen Layout ist das Chat-JS nicht geladen, dort trägt der
    zuletzt geschriebene Stand.

    POST mit Body statt GET mit Query-Param: die Session trägt (künftig) den
    NIP-46-Signing-Key, und NativePHP loggt jede URL in den logcat. Die Route hat
    eine CSRF-Ausnahme (bootstrap/app.php) — ein 419 hat hier im Chat-Layout
    schon eine Livewire-Variante gekillt (plans/PUSH-NOTIFICATIONS.md §4).

    Reconcile statt Fire-and-forget: läuft bei jedem App-Start. Schalter aus,
    ausgeloggt oder in keinem Raum → Worker wird GESTOPPT, damit im Aus-Zustand
    garantiert keine Hintergrundaktivität Akku zieht. Die Route ist idempotent.
--}}
@once
    <script>
        (function () {
            var read = function (key) {
                try {
                    return JSON.parse(localStorage.getItem(key));
                } catch (e) {
                    return null; /* nicht lesbar → wie ausgeloggt behandeln */
                }
            };

            /*
                Wartet, bis NativePHPs POST-Shim installiert ist.

                Androids WebView kann in `shouldInterceptRequest` den POST-Body
                NICHT lesen — NativePHP umgeht das, indem es window.fetch/XHR
                umhüllt und den Body vorab per `AndroidPOST.storePostData()` an
                Kotlin reicht. Diese Umhüllung passiert in `onPageFinished`
                (WebViewManager.injectJavaScript), also NACH allen Seiten-Skripten
                und nach `load`. Ein fetch davor kommt bei PHP OHNE Body an: die
                Route sieht „Pubkey/Relay/Rooms fehlen", macht daraus einen leeren
                Zustand — und der bestellt den Worker ab.

                Am Gerät gemessen: identischer Body, `{"scheduled":false}` beim
                Seitenaufbau vs. `{"scheduled":true}` danach. Im Chat fiel das nie
                auf, weil der `push-sync`-Event ~1 s später nachsynchronisiert; im
                mobilen Layout (Meetups/Profil) gibt es den nicht — dort blieb der
                Worker nach jedem Seitenaufruf abbestellt.

                Das Flag setzt NativePHP selbst (Re-Injection-Guard). Ohne
                natives Runtime (Web/Tests) kommt es nie — deshalb der Deckel,
                danach läuft der Aufruf ins Leere und wird gefangen.
            */
            var whenPostsWork = function (fn) {
                if (window.__nphpPostPatched) {
                    return fn();
                }

                var tries = 0;
                var timer = setInterval(function () {
                    if (window.__nphpPostPatched || ++tries > 100) {
                        clearInterval(timer);
                        fn();
                    }
                }, 50);
            };

            var sync = function () {
                var pubkey = read('pubkey');
                var state = read('pushSync') || {};
                var sessions = read('sessions') || {};

                fetch(@js(url('push/sync')), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        pubkey: typeof pubkey === 'string' ? pubkey : '',
                        relay: state.relay || '',
                        rooms: state.rooms || [],
                        names: state.names || {}, /* Raum-ID → Name, nur für den Notification-Titel */
                        session: sessions[pubkey] || null,
                    }),
                }).catch(function () { /* kein natives Runtime (Web/Tests) → egal */ });
            };

            whenPostsWork(sync);

            // Die Raumliste streamt im Chat erst nach dem Seitenaufbau ein
            // (39002 vom Relay) und ändert sich beim Beitreten/Verlassen —
            // groups.ts feuert dann dieses Event. Entprellt, weil beim Laden
            // jeder Raum einzeln eintrudelt.
            var pending;
            window.addEventListener('push-sync', function () {
                clearTimeout(pending);
                pending = setTimeout(function () {
                    whenPostsWork(sync);
                }, 1000);
            });
        })();
    </script>
@endonce

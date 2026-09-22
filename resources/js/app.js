/**
 * The ONE Vite JS entry of this app (P4).
 *
 * ── Why there is only one left ──────────────────────────────────────────────────
 * Up to P3 there were two: this `app.js` for the Portal shell and `group.js` for the chat
 * full-screen tab, each bound to its layout. Since P4 the Portal pages render in the PACKAGE
 * layout (D9), and the command palette is the one search (D6) — so it has to exist on both
 * layouts. Two entries meant: on half the pages there is no palette, and the bridge that
 * covered that up (a listener opening a second search window) was exactly the duplication
 * this concept removes.
 *
 * The registration of the Nostr components therefore stands HERE and FIRST: it brings the
 * full `authGate` store, and the thin replacement store of this file (which served the Portal
 * pages up to P3 because the island was missing there) is gone with it — one question, one
 * answer.
 *
 * ── Leaflet is loaded LAZILY ────────────────────────────────────────────────────
 * Leaflet + MarkerCluster (~150 kB) used to be a top-level import in this file and were
 * therefore parsed on EVERY page, although exactly one view needs them
 * (`/bereich/meetups?ansicht=karte`). `window.loadLeaflet()` fetches them on the first render
 * of that view and remembers the result.
 */
import { registerNostrComponents } from '@einundzwanzig/group';

/**
 * Leaflet on demand. Idempotent: the second call gets the same promise, and switching views
 * back and forth loads nothing again.
 *
 * The co-located CSS comes along — without `leaflet.css` a map renders as a salad of
 * unpositioned tiles, and that looks like a map bug rather than a missing stylesheet.
 */
let leafletPromise = null;

window.loadLeaflet = function () {
    if (leafletPromise) {
        return leafletPromise;
    }

    leafletPromise = (async () => {
        const [{ default: L }] = await Promise.all([
            import('leaflet'),
            import('leaflet/dist/leaflet.css'),
        ]);
        await Promise.all([
            import('leaflet.markercluster'),
            import('leaflet.markercluster/dist/MarkerCluster.css'),
            import('leaflet.markercluster/dist/MarkerCluster.Default.css'),
        ]);
        // `window.L` stays set: `leaflet.markercluster` extends the global `L`, and older
        // views read it from there.
        window.L = L;

        // The base map is a MapLibre GL layer inside Leaflet (vector tiles, see
        // config/maps.php). Loaded here and not in the main entry: MapLibre is the largest
        // part of this bundle, and only the two map views need it.
        //
        // The worker is emitted by Vite (`?worker&url`, bundled with its shared chunk) and
        // handed to MapLibre explicitly: its own default looks for `maplibre-gl-worker.mjs`
        // next to the importing chunk, a file the build does not emit.
        const [{ maplibreGL }, maplibre, { default: workerUrl }] = await Promise.all([
            import('@maplibre/maplibre-gl-leaflet'),
            import('maplibre-gl'),
            import('maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url'),
            import('maplibre-gl/dist/maplibre-gl.css'),
        ]);
        maplibre.setWorkerUrl(workerUrl);
        L.maplibreGL = maplibreGL;

        return L;
    })();

    return leafletPromise;
};

/**
 * Put the configured base map (`config('maps.tiles')`) onto a Leaflet map. Both map views
 * call this, so the provider stays a matter of config/maps.php alone.
 */
window.addBaseMap = function (L, map, tiles) {
    map.setMinZoom(tiles.minZoom);
    map.setMaxZoom(tiles.maxZoom);

    const layer = L.maplibreGL({ style: tiles.style }).addTo(map);

    // The OpenFreeMap dark style fills forests with `wood-pattern`, an image its own sprite
    // (ofm_f384) does not contain — measured 2026-09-22, 264 sprite entries, none of them
    // that one. Without a stand-in MapLibre warns on every page with a map. A transparent
    // pixel draws what the style would draw anyway: nothing.
    // (`styleimagemissing` listeners cannot resolve it for the current request in
    // MapLibre 6 — the resolver can.)
    const gl = layer.getMaplibreMap();
    gl.setMissingStyleImageResolver((id) => {
        if (!gl.hasImage(id)) {
            gl.addImage(id, { width: 1, height: 1, data: new Uint8Array(4) });
        }
    });

    return layer;
};

/**
 * Haptisches Feedback (Phase 1.3).
 *
 * Sofortiges, clientseitiges Tap-Feedback ohne Server-Round-Trip über die
 * Web-Vibration-API (vom Android-WebView unterstützt — der Hauptzielplattform).
 * Server-seitige Aktions-Bestätigung läuft zusätzlich über die native
 * NativePHP-API (Device::vibrate()) in den Livewire-Actions.
 *
 * Muster: 'light' (Tap), 'medium' (Auswahl), 'success', 'error'.
 * Respektiert prefers-reduced-motion (dann lautlos no-op).
 */
const HAPTIC_PATTERNS = {
    light: 10,
    medium: 18,
    success: [12, 40, 12],
    error: [24, 50, 24],
};

// Einmalig ausgewertet: das MediaQueryList aktualisiert `.matches` live, daher
// reicht ein Lookup statt eines matchMedia()-Aufrufs bei jedem Tap.
const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)');

window.haptic = function (pattern = 'light') {
    if (reducedMotion?.matches || typeof navigator.vibrate !== 'function') {
        return;
    }

    navigator.vibrate(HAPTIC_PATTERNS[pattern] ?? HAPTIC_PATTERNS.light);
};

/**
 * Alpine-Extensions registrieren — RACE-FEST. Dieses per Vite gebündelte, schwer
 * importierende Modul (leaflet, group/auth-gate) kann im Android-WebView ERST NACH
 * Alpine.start ausgewertet werden (das lokale Bundle startet Alpine, bevor app.js
 * fertig lädt). Dann ist 'alpine:init' bereits durch und ein reiner Event-Listener
 * feuerte NIE → $haptic/authGate blieben unregistriert: „$haptic is not
 * defined", der Banner-„Verstanden"-Button ohne Wirkung, tote Nav-Gates. Darum wird
 * die Registrierung unten defensiv aufgerufen (Alpine schon da → sofort; sonst Event).
 */
const registerAlpineExtensions = () => {
    // Nutzung im Markup: x-on:click="$haptic('success')"
    window.Alpine?.magic('haptic', () => window.haptic);

    /**
     * Bild-Cropper für die Editoren (Meetup/Kurs/Referent). Auf Mobile ist ein
     * HTML-`<input type=file>` im NativePHP-WebView funktionslos (kein
     * onShowFileChooser), deshalb kommt das Bild über die native Kamera/Galerie
     * (PHP-Facade) als base64-data-URI herein: der jeweilige Editor feuert
     * `image-crop-open` {src, key, ratio}; dieses EINE globale Overlay lädt
     * cropperjs lazy (co-lokalisiertes CSS lädt mit), zeigt die Crop-UI und gibt
     * das gecroppte Canvas als JPEG-data-URI per `image-cropped` {dataUrl, key}
     * zurück. Der `key` korreliert Overlay ↔ Editor (mehrere liegen im Layout).
     */
    window.Alpine?.data('imageCropper', () => ({
        src: null,
        key: null,
        ratio: NaN,
        _cropper: null,
        _token: 0,

        show(detail) {
            this.src = detail.src;
            this.key = detail.key;
            this.ratio = detail.ratio || NaN;
            // Als Flux-Modal öffnen → stapelt korrekt ÜBER dem Editor-Sheet.
            this.$flux.modal('image-cropper').show();
            this._initWhenSized(++this._token);
        },

        // cropperjs erst initialisieren, wenn der Container beim Modal-Einfahren
        // eine echte Größe hat (0px-Init liefert eine kaputte Crop-Box).
        async _initWhenSized(token) {
            const [{ default: Cropper }] = await Promise.all([
                import('cropperjs'),
                import('cropperjs/dist/cropper.css'),
            ]);
            const start = performance.now();
            const wait = () => {
                if (token !== this._token) {
                    return; // inzwischen geschlossen/neu geöffnet
                }
                // Flux-Modals teleportieren ihren Inhalt aus dem Alpine-Subtree
                // (Portal) → $refs greift nicht; deshalb per id aus dem Dokument.
                const img = document.getElementById('image-cropper-img');
                if (img && img.clientWidth > 0 && img.offsetParent !== null) {
                    this._cropper?.destroy();
                    this._cropper = new Cropper(img, {
                        viewMode: 1,
                        autoCropArea: 1,
                        background: false,
                        aspectRatio: this.ratio,
                    });
                    return;
                }
                if (performance.now() - start < 3000) {
                    requestAnimationFrame(wait);
                }
            };
            requestAnimationFrame(wait);
        },

        confirm() {
            if (!this._cropper) {
                return;
            }
            const canvas = this._cropper.getCroppedCanvas({ maxWidth: 1600, maxHeight: 1600 });
            const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
            window.haptic('success');
            window.Livewire?.dispatch('image-cropped', { dataUrl, key: this.key });
            this.$flux.modal('image-cropper').close();
        },

        // Vom Modal-@close aufgerufen (auch bei Escape/Backdrop) → aufräumen.
        teardown() {
            this._token++;
            this._cropper?.destroy();
            this._cropper = null;
            this.src = null;
            this.key = null;
        },
    }));

    /*
     * ── NO `authGate` store of its own any more (P4) ─────────────────────────────
     *
     * A thin version with a single `gateTap` stood here that forced EVERY gated tap into a
     * hard page load. It existed because the welshman island lived in the chat bundle only: a
     * `wire:navigate` from a Portal page into the chat would have needed an island that was
     * not on that page at all.
     *
     * Since the two entries are ONE entry (see the head), the island is everywhere — and with
     * it the full store from `registerNostrComponents` (`js/bridge.ts`), which answers the
     * same `gateTap` AND opens the login sheet in place instead of leaving the page. Two
     * registrations of the same store name would be a race whose winner is decided by load
     * order.
     */
};

// Läuft Alpine schon (WebView: das Bundle startet es, bevor dieses Modul lädt),
// direkt registrieren; sonst regulär über 'alpine:init' — dann greifen $haptic
// (magic) und der authGate-Store rechtzeitig vor der Element-Initialisierung.
/**
 * The Nostr components FIRST, then the app extensions.
 *
 * The order is meaning here and not style: `registerNostrComponents` brings the `authGate`
 * store, the palette and the chat islands; everything this file registers afterwards
 * (`$haptic`, `imageCropper`) only adds to them. The other way round an addition would stand
 * before the thing it adds to.
 */
const registriere = () => {
    registerNostrComponents(window.Alpine);
    registerAlpineExtensions();
};

// If Alpine is already running (WebView: the local bundle starts it before this module is
// loaded), register straight away; otherwise regularly through `alpine:init`. Without that
// branch the event would long be over and NOTHING would be registered — measured as
// "$haptic is not defined" and as a dead chat island.
if (window.Alpine) {
    registriere();
} else {
    document.addEventListener('alpine:init', registriere);
}

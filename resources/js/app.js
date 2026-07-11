import { isAuthed, sanitizeReturnUrl } from '@einundzwanzig/group/auth-gate';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';

window.L = L;

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
 * feuerte NIE → $haptic/appRefresh/authGate blieben unregistriert: „$haptic is not
 * defined", der Banner-„Verstanden"-Button ohne Wirkung, tote Nav-Gates. Darum wird
 * die Registrierung unten defensiv aufgerufen (Alpine schon da → sofort; sonst Event).
 */
const registerAlpineExtensions = () => {
    // Nutzung im Markup: x-on:click="$haptic('success')"
    window.Alpine?.magic('haptic', () => window.haptic);

    /**
     * App-Refresh (Phase A2): Header-Refresh-Button. Das Layout-Chrome liegt
     * ausserhalb der Seiten-Livewire-Komponente, daher löst der Button einen
     * GLOBALEN Livewire-Event `portal-refresh` aus (die Seite verwirft ihren
     * Cache und rendert neu); `portal-refreshed` kommt zurück und stoppt den
     * Spinner (`refreshing` treibt den Header-Icon-Spin).
     *
     * Pull-to-Refresh wurde bewusst entfernt — es griff beim normalen Scrollen
     * zu aggressiv. Aktualisiert wird nur noch per Button.
     */
    window.Alpine?.data('appRefresh', () => ({
        refreshing: false,

        trigger() {
            if (this.refreshing) {
                return;
            }
            this.refreshing = true;
            window.haptic('medium');
            window.Livewire?.dispatch('portal-refresh');
        },
    }));

    /**
     * Kontextueller Auth-Gate für die Portal-Shell (§4.2). Die geteilte
     * <x-group::bottom-nav> ruft `$store.authGate.gateTap` auf JEDER Shell-Seite
     * auf, auch auf den Portal-Tabs (Meetups/Mehr), die nur dieses app.js laden —
     * die welshman-Insel samt vollem authGate-Store lebt allein im Chat-Bundle
     * (group.js). Ohne diesen Store wäre `$store.authGate` dort undefined →
     * „Cannot read properties of undefined (reading 'gateTap')".
     *
     * Von einer Portal-Seite führt JEDER nostr-gate-Tab (Chat/Wallet) ins
     * Chat-Bundle (group.js) — über die Layout-Grenze layouts.mobile →
     * group::einundzwanzig. Ein wire:navigate-SPA-Sprung bootet group.js dort NIE:
     * es registriert alle Alpine-Komponenten in `alpine:init`, das nach dem
     * Portal-Load bereits gefeuert hat → tote Chat/Wallet-Insel. Darum erzwingt
     * gateTap IMMER einen harten Seiten-Load — Gast wie eingeloggt:
     *   Gast       → /nostr-login (Interstitial, liegt ebenfalls im group-Layout).
     *   eingeloggt → direkt aufs Tab-Ziel; der Full-Load bootet group.js frisch.
     *
     * isAuthed/sanitizeReturnUrl kommen aus dem geteilten (welshman-freien)
     * @einundzwanzig/group/auth-gate — dieselbe Trust-Grenzen-Logik wie der volle
     * Store in bridge.ts (welshman speichert den pubkey JSON-serialisiert, darum
     * KEINE rohe Hex-Regex).
     */
    window.Alpine?.store('authGate', {
        gateTap(event, intent = {}) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (isAuthed(localStorage.getItem('pubkey'))) {
                const href = event.currentTarget?.href;
                if (href) {
                    location.assign(href);
                }
                return;
            }
            const ret = sanitizeReturnUrl(intent.returnUrl ?? location.pathname + location.search);
            location.assign('/nostr-login' + (ret ? '?return=' + encodeURIComponent(ret) : ''));
        },
    });
};

// Läuft Alpine schon (WebView: das Bundle startet es, bevor dieses Modul lädt),
// direkt registrieren; sonst regulär über 'alpine:init' — dann greift auch die
// x-data-Registrierung (appRefresh) rechtzeitig vor der Element-Initialisierung.
if (window.Alpine) {
    registerAlpineExtensions();
} else {
    document.addEventListener('alpine:init', () => registerAlpineExtensions());
}

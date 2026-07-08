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

document.addEventListener('alpine:init', () => {
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
});

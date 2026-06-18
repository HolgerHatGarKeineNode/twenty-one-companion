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
     * App-Refresh (Phase A2/A3): Header-Refresh-Button UND Pull-to-Refresh am
     * scrollbaren <main>. Das Layout-Chrome liegt ausserhalb der Seiten-
     * Livewire-Komponente, daher löst die Geste einen GLOBALEN Livewire-Event
     * `portal-refresh` aus (die Seite verwirft ihren Cache und rendert neu);
     * `portal-refreshed` kommt zurück und stoppt den Spinner.
     *
     * `pull`/`dragging` treiben den PTR-Indikator (wächst beim Ziehen, dreht
     * beim Aktualisieren), `refreshing` den Header-Icon-Spin.
     */
    window.Alpine?.data('appRefresh', () => ({
        refreshing: false,
        pull: 0,
        dragging: false,
        startY: 0,
        threshold: 64,
        max: 96,

        trigger() {
            if (this.refreshing) {
                return;
            }
            this.refreshing = true;
            window.haptic('medium');
            window.Livewire?.dispatch('portal-refresh');
        },

        onDone() {
            this.refreshing = false;
            this.pull = 0;
            this.dragging = false;
        },

        onStart(e) {
            if (this.refreshing || e.currentTarget.scrollTop > 0) {
                return;
            }
            this.dragging = true;
            this.startY = e.touches[0].clientY;
        },

        onMove(e) {
            if (!this.dragging || this.refreshing) {
                return;
            }
            const dy = e.touches[0].clientY - this.startY;
            if (dy <= 0 || e.currentTarget.scrollTop > 0) {
                this.pull = 0;

                return;
            }
            // Gummiband-Widerstand: je weiter gezogen, desto zäher.
            this.pull = Math.min(dy * 0.5, this.max);
        },

        onEnd() {
            this.dragging = false;
            if (this.refreshing) {
                return;
            }
            if (this.pull >= this.threshold) {
                this.pull = this.threshold;
                this.trigger();
            } else {
                this.pull = 0;
            }
        },
    }));
});

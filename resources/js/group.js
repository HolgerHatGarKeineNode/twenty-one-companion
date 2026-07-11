// Insel-Bootstrap des Chat-Vollbild-Tabs. Lädt die welshman/Alpine-Insel aus
// dem einundzwanzig/group-Package und registriert deren Alpine-Komponenten.
// Wird NUR im Chat-Layout via @vite(config('group.vite')) geladen (Vollbild-
// Takeover) — die Portal-Shell nutzt weiterhin resources/js/app.js.
import { registerNostrComponents } from '@einundzwanzig/group'

// RACE-FEST (siehe app.js): Startet das WebView-Bundle Alpine, bevor dieses Modul
// ausgewertet ist, ist 'alpine:init' schon durch und die Chat-Komponenten
// (nostrRoomChat, nostrWallet, …) blieben unregistriert. Läuft Alpine schon, direkt
// registrieren; sonst regulär über das Event.
const register = () => registerNostrComponents(window.Alpine)
if (window.Alpine) {
    register()
} else {
    document.addEventListener('alpine:init', register)
}

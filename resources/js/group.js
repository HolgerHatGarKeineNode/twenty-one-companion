// Insel-Bootstrap des Chat-Vollbild-Tabs. Lädt die welshman/Alpine-Insel aus
// dem einundzwanzig/group-Package und registriert deren Alpine-Komponenten.
// Wird NUR im Chat-Layout via @vite(config('group.vite')) geladen (Vollbild-
// Takeover) — die Portal-Shell nutzt weiterhin resources/js/app.js.
import { registerNostrComponents } from '@einundzwanzig/group'

document.addEventListener('alpine:init', () => {
    registerNostrComponents(window.Alpine)
})

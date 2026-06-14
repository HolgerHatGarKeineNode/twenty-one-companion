# BACKLOG — Zusatz-Features 🚀

> **Herkunft:** ausgelagert aus `VERSION_1_2_0.md` (ehemals „Phase 8 — Zusatz-Features").
> Bewusst **aus dem v1.2.0-Scope herausgenommen**, damit v1.2.0 mit dem QA-Abschluss
> (Phase 8 dort) sauber geschlossen werden kann.
>
> **Status:** ungeplant / nicht versionsgebunden. Items werden bei Priorisierung in einen
> konkreten Release-Plan gezogen. Reihenfolge der IDs nur historisch — keine Sortierung.
>
> **Wenn ein Item gebaut wird, gilt das Phase-Abschluss-Ritual aus v1.2.0:**
> (a) opt-in Integrationssuite (`scripts/run-integration.sh`) gegen das lokale Portal-Dev,
> soweit Schreib-Endpunkte existieren, und (b) Playwright-Validierung des neuen Flows gegen
> die Live-Portal-API (funktional + visuell im Web-Renderer). Beides ist als die letzten
> beiden Backlog-Zeilen (B.12/B.13) festgehalten.

---

## Feature-Ideen (einzeln abhakbar, MVP-Set fett)

- [ ] **B.1 Favoriten/Merkliste** — Meetups, Termine, Kurse lokal (SQLite) bookmarken; eigener „Gemerkt"-Filter.
- [ ] **B.2 Add-to-Calendar / ICS** — Termin in den nativen Kalender exportieren (NativePHP) oder `.ics` teilen.
- [ ] **B.3 Teilen** — Meetup/Termin/Kurs via nativem Share-Sheet (Deep-Link ins Portal/in die App).
- [ ] **B.4 QR-Code** — pro Meetup/Termin QR generieren (Anreise/Beitritt) + In-App QR-Scanner (NativePHP Scanner) zum Beitreten.
- [ ] **B.5 RSVP/Teilnehmen** — „Ich komme"-Status für Termine (falls Portal-API vorhanden; sonst als lokaler Reminder). *(Portalseitig zu klären — siehe Offene Fragen unten.)*
- [ ] **B.6 Push-Benachrichtigungen** — Reminder vor Terminen eigener/gemerkter Meetups; neue Termine in der Region (NativePHP Push, Permission-Priming aus Onboarding 3.5 bereits gebaut).
- [ ] **B.7 „In der Nähe"** — Geo-Sortierung von Meetups/Terminen nach Gerätestandort (NativePHP Location, opt-in).
- [ ] **B.8 Profil-Ausbau** — Avatar, Bio, eigene Beiträge, Verbindungsstatus, „Abmelden/Token erneuern".
- [ ] **B.9 Pull-to-Refresh & Cache-Status** — einheitliche Refresh-Geste + sichtbarer „zuletzt aktualisiert / offline"-Hinweis (baut auf `servedStaleData`).
- [ ] **B.10 Widget/Quick-Action** — Android-Shortcut „Nächster Termin". *(Stretch.)*
- [ ] **B.11 Mehrsprachigkeit prüfen** — alle neuen Strings via `__()` + `lang/`-Dateien (de/en).
- [ ] **B.12** Integrationssuite erweitern + laufen lassen: die schreibenden/Portal-gebundenen Features (z. B. RSVP B.5, Push B.6) real gegen das lokale Portal-Dev absichern, soweit Endpunkte existieren (`scripts/run-integration.sh`, opt-in).
- [ ] **B.13** Playwright-Validierung der umgesetzten Zusatz-Features (Favoriten, Teilen, Pull-to-Refresh …) gegen die Live-Portal-API (funktionale + visuelle Abnahme im Web-Renderer).

**Empfohlenes MVP-Set für eine spätere Version:** B.1, B.2, B.3, B.6, B.9.

---

## Offene Fragen (übernommen, betreffen dieses Backlog)

- [ ] **RSVP/Teilnehmen-Route** (B.5): Gibt es eine RSVP/Teilnehmen-Route im Portal? → sonst lokaler Reminder.
- [ ] **Push-Infrastruktur** (B.6): nutzt das Portal einen eigenen Push-Provider oder rein clientseitige lokale Notifications?

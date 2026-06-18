# AAA Design Recap & UX/UI-Plan

> Stand: 2026-06-18 · Reviewer: Design-Pass über die laufende App (Playwright, Mobile-Viewport 390×844, lokaler App-Server gegen Produktions-Portal) + Code-Review des Design-Systems (`resources/css/app.css`, `layouts/mobile.blade.php`, geteilte Komponenten).

## TL;DR — Verdikt

Die App ist **bereits sehr nah an AAA**. Das Fundament ist überdurchschnittlich: ein echtes Token-System (Radius-/Elevation-/Motion-Skala), Spring-Motion mit `prefers-reduced-motion`-Fallback, Safe-Area-Insets, Skeletons statt Spinner, Press-Feedback, Listen-Stagger, Haptics und eine markenkohärente Brand-Switch-Celebration. Das ist mehr Sorgfalt, als die meisten produktiven Apps zeigen.

Die Verbesserungen unten sind deshalb **Feinschliff, keine Reparatur**. Es geht um Lesbarkeit bei Fließtext, visuelle Kohäsion (Karte), das Schließen einer echten Funktionslücke (Refresh) und ein paar Konsistenz-/Scan-Themen. Nichts davon erfordert einen Umbau.

---

## Was bereits AAA ist (bewusst nicht anfassen)

- **Token-Source-of-Truth** in `@theme` — Radius (`tile<card<sheet`), Elevation, Spring/Emphasized-Eases. Komponenten greifen darauf zu statt `rounded-2xl` zu streuen.
- **Motion-System** mit dediziertem `prefers-reduced-motion`-Block, der *jede* Animation abschaltet — das ist der Accessibility-Schritt, den fast alle vergessen.
- **Loading/Empty/Error** als eigene Komponenten (`skeleton-card`, `portal-empty-state`, `error-state`) mit gestaffelter Entrance.
- **Native Touch-Feel**: `overscroll-behavior`, kein Tap-Highlight, `user-select:none` außer in Inputs, `.pressable` Scale-Down, Haptics über `$haptic`.
- **Marken-Kohärenz**: Wortmarke wechselt live mit der Region inkl. Celebration-Overlay.

Diese Dinge sind der Grund, dass sich die App schon „teuer" anfühlt. Plan baut darauf auf, ersetzt nichts davon.

---

## Findings (priorisiert)

### P1 — höchster Hebel

**1.1 Fließtext in Monospace (Lesbarkeit)** — ❌ **bewusst verworfen**
`--font-sans: 'Inconsolata'` gilt für *alles*, auch langen Fließtext. Ein Dual-Font (Inconsolata für Marke/Labels, Proportionalschrift nur für `.markdown`) wurde erwogen und **abgelehnt**: Die durchgängige Monospace ist gewollte Markenidentität — kein Zweitfont, ausnahmslos Inconsolata. Hier kein Handlungsbedarf.

**1.2 Kein Refresh-Mechanismus (Funktionslücke)**
Daten kommen aus dem Portal-Cache; es gibt **weder Pull-to-Refresh noch einen manuellen Refresh** auf den Daten-Seiten (verifiziert: nur `overscroll` in CSS, kein `wire:poll`/Refresh außerhalb der 2FA-Codes). Auf einer datengetriebenen Mobile-App ist das die meist-erwartete Geste. Stale-Daten ohne Ausweg fühlen sich kaputt an.
*Lösung:* Pull-to-Refresh (Alpine-Touch-Handler am `<main>`, ruft `unset` der Computed via Livewire-Event) **oder** als lazy v1 ein Refresh-Icon im Header neben der Lupe. Refresh-Icon zuerst — eine Zeile, deckt 80 % ab; PTR nachziehen, wenn die Geste vermisst wird.

### P2 — sichtbare Kohäsion/Scan

**2.1 Helle Karten-Tiles im Dark-Chrome**
Die Leaflet-Karte nutzt Standard-OSM-Tiles (hell) und sitzt mitten in einer durchgehend dunklen App. Der Helligkeitssprung bricht die Kohäsion sofort.
*Lösung:* Dark-Tile-Layer (CARTO Dark Matter o. ä.) im Dark-Mode; Cluster-Marker-Farben (orange/grün) bleiben, kontrastieren auf Dunkel sogar besser.

**2.2 Redundanter Marken-Präfix frisst Listen-Lesbarkeit**
In der Meetup-Liste heißt fast jede Karte „Einundzwanzig <Stadt>" und truncatet zu „Einundzwanzig Nordburgenl…". Der Präfix ist im Kontext (es ist *die* EINUNDZWANZIG-App) redundant und verdrängt genau die Info, die unterscheidet: die Stadt.
*Lösung:* In Listenkarten den Marken-Präfix optisch zurücknehmen — Stadt/Ort prominent, „Einundzwanzig" als schwächeres Präfix oder ganz weg (Detail-Header behält den vollen Namen). Reine View-Änderung.

**2.3 Kontrast der Datum-Badges prüfen** — ✅ **gemessen, bestanden**
Real per Playwright gemessen (sRGB-aufgelöst): Datum-Badge-Text auf dem effektiven Badge-Hintergrund = **13.18:1** → WCAG AA *und* AAA bestanden. Kein Handlungsbedarf.

### P3 — Politur

**3.1 Such-Konsistenz** — ✅ geprüft: Global- und Seiten-Suche sind distinkte, komplementäre Rollen mit identischer Input-Konfiguration. Bereits konsistent, kein Handlungsbedarf.
**3.2 Listen-Dichte** — ✅ umgesetzt: Profil-Umschalter „Normal/Kompakt", persistiert, verdichtet die Browse-Listen.
**3.3 Tab-Bar Aktiv-Farbe** — ✅ gemessen: inaktiv 7.85:1, aktiv 8.62:1 (beide ≥ WCAG AA). Gute Differenzierung, keine Anpassung nötig.

---

## Phasen-Plan

Reihenfolge = Hebel/Aufwand. Jede Phase ist eigenständig mergebar und testbar. Flux-idiomatisch, keine neuen Dependencies außer optional einer zweiten Webfont (lokal via `@fontsource`, wie Inconsolata schon eingebunden ist).

### Phase A — Lesbarkeit & Refresh (P1) — ✅ abgeschlossen
- [x] ~~**A1 Dual-Font für Fließtext.**~~ **Verworfen** (Produktentscheidung): Die App bleibt ausnahmslos Inconsolata — Monospace ist bewusste, durchgängige Markenidentität, auch im Fließtext. Kein Zweitfont. Vollständig zurückgebaut.
- [x] **A2 Header-Refresh-Button.** `arrow-path`-Button links der Lupe. **Architektur-Korrektur:** Layout-Chrome (Header/Main) liegt bei Full-Page-Livewire AUSSERHALB der Seiten-Komponente, `wire:click` band dort nicht (im Browser No-op verifiziert). Jetzt über einen **globalen Livewire-Event** `portal-refresh` (`#[On]` auf `PortalPage`); Bestätigung `portal-refreshed` stoppt den Alpine-Spinner. Refresh erhöht eine Fresh-Cache-Generation in `PortalApi` (nur im Fresh-Key; Stale-Forever-Kopien bleiben Offline-Fallback). Per Playwright verifiziert: Klick → echter `livewire/update`-POST. Tests: `PortalApiTest`, `PortalRefreshButtonTest` (Event → Re-Fetch + `portal-refreshed`).
- [x] **A3 Pull-to-Refresh.** Alpine-Komponente `appRefresh` (in `app.js`) am scrollbaren `<main>`: Touch-Handler mit Gummiband-Widerstand, wachsender Indikator (Icon rotiert proportional, spinnt beim Laden), löst jenseits der Schwelle denselben `portal-refresh`-Event aus. Nur bei `chrome`. End-to-end per Playwright verifiziert (Zug → Refresh-POST → Spinner-Reset).

### Phase B — Visuelle Kohäsion (P2) — ✅ abgeschlossen
- [x] **B1 Dark-Map-Tiles.** CARTO Dark Matter (`dark_all`) in `map/⚡index` **und** `x-map-picker`; Attribution um CARTO ergänzt, `subdomains: 'abcd'`. Da die App dark-only ist, unbedingt umgeschaltet (kein Light/Dark-Branching). Visuell verifiziert — Karte kohärent dunkel, Cluster-Marker poppen. Test: `MapPageTest` auf neue Tile-Quelle angepasst.
- [x] **B2 Listenkarten-Hierarchie.** Neue Komponente `x-meetup-name` nimmt den Marken-Präfix (jede Länder-Variante via `Brand::cases()`) optisch zurück (gedämpft), der unterscheidende Teil (Stadt) führt in `font-semibold`. Eingesetzt in Meetups-Liste (alle + meine) und Termine-Liste; Detail-Header behalten den vollen Namen. Tests: `MeetupNameComponentTest` (Split-Logik) + Listen-Assertions auf `assertSeeText` umgestellt (Markup-agnostisch, `assertDontSeeText` gegen False-Positives).
- [x] **B3 Badge-Kontrast.** Per Playwright real gemessen (sRGB-aufgelöst, 0.4-Alpha über Karte kompositiert): Datum-Badge = **13.18:1** → besteht WCAG AA *und* AAA. **Kein Handlungsbedarf** — das ursprüngliche Flag war übervorsichtig.

### Phase C — Politur (P3, opportunistisch) — ✅ abgeschlossen (untersucht, kein Code-Change nötig)
- [x] **C1 Such-Konsistenz — bereits konsistent.** Global- und Seiten-Suche haben **distinkte, komplementäre Rollen**: global = entitätsübergreifende Command-Palette (Meetups/Kurse/Referenten → direkt ins Detail); Seiten-Suche = Liste filtern + Länderfilter. Beide nutzen identische `flux:input`-Konfiguration (`type=search`, Lupe, `clearable`, 300 ms-Debounce), nur rollenspezifische Platzhalter. Nichts redundant, nichts anzugleichen.
- [x] **C2 Listen-Dichte — umgesetzt (auf Wunsch).** Profil-Umschalter „Normal/Kompakt" (`flux:radio.group` segmented) → `AppPreferences::density()` persistiert in der Geräte-DB. Layout-Wrapper bekommt `density-compact`; CSS verdichtet `.list-stagger` (gap + Card-Padding) via Descendant-Selektor (schlägt die p-4/gap-3-Utilities, kein `!important`). Tap-Flächen bleiben gross genug. Übersetzungen in alle 7 Sprachen ergänzt. Tests: `ListDensityTest` (Default/Validierung/Speichern/Layout-Klasse). Visuell verifiziert.
- [x] **C3 Nav-Kontrast — gemessen, bestanden.** Per Playwright (sRGB): inaktiv (zinc-400) = **7.85:1**, aktiv (Brand-Orange) = **8.62:1** — beide ≥ WCAG AA (normal). Aktiv liegt höher *und* bekommt Solid-Icon + Orange-Pill → klare Differenzierung, inaktiv bleibt gut lesbar. Keine Anpassung nötig.

---

## Test- & Verifikations-Hinweise

- Jede View-Änderung mit einem Pest-/Browser-Smoke abdecken bzw. bestehenden Test anpassen (`scripts/run-browser.sh`, opt-in). Reine CSS-/Font-Änderungen visuell via Playwright auf Mobile-Viewport.
- Nach Frontend-Änderungen `yarn run build` bzw. `composer run dev`.
- `vendor/bin/pint --dirty` vor Abschluss.
- Motion-Änderungen immer auch mit `prefers-reduced-motion: reduce` gegenprüfen (der globale Block deckt neue Keyframes nur ab, wenn sie dort eingetragen sind).

## Bewusst nicht im Scope

- **Schrift anfassen** — die App bleibt ausnahmslos Inconsolata (Monospace = Markenidentität, auch im Fließtext). Kein Zweitfont, kein `.markdown`-Sonderfall.
- **Neues Komponenten-Framework / Redesign** — das Token-System trägt; alles oben sind gezielte Eingriffe.
- **Helles Theme** — App ist bewusst dark-only (`class="dark"` hart gesetzt).

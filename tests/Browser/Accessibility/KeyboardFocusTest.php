<?php

declare(strict_types=1);

/**
 * K2 der Barrierefreiheits-Messreihe: jeder Tastaturhalt braucht einen sichtbaren
 * Fokus (WCAG 2.4.7).
 *
 * Portiert aus `mim-cockpit` (Stand 2026-08-19), das ihn seinerseits aus `mim-pulse-flux`
 * uebernommen hat. Die Lehren von dort gelten unveraendert und sind der Grund fuer jede
 * Eigenheit hier:
 *
 * Warum ein Browser-Test und kein Skript-Fokus: `:focus-visible` haengt daran, ob
 * die letzte Interaktion von der Tastatur kam. Ein per `el.focus()` gesetzter Fokus
 * erfuellt das nicht — eine Messung darueber misst die Chromium-Heuristik, nicht die
 * Seite. Nur echte Tab-Druecke sind belastbar.
 *
 * `keys($selector, ...)` fokussiert das Element vorher. Fuer echtes Durchtabben ist
 * der Selektor deshalb `:focus` — das aktuell fokussierte Element neu zu fokussieren
 * ist folgenlos, und der Tab-Druck geht von dort weiter.
 *
 * Der Einstieg laeuft ueber `:root` und nicht ueber `body`: der Locator des Plugins
 * behandelt einen nackten Bezeichner NICHT als CSS, sondern sucht erst nach
 * id/name und dann nach Text (Selector::isExplicit). `body` laeuft in einen Timeout;
 * `:root` enthaelt einen Doppelpunkt und wird als CSS aufgeloest.
 *
 * KEINE `//`-Kommentare in FOKUS_LESEN: ein eingefuegter Kommentarblock liess
 * `script()` still nichts zurueckgeben, alle Faelle meldeten daraufhin null
 * Tastaturhalte. Erklaerungen gehoeren in diesen Docblock, nicht in das Skript.
 *
 * Der Indikator darf auch an einem VORFAHREN haengen. WCAG 2.4.7 verlangt einen
 * sichtbaren Fokusindikator, nicht dass er auf dem fokussierten Knoten selbst
 * gezeichnet wird — bei TomSelect etwa ist das fokussierte `input` optisch gar nicht
 * vorhanden, sichtbar ist die Huelle `.ts-control`. Gesucht wird bis zu drei Ebenen
 * hoch, und nur bei Vorfahren, die `:focus-within` matchen.
 *
 * Am Vorfahren zaehlt AUSSCHLIESSLICH `outline`, nicht `box-shadow`. Ein Schatten an
 * einer Huelle ist meistens Dekoration (Karte, Eingabefeld-Relief) und liegt dort auch
 * ohne Fokus — er wuerde jeden Treffer gruen faerben. Ein `outline` wird praktisch nie
 * dekorativ gesetzt. Auf dem fokussierten Element selbst zaehlen weiterhin beide.
 *
 * Was `sichtbar` NICHT prueft: `--tw-ring-shadow`. Diese Custom Property ist auf
 * vielen Elementen gesetzt, ohne dass `box-shadow` sie verwendet — sie zu pruefen
 * meldete ueberall "Fokus sichtbar" und liess die Negativkontrolle gruen durchgehen,
 * obwohl die Indikatoren abgeschaltet waren. Nur das gezeichnete Ergebnis zaehlt:
 * `outline` und `box-shadow`.
 */
const LESER = <<<'JS'
const lesen = () => {
  const el = document.activeElement;
  if (!el || el === document.body || el === document.documentElement) {
    return { ende: true };
  }
  el.setAttribute('data-k2-halt', '1');
  const cs = getComputedStyle(el);
  const leer = (s) => !s || s === 'none'
    || /^rgba\(0, 0, 0, 0\) 0px 0px 0px 0px(, rgba\(0, 0, 0, 0\).*)?$/.test(s);
  const outline = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0;
  const schatten = !leer(cs.boxShadow);
  let quelle = outline || schatten ? 'selbst' : 'keine';
  if (quelle === 'keine') {
    let p = el.parentElement;
    for (let i = 0; i < 3 && p; i++, p = p.parentElement) {
      const ps = getComputedStyle(p);
      if (p.matches(':focus-within') && ps.outlineStyle !== 'none' && parseFloat(ps.outlineWidth) > 0) {
        quelle = 'vorfahr';
        break;
      }
    }
  }
  return {
    ende: false,
    sichtbar: quelle !== 'keine',
    quelle,
    focusVisible: el.matches(':focus-visible'),
    tag: el.tagName.toLowerCase(),
    name: (el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 44),
    outline: cs.outlineStyle + ' ' + cs.outlineWidth,
    klassen: (el.getAttribute('class') || '').slice(0, 90),
    typ: el.getAttribute('type') || '',
    wire: el.getAttribute('wire:model') || el.getAttribute('wire:click') || '',
    eltern: el.parentElement
      ? el.parentElement.tagName.toLowerCase() + '.' + (el.parentElement.getAttribute('class') || '').slice(0, 60)
      : '',
  };
};
JS;

const FOKUS_LESEN = '(() => { '.LESER.' return lesen(); })()';

/**
 * Wie gross ist der Fokusbereich, den der Durchlauf tatsaechlich vor sich hatte?
 *
 * Nicht geraten, sondern gemessen: jeder Halt wird beim Lesen mit `data-k2-halt`
 * markiert; hier wird der naechste gemeinsame Vorfahr aller Markierungen gesucht und
 * darin gezaehlt. Erst dieses Verhaeltnis — besucht zu vorhanden IM SELBEN Bereich —
 * sagt, ob der Lauf die Oberflaeche durchmessen hat.
 *
 * Warum nicht einfach das Dokument zaehlen: `/dashboard` oeffnet je nach Zustand einen
 * Dialog, der den Fokus voellig zu Recht einsperrt. Gegen das Dokument gerechnet las
 * sich das als "6 von 39 erreicht" und damit wie ein kaputter Pruefstand — in Wahrheit
 * waren es 6 von 6 im Dialog. Zwei Versuche davor haben NICHT getaugt und sind der
 * Grund fuer diese Form: `dialog[open]` als Bereichsmerkmal traf den Fall nur manchmal,
 * und eine feste Untergrenze von 10 Halten liess den Test in jedem vierten Lauf rot
 * werden, ohne dass sich an der Seite etwas geaendert haette.
 *
 * `bereich` ist zugleich der Zustandsmarker des Laufs: ohne ihn liesse sich nicht
 * sagen, WELCHE Oberflaeche gemessen wurde.
 *
 * Zurueckgegeben wird deshalb AUCH die Zahl im ganzen Dokument und, falls der Bereich
 * kleiner ist, was ihn einsperrt. Sonst waere die Pruefung zirkulaer: bricht der Lauf
 * frueh ab, schrumpft der gemessene Bereich mit ihm, und "alles ausgeschoepft" waere
 * immer wahr. Ein kleinerer Bereich ist nur mit einem Faenger zu rechtfertigen —
 * einem offenen Dialog, `aria-modal`, `role=dialog` oder Alpines `x-trap`.
 */
const BEREICH_MESSEN = <<<'JS'
(() => {
  const sel = 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"]), [contenteditable="true"]';
  const sichtbar = (el) => !el.disabled && el.tabIndex !== -1 && el.offsetParent !== null;
  const halte = Array.from(document.querySelectorAll('[data-k2-halt]'));
  if (halte.length === 0) {
    return { ziele: Array.from(document.querySelectorAll(sel)).filter(sichtbar).length, bereich: 'Dokument' };
  }
  let wurzel = halte[0];
  for (const h of halte.slice(1)) {
    while (wurzel !== document.documentElement && !wurzel.contains(h)) {
      wurzel = wurzel.parentElement;
    }
  }
  const name = wurzel === document.documentElement
    ? 'Dokument'
    : wurzel.tagName.toLowerCase() + (wurzel.id ? '#' + wurzel.id : '');
  const faenger = wurzel.closest('dialog, [aria-modal="true"], [role="dialog"], [x-trap]')
    || Array.from(document.querySelectorAll('dialog')).find((d) => d.open)
    || null;
  const imBereich = Array.from(wurzel.querySelectorAll(sel)).filter(sichtbar);
  return {
    ziele: imBereich.length,
    unbesucht: imBereich.filter((el) => !el.hasAttribute('data-k2-halt')).length,
    zieleDokument: Array.from(document.querySelectorAll(sel)).filter(sichtbar).length,
    bereich: name,
    faenger: faenger ? faenger.tagName.toLowerCase() : '',
  };
})()
JS;

/**
 * Tabbt durch die Seite und gibt jeden Halt zurueck, an dem kein Indikator sichtbar
 * ist. Bricht ab, sobald der Fokus die Seite verlaesst oder das Limit erreicht ist.
 *
 * `abgeschnitten` sagt, ob das Limit gegriffen hat — ohne dieses Feld liesse sich ein
 * abgebrochener Durchlauf nicht von einem vollstaendigen unterscheiden, und ein gruener
 * Lauf haette stillschweigend nur den Anfang der Seite geprueft.
 *
 * JEDER Tastendruck geht an `:root`, nicht an `:focus`. Zwei Gruende, beide gemessen:
 * (1) `Locator::press()` prueft das Ziel vorher auf Bedienbarkeit (sichtbar, stabil,
 * im Sichtfeld). Auf `/dashboard` blieb der Aufruf an einem Navigationslink haengen
 * und lief in den Timeout — der Lauf endete nach einem Halt. `<html>` ist immer
 * stabil, dort kann das nicht passieren.
 * Es wird bis zu ZWEIMAL durchgetabbt, und der zweite Durchgang ist kein Sicherheits-
 * netz, sondern Notwendigkeit: Seiten wachsen waehrend des Laufs. Auf `/events` in
 * `twenty-one-companion` erscheint ein "Erneut versuchen"-Knopf erst, nachdem die
 * Portal-Anfrage ins Leere gelaufen ist — der Tab-Weg war da schon an seiner Stelle
 * vorbei. Der Lauf meldete 8 von 10, obwohl ein zweiter Durchgang alle 10 erreicht.
 * Abgebrochen wird, sobald nichts Unbesuchtes mehr uebrig ist.
 *
 * Vor dem ersten Druck wird auf `networkidle` gewartet. Ohne das lieferte
 * `/dashboard` in einem Lauf 0 Halte und im naechsten 4: Livewire hydriert nach dem
 * Laden nach und setzt den Fokus dabei zurueck auf `<body>`, der erste Tab-Druck
 * ging also ins Leere. Die Zaehl-Sonde davor sah das nicht — sie fand das Markup
 * bereits vollstaendig vor.
 *
 * (2) `.focus()` auf `<html>` setzt den Tab-Weg NICHT zurueck. Gegenprobe mit fuenf
 * `:root`-Druecken hintereinander: Cockpit → Explore Mining Packages → Later →
 * (Knopf) → BODY. Der Fokus wandert also weiter, statt von vorn zu beginnen.
 *
 * @return array{ziele: int, unbesucht: int, durchgaenge: int, zieleDokument: int, bereich: string, faenger: string, halte: int, abgebrochen: bool, abgeschnitten: bool, alle: array<int, array<string, mixed>>, ohne: array<int, array<string, mixed>>}
 */
function tabbeDurch(object $seite, int $maxHalte = 400): array
{
    $seite->page()->waitForLoadState('networkidle');

    $alle = [];
    $ohne = [];
    $abgebrochen = false;
    $durchgaenge = 0;

    do {
        $durchgaenge++;

        for ($i = 0; $i < $maxHalte; $i++) {
            try {
                $seite->keys(':root', 'Tab');
            } catch (Throwable) {
                $abgebrochen = true;
                break;
            }

            $halt = $seite->script(FOKUS_LESEN);

            if (! is_array($halt) || ($halt['ende'] ?? true)) {
                break;
            }

            $alle[] = $halt;

            if (! ($halt['sichtbar'] ?? false)) {
                $ohne[] = $halt;
            }
        }

        $stand = $seite->script(BEREICH_MESSEN);
        $offen = is_array($stand) ? ($stand['unbesucht'] ?? 0) : 0;
    } while ($offen > 0 && ! $abgebrochen && $durchgaenge < 2);

    return [
        'ziele' => is_array($stand) ? ($stand['ziele'] ?? -1) : -1,
        'unbesucht' => is_array($stand) ? ($stand['unbesucht'] ?? -1) : -1,
        'durchgaenge' => $durchgaenge,
        'zieleDokument' => is_array($stand) ? ($stand['zieleDokument'] ?? -1) : -1,
        'bereich' => is_array($stand) ? ($stand['bereich'] ?? '?') : '?',
        'faenger' => is_array($stand) ? ($stand['faenger'] ?? '') : '',
        'halte' => count($alle),
        'abgebrochen' => $abgebrochen,
        'abgeschnitten' => count($alle) >= $maxHalte,
        'alle' => $alle,
        'ohne' => $ohne,
    ];
}

/**
 * Negativkontrolle fuer den Detektor.
 *
 * Sie prueft genau eine Sache: erkennt FOKUS_LESEN ein fokussiertes Element OHNE
 * gezeichneten Indikator als solches? Ohne diesen Nachweis ist ein gruener Lauf
 * nicht von einem blinden zu unterscheiden.
 *
 * Deshalb ohne Tab-Weg und ohne Stylesheet: ein Element mit Inline-Stil, direkt
 * fokussiert, direkt gelesen. Dass das Durchtabben selbst funktioniert, sichert die
 * Untergrenze von 10 Halten im Seitentest ab.
 *
 * Der Knopf wird in den obersten Fokusbereich gehaengt, also in einen offenen
 * `<dialog>`, falls es einen gibt — sonst an `<body>`. Ein per `showModal()` geoeffneter
 * Dialog sperrt den Fokus ein: `focus()` auf ein Element ausserhalb ist dann schlicht
 * wirkungslos. Genau daran scheiterte die Kontrolle am 2026-08-19 in jedem zweiten Lauf,
 * je nachdem ob `/dashboard` seinen Dialog gerade zeigte — und meldete einen
 * Detektor-Defekt, den es nicht gab.
 *
 * BEIDE Messungen laufen in EINEM `script()`-Aufruf. In zwei getrennten Aufrufen
 * flackerte die Kontrolle: `/dashboard` rendert per Livewire nach, und der zwischen
 * den Aufrufen eingehaengte Knopf war beim zweiten Lesen schon wieder aus dem DOM
 * gemorpht — die Kontrolle meldete dann einen Detektor-Defekt, den es nicht gab.
 * Innerhalb einer Auswertung kann kein Re-Render dazwischenkommen.
 */
test('der Detektor erkennt einen fokussierten Knopf ohne Indikator', function () {
    $mess = seite('/meetups')->script('(() => { '.LESER.<<<'JS'
        const dialog = Array.from(document.querySelectorAll('dialog')).find((d) => d.open);
        const wurzel = dialog || document.body;
        const bauen = (stil) => {
          const b = document.createElement('button');
          b.textContent = 'Kontrolle';
          b.style.cssText = stil + ';position:fixed;top:0;left:0';
          wurzel.appendChild(b);
          b.focus();
          return lesen();
        };
        return { ohne: bauen('outline:none;box-shadow:none'), mit: bauen('outline:2px solid red') };
        JS.' })()');

    expect($mess)->toBeArray();

    expect($mess['ohne']['ende'] ?? true)->toBeFalse('Der eingeschleuste Knopf war nicht fokussiert.');
    expect($mess['ohne']['sichtbar'] ?? null)
        ->toBeFalse('Der Detektor haelt einen Knopf mit outline:none fuer sichtbar fokussiert — er ist blind.');

    expect($mess['mit']['sichtbar'] ?? null)
        ->toBeTrue('Der Detektor haelt einen Knopf mit sichtbarem Rand fuer unsichtbar — er meldet Falschbefunde.');
})->group('a11y');

test('jeder Tastaturhalt hat einen sichtbaren Fokus', function (string $pfad) {
    $ergebnis = tabbeDurch(seite($pfad));

    $abbruch = $ergebnis['abgebrochen'] ? 'ja' : 'nein';
    $faenger = $ergebnis['faenger'] !== '' ? "<{$ergebnis['faenger']}>" : 'keiner';
    $lage = "Auf {$pfad}: {$ergebnis['halte']} Tastaturhalte in {$ergebnis['durchgaenge']} Durchgang/-gaengen, "
        ."{$ergebnis['ziele']} fokussierbare Elemente im gemessenen Fokusbereich <{$ergebnis['bereich']}> "
        ."({$ergebnis['zieleDokument']} im ganzen Dokument, Faenger: {$faenger}), davon "
        ."{$ergebnis['unbesucht']} nie erreicht, Abbruch: {$abbruch}.";

    // Der Durchlauf muss seinen Fokusbereich AUSGESCHOEPFT haben, sonst haette ein
    // gruener Lauf stillschweigend nur den Anfang geprueft. Geprueft wird das an den
    // Markierungen selbst — kein Zaehlvergleich, der an einer waehrend des Laufs
    // gewachsenen Seite scheitern koennte, sondern: ist noch ein fokussierbares
    // Element im Bereich UNMARKIERT geblieben?
    expect($ergebnis['unbesucht'])
        ->toBe(
            0,
            "{$lage} Der Lauf hat seinen Fokusbereich nicht ausgeschoepft — entweder greift der Tab-Weg zu kurz (Pruefstand) oder die Seite hat Bedienelemente, die per Tastatur nicht erreichbar sind (WCAG 2.1.1)."
        );

    // Und der Bereich muss das ganze Dokument gewesen sein, sonst hat etwas den Fokus
    // eingesperrt. Ohne diese Pruefung waere die vorige zirkulaer: der Bereich ist der
    // gemeinsame Vorfahr der besuchten Halte, ein frueh abgebrochener Lauf schrumpft
    // ihn also auf genau das, was er geschafft hat — "ausgeschoepft" waere immer wahr.
    // Ein kleinerer Bereich ist nur mit einem Faenger zulaessig (offener Dialog,
    // aria-modal, role=dialog, x-trap); der sperrt den Fokus zu Recht ein.
    if ($ergebnis['faenger'] === '') {
        expect($ergebnis['ziele'])->toBe(
            $ergebnis['zieleDokument'],
            "{$lage} Der Fokus blieb in einem Teilbaum, ohne dass ein Faenger das erklaert — entweder greift der Tab-Weg zu kurz (Pruefstand) oder die Seite sperrt Bedienelemente aus (WCAG 2.1.1)."
        );
    }

    expect($ergebnis['abgeschnitten'])
        ->toBeFalse("{$lage} Das Limit hat gegriffen: der Rest der Seite wurde gar nicht geprueft.");

    $namen = collect($ergebnis['ohne'])
        ->map(fn (array $h): string => "{$h['tag']}[{$h['typ']}] outline={$h['outline']} class=\"{$h['klassen']}\" in {$h['eltern']}")
        ->implode("\n  ");

    expect($ergebnis['ohne'])->toBe(
        [],
        "{$lage}\nOhne sichtbaren Fokus:\n  {$namen}"
    );
})->with([
    '/meetups',
    '/events',
    '/courses',
    '/more',
    '/profile',
])->group('a11y');

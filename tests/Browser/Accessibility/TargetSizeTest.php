<?php

declare(strict_types=1);

/**
 * K4 der Barrierefreiheits-Messreihe: Zielgroessen (WCAG 2.5.8, Stufe AA).
 * Gemessen bei 320 px und 1280 px Breite — schmal und breit sind verschiedene
 * Oberflaechen, und ein Ziel, das nur in einer davon zu klein ist, ist trotzdem zu klein.
 *
 * **Die Abstandsausnahme ist Teil des Kriteriums, nicht Kulanz.** Ein Ziel unter
 * 24x24 CSS-Pixeln besteht trotzdem, wenn ein 24-px-Kreis um seinen Mittelpunkt keinen
 * solchen Kreis eines anderen Ziels schneidet. Wer sie weglaesst, meldet Befunde, die
 * keine sind: am 2026-08-19 waren so 11 von 11 Treffern in `mim-cockpit` und 1 von 1 in
 * `fair-btc` falsch. Ein roher Zaehler „kleiner als 24" ist keine WCAG-Messung.
 *
 * Nicht umgesetzt sind die uebrigen drei Ausnahmen des Kriteriums — „inline" (Ziel im
 * Fliesstext), „essenziell" und „vom Browser bestimmt". Das macht den Lauf STRENGER als
 * die Norm, nie milder: was hier auffaellt, kann noch eine gueltige Ausnahme haben und
 * gehoert dann von Hand entschieden. Der umgekehrte Fehler — etwas durchwinken, das
 * durchfaellt — ist ausgeschlossen.
 *
 * 44x44 (Apple HIG) wird MITGEZAEHLT, aber nicht erzwungen. Es ist eine Design-Zahl fuer
 * Telefon-Oberflaechen, keine Rechtsnorm; die Verwechslung hat in `mim-cockpit` einmal
 * 95 „Befunde" erzeugt, wo es null gab. Die Zahl steht in der Meldung, damit sie
 * beurteilt werden kann, und faellt den Lauf nicht.
 */
const K4_MESSEN = <<<'JS'
const ZIELE = 'a[href], button, input:not([type="hidden"]), select, textarea, summary, '
  + '[role="button"], [role="link"], [role="switch"], [role="checkbox"], [role="radio"], '
  + '[role="menuitem"], [role="tab"], [tabindex]:not([tabindex="-1"])';

const MINDEST = 24;

const messen = () => {
  const kandidaten = [];

  for (const el of document.querySelectorAll(ZIELE)) {
    if (el.disabled || el.closest('[aria-hidden="true"], [inert]')) continue;
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) continue;
    kandidaten.push({
      el: el,
      x: r.left + r.width / 2,
      y: r.top + r.height / 2,
      b: r.width,
      h: r.height,
    });
  }

  const zuKlein = [];
  const unterHig = [];

  for (const k of kandidaten) {
    if (Math.min(k.b, k.h) >= 44) continue;

    const beschreibung = {
      tag: k.el.tagName.toLowerCase(),
      typ: k.el.getAttribute('type') || '',
      breite: Math.round(k.b),
      hoehe: Math.round(k.h),
      name: (k.el.getAttribute('aria-label') || k.el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 30),
      klassen: (k.el.getAttribute('class') || '').slice(0, 60),
    };

    unterHig.push(beschreibung);

    if (Math.min(k.b, k.h) >= MINDEST) continue;

    /* Abstandsausnahme: um jedes Ziel wird ein Kreis mit 24 px Durchmesser gelegt,
       zentriert auf seinen Mittelpunkt. Beruehren sich zwei solche Kreise nicht,
       besteht das kleinere Ziel trotzdem. Zwei Mittelpunkte kollidieren also genau
       dann, wenn ihr Abstand kleiner als 24 px ist. */
    let gedraengt = false;
    for (const a of kandidaten) {
      if (a === k) continue;
      const dx = a.x - k.x;
      const dy = a.y - k.y;
      if (Math.sqrt(dx * dx + dy * dy) < MINDEST) { gedraengt = true; break; }
    }

    if (gedraengt) {
      zuKlein.push({ ...beschreibung, grund: 'unter 24 px UND zu dicht am naechsten Ziel' });
    }
  }

  return {
    breite: window.innerWidth,
    ziele: kandidaten.length,
    zuKlein: zuKlein,
    unterHig: unterHig,
  };
};
JS;

const K4_LAUF = '(() => { '.K4_MESSEN.' return messen(); })()';

/**
 * Negativkontrolle — und sie prueft ausdruecklich BEIDE Richtungen der
 * Abstandsausnahme, weil genau die schon einmal falsch herum angewandt wurde.
 *
 * Drei eingeschleuste Knoepfe: zwei winzige (12x12) direkt nebeneinander — die muessen
 * auffallen; einer allein in einer freien Ecke, ebenso winzig — der darf NICHT
 * auffallen, denn um ihn herum ist Platz.
 */
test('der Zielgroessendetektor wendet die Abstandsausnahme in beide Richtungen an', function () {
    $mess = seite('/meetups')->script('(() => { '.K4_MESSEN.<<<'JS'
        const bauen = (marke, x, y) => {
          const b = document.createElement('button');
          b.setAttribute('aria-label', marke);
          b.style.cssText = 'position:fixed;width:12px;height:12px;left:' + x + 'px;top:' + y + 'px';
          document.body.appendChild(b);
        };
        bauen('K4-ENG-A', 0, 0);
        bauen('K4-ENG-B', 14, 0);
        bauen('K4-FREI', 0, 300);
        return messen();
        JS.' })()');

    $namen = collect($mess['zuKlein'])->pluck('name');

    expect($namen->contains('K4-ENG-A'))
        ->toBeTrue('Zwei winzige Knoepfe im Abstand von 14 px fielen nicht auf — der Detektor ist blind.');
    expect($namen->contains('K4-ENG-B'))
        ->toBeTrue('Zwei winzige Knoepfe im Abstand von 14 px fielen nicht auf — der Detektor ist blind.');
    expect($namen->contains('K4-FREI'))
        ->toBeFalse('Ein winziger Knopf mit Platz ringsum wurde gemeldet — die Abstandsausnahme fehlt, und dann sind fast alle Befunde falsch.');

    // Alle drei sind unter 44 px und muessen in der HIG-Zaehlung auftauchen — sonst
    // misst diese Zahl nichts und der Bericht taeuscht Vollstaendigkeit vor.
    expect(collect($mess['unterHig'])->pluck('name')->contains('K4-FREI'))
        ->toBeTrue('Die 44-px-Zaehlung uebersieht ein 12x12-Ziel.');
})->group('a11y');

test('jedes Ziel ist gross genug oder hat Platz um sich', function (string $pfad, int $breite) {
    // Hier NICHT ueber seite(): `on()->mobile()` setzt ein Geraeteprofil samt eigenem
    // Viewport und Skalierung, und die schlaegt das spaetere setViewportSize — der Lauf
    // meldete 369 px statt der verlangten 320. K4 steuert die Breite selbst, also nur
    // der Dunkelmodus als App-Default.
    //
    // `/forge` liegt hinter `nostr.auth` — dieselbe Session wie in `seiteAlsNostrNutzer()`
    // (tests/Pest.php), hier von Hand gesetzt, weil K4 seite() bewusst nicht benutzt.
    if (str_starts_with($pfad, '/forge')) {
        test()->withSession(['nostr_pubkey' => str_repeat('a', 64)]);
    }
    $seite = visit($pfad)->inDarkMode();
    $seite->page()->setViewportSize($breite, 900);

    $mess = $seite->script(K4_LAUF);

    expect($mess['breite'])->toBe($breite, "Der Lauf misst bei {$mess['breite']} px statt bei {$breite} px — das ist die falsche Oberflaeche.");
    expect($mess['ziele'])->toBeGreaterThan(2, "Bei {$breite} px auf {$pfad} nur {$mess['ziele']} Ziele gefunden — der Lauf ist kaputt, nicht die Seite.");

    $lage = "Auf {$pfad} bei {$breite} px: {$mess['ziele']} Ziele, "
        .count($mess['unterHig']).' davon unter 44x44 (Apple HIG, hier nur nachrichtlich).';

    $namen = collect($mess['zuKlein'])
        ->map(fn (array $b): string => "{$b['breite']}x{$b['hoehe']} px — {$b['tag']}[{$b['typ']}] \"{$b['name']}\" ({$b['grund']}) class=\"{$b['klassen']}\"")
        ->implode("\n  ");

    expect($mess['zuKlein'])->toBe([], "{$lage}\nUnter 24x24 ohne ausreichenden Abstand:\n  {$namen}");
})->with([
    ['/meetups', 320],
    ['/meetups', 1280],
    ['/events', 320],
    ['/courses', 320],
    ['/more', 320],
    ['/profile', 320],
    ['/profile', 1280],
    ['/forge', 320],
    ['/forge', 1280],
])->group('a11y');

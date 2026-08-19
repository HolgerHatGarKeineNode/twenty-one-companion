<?php

declare(strict_types=1);

/**
 * K3 der Barrierefreiheits-Messreihe: jedes Bedienelement braucht einen zugaenglichen
 * Namen (WCAG 4.1.2, und fuer Links 2.4.4). Bestanden: 0 ohne Namen.
 *
 * Vollzaehlig statt Stichprobe — der Lauf geht ueber JEDES Element, das die
 * Barrierefreiheits-Schnittstelle als bedienbar ausweist.
 *
 * Der Name wird hier NACHGEBILDET, nicht aus dem Accessibility-Tree gelesen: Pests
 * `script()` reicht nur ins DOM, an Chromiums AX-Baum kommt man von dort nicht heran.
 * Die Nachbildung folgt der Reihenfolge aus "Accessible Name and Description
 * Computation" und deckt die Faelle ab, die in dieser Anwendung vorkommen:
 * `aria-labelledby`, `aria-label`, zugehoeriges `<label>`, eigener Textinhalt,
 * `value` bei Knopf-Inputs, `alt` bei Bild-Inputs, `title`.
 *
 * Zwei bewusste Abweichungen, beide zu Lasten der Anwendung und nicht zu ihren Gunsten:
 * - `placeholder` zaehlt NICHT als Name. Chromium nimmt ihn als letzten Ausweg, WCAG
 *   akzeptiert ihn nicht als Ersatz — er verschwindet beim Tippen. Ein Feld, das nur
 *   einen Platzhalter hat, faellt hier durch. Das ist strenger als der Browser, aber
 *   richtig.
 * - Inhalt aus `::before`/`::after` bleibt aussen vor. Er ist ueber `getComputedStyle`
 *   zwar lesbar, wird aber von Screenreadern uneinheitlich behandelt.
 *
 * Ausgenommen sind Elemente, die der Baum gar nicht sieht: `aria-hidden="true"`,
 * `inert`, `display:none`, `visibility:hidden` — und alles darunter.
 *
 * Wie bei K1 und K2 gilt: **meldet der Lauf alles gruen, ist ohne Gegenprobe nicht zu
 * unterscheiden, ob er misst oder blind ist.** Deshalb der erste Test unten.
 */
/*
 * ACHTUNG bei `toContain`: die Erwartung ist VARIADISCH — ein zweites Argument ist kein
 * Meldungstext, sondern ein weiterer Suchbegriff. `toContain('x', 'Erklaerung')` sucht
 * also nach beidem und schlaegt an der Erklaerung fehl; bei `not->toContain` geht es
 * still durch und schwaecht die Pruefung. Deshalb steht hier ueberall
 * `expect($…->contains(…))->toBeTrue('Meldung')`.
 */
const K3_MESSEN = <<<'JS'
const BEDIENBAR = 'a[href], button, input, select, textarea, summary, '
  + '[role="button"], [role="link"], [role="switch"], [role="checkbox"], [role="radio"], '
  + '[role="menuitem"], [role="tab"], [role="combobox"], [tabindex]:not([tabindex="-1"])';

const versteckt = (el) => {
  if (el.closest('[aria-hidden="true"], [inert]')) return true;
  const cs = getComputedStyle(el);
  if (cs.display === 'none' || cs.visibility === 'hidden') return true;
  const r = el.getBoundingClientRect();
  return r.width === 0 && r.height === 0;
};

const text = (el) => (el.textContent || '').replace(/\s+/g, ' ').trim();

const name = (el) => {
  const ids = (el.getAttribute('aria-labelledby') || '').split(/\s+/).filter(Boolean);
  if (ids.length) {
    const s = ids.map((id) => {
      const z = document.getElementById(id);
      return z ? text(z) : '';
    }).join(' ').trim();
    if (s) return { name: s, quelle: 'aria-labelledby' };
  }

  const label = (el.getAttribute('aria-label') || '').trim();
  if (label) return { name: label, quelle: 'aria-label' };

  const tag = el.tagName.toLowerCase();

  if (['input', 'select', 'textarea'].includes(tag)) {
    let zu = null;
    if (el.id) zu = document.querySelector('label[for="' + CSS.escape(el.id) + '"]');
    if (!zu) zu = el.closest('label');
    if (zu && text(zu)) return { name: text(zu), quelle: 'label' };

    const typ = (el.getAttribute('type') || '').toLowerCase();
    if (['submit', 'button', 'reset'].includes(typ) && (el.value || '').trim()) {
      return { name: el.value.trim(), quelle: 'value' };
    }
    if (typ === 'image' && (el.getAttribute('alt') || '').trim()) {
      return { name: el.getAttribute('alt').trim(), quelle: 'alt' };
    }
  } else if (text(el)) {
    return { name: text(el), quelle: 'Textinhalt' };
  }

  const bildAlt = Array.from(el.querySelectorAll('img[alt], svg title'))
    .map((z) => (z.getAttribute ? (z.getAttribute('alt') || '') : '') || text(z))
    .map((s) => s.trim())
    .filter(Boolean)
    .join(' ');
  if (bildAlt) return { name: bildAlt, quelle: 'alt/title im Inhalt' };

  const titel = (el.getAttribute('title') || '').trim();
  if (titel) return { name: titel, quelle: 'title' };

  return { name: '', quelle: (el.getAttribute('placeholder') || '').trim() ? 'nur placeholder' : 'keine' };
};

const messen = () => {
  const ohne = [];
  let geprueft = 0;

  for (const el of document.querySelectorAll(BEDIENBAR)) {
    if (el.disabled || versteckt(el)) continue;
    geprueft++;
    const n = name(el);
    if (!n.name) {
      ohne.push({
        marke: el.getAttribute('data-k3') || '',
        tag: el.tagName.toLowerCase(),
        typ: el.getAttribute('type') || '',
        rolle: el.getAttribute('role') || '',
        grund: n.quelle,
        platzhalter: (el.getAttribute('placeholder') || '').slice(0, 30),
        klassen: (el.getAttribute('class') || '').slice(0, 60),
        eltern: el.parentElement ? el.parentElement.tagName.toLowerCase() + '.' + (el.parentElement.getAttribute('class') || '').slice(0, 40) : '',
      });
    }
  }

  return { geprueft: geprueft, ohne: ohne };
};
JS;

const K3_LAUF = '(() => { '.K3_MESSEN.' return messen(); })()';

/**
 * Negativkontrolle. Vier eingeschleuste Elemente mit bekanntem Ausgang:
 * ein Knopf ohne alles (muss auffallen), ein Feld mit NUR Platzhalter (muss auffallen —
 * siehe Docblock oben), ein Knopf mit `aria-label` und ein Feld mit `<label for>`
 * (duerfen beide nicht auffallen).
 */
test('der Namensdetektor findet ein Bedienelement ohne Namen', function () {
    $mess = seite('/meetups')->script('(() => { '.K3_MESSEN.<<<'JS'
        const h = document.createElement('div');
        h.id = 'k3-kontrolle';
        // Groesse ausdruecklich setzen: ein leerer Knopf ist 0x0, und 0x0 gilt dem
        // Detektor zu Recht als unsichtbar. Ohne die Groesse prueft die Kontrolle
        // nichts und war beim ersten Lauf am 2026-08-19 genau deshalb rot.
        h.innerHTML =
          '<button data-k3="ohne" style="width:40px;height:40px"></button>'
          + '<input data-k3="nur-platzhalter" type="text" placeholder="K3 Platzhalter">'
          + '<button data-k3="mit-aria" style="width:40px;height:40px" aria-label="K3 mit aria-label"></button>'
          + '<label for="k3-mit-label">K3 mit label</label><input data-k3="mit-label" id="k3-mit-label" type="text">';
        document.body.appendChild(h);
        return messen();
        JS.' })()');

    $klassen = collect($mess['ohne']);

    $marken = $klassen->pluck('marke');
    $gruende = $klassen->where('marke', '!=', '')->pluck('grund');

    expect($marken->contains('mit-aria'))
        ->toBeFalse('Ein Knopf mit aria-label wurde als namenlos gemeldet.');
    expect($marken->contains('mit-label'))
        ->toBeFalse('Ein Feld mit zugehoerigem <label> wurde als namenlos gemeldet.');

    expect($gruende->contains('keine'))
        ->toBeTrue('Ein Knopf ohne jede Namensquelle wurde nicht gefunden — der Detektor ist blind.');
    expect($gruende->contains('nur placeholder'))
        ->toBeTrue('Ein Feld mit nur einem Platzhalter gilt hier als namenlos — der Detektor sieht das nicht.');
})->group('a11y');

/**
 * Gegenprobe zur anderen Richtung: die Kontrolle oben zeigt, dass der Detektor Fehler
 * FINDET. Dieser Test zeigt, dass er sie nicht ERFINDET — vier korrekt benannte
 * Elemente in allen vier Namensquellen duerfen nicht auffallen.
 */
test('der Namensdetektor meldet korrekt benannte Elemente nicht', function () {
    $mess = seite('/meetups')->script('(() => { '.K3_MESSEN.<<<'JS'
        const h = document.createElement('div');
        h.innerHTML =
          '<span id="k3-quelle">K3 per labelledby</span>'
          + '<button data-k3="labelledby" aria-labelledby="k3-quelle"></button>'
          + '<button data-k3="aria-label" aria-label="K3 per aria-label"></button>'
          + '<button data-k3="textinhalt">K3 per Textinhalt</button>'
          + '<button data-k3="title" title="K3 per title"></button>';
        document.body.appendChild(h);
        return messen();
        JS.' })()');

    // NUR die eingeschleusten Elemente pruefen. Die erste Fassung verlangte, dass die
    // GANZE Seite ohne Befund ist — das ging in fair-btc zufaellig gut und fiel in
    // twenty-one-companion sofort um, weil die Seite echte namenlose Elemente hat. Eine
    // Kontrolle, die am Prueflingszustand haengt, kontrolliert nichts.
    $eingeschleust = collect($mess['ohne'])->where('marke', '!=', '')->pluck('marke');

    expect($eingeschleust->all())
        ->toBe([], 'Der Detektor meldet korrekt benannte Elemente als namenlos — er erfindet Befunde.');
})->group('a11y');

test('jedes Bedienelement hat einen zugaenglichen Namen', function (string $pfad) {
    $seite = seite($pfad);

    $mess = $seite->script(K3_LAUF);

    expect($mess['geprueft'])->toBeGreaterThan(2, "Auf {$pfad} wurden nur {$mess['geprueft']} Bedienelemente geprueft — der Lauf ist kaputt, nicht die Seite.");

    $lage = "Auf {$pfad}: {$mess['geprueft']} Bedienelemente geprueft.";

    $namen = collect($mess['ohne'])
        ->map(fn (array $b): string => "{$b['tag']}[{$b['typ']}] role={$b['rolle']} Grund: {$b['grund']}"
            .($b['platzhalter'] !== '' ? " (placeholder \"{$b['platzhalter']}\")" : '')
            ." class=\"{$b['klassen']}\" in {$b['eltern']}")
        ->implode("\n  ");

    expect($mess['ohne'])->toBe([], "{$lage}\nOhne zugaenglichen Namen:\n  {$namen}");
})->with([
    '/meetups',
    '/events',
    '/courses',
    '/more',
    '/profile',
])->group('a11y');

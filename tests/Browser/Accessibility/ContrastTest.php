<?php

declare(strict_types=1);

/**
 * K1 der Barrierefreiheits-Messreihe: Textkontrast gegen den TATSAECHLICH gerenderten
 * Untergrund (WCAG 1.4.3). Bestanden: alles >= 4,5:1, grosser oder fetter Text >= 3:1.
 *
 * Diese Anwendung laeuft immer im Dunkelmodus — `layouts/app.blade.php` setzt
 * `localStorage['flux.appearance'] = 'dark'` fest. Der Lauf prueft das als
 * Zustandsmarker; ohne ihn waere nicht zu sagen, welche Farbwelt gemessen wurde.
 *
 * Drei Fehler aus frueheren Laeufen dieser Messreihe stecken hier als Vorkehrung drin.
 * Alle drei liessen eine Messung GRUEN aussehen oder erfanden Befunde, die es nicht gab:
 *
 * (1) VERLAUFSBLINDHEIT. Ein `bgOf()`, das nur `background-color` liest, findet bei
 *     `bg-gradient-to-br` nichts und faellt auf Weiss zurueck. In `mim-cockpit` meldete
 *     das "1,00:1 weiss auf weiss" auf einer Seite, die voellig in Ordnung war. Ein
 *     Verlauf ist `background-image`; trifft die Suche einen, ist das Ergebnis
 *     **unklar** und wird als solches ausgewiesen — nicht als bestanden und nicht als
 *     Befund.
 * (2) FALSCHE ALPHA-RECHNUNG. Ein `over()`, das die Deckkraft des Ergebnisses
 *     bedingungslos auf 1 setzt, laesst die Kette bei zwei gestapelten
 *     halbtransparenten Ebenen "undurchsichtig werden", bevor der dunkle Koerper unten
 *     ueberhaupt angewandt wird. In `mim-pulse-flux` ergab das 1,00:1, wo in
 *     Wirklichkeit ~7:1 stehen. Richtig ist `outA = fa + ba * (1 - fa)`.
 * (3) FARBEN NUR ALS `rgb()` GELESEN. Tailwind v4 liefert Farben als `oklch()`, Flux
 *     zusaetzlich als `oklab()` — `getComputedStyle().color` gibt genau das zurueck.
 *     Ein Parser, der die Zahlen aus dem String zieht und als r/g/b nimmt, macht aus
 *     `oklch(0.871 0.006 286.286)` ein `rgb(0, 0, 286)` und meldet Befunde, die es
 *     nicht gibt (am 2026-08-19 im ersten Lauf hier: sechs Stueck, alle erfunden).
 *     Gelesen wird deshalb ueber eine 1x1-Leinwand: `fillStyle` nimmt JEDE gueltige
 *     CSS-Farbe an, `getImageData` gibt sRGB-Bytes zurueck. Vor dem Setzen steht
 *     `rgba(0,0,0,0)`, damit eine ungueltige Angabe transparent bleibt statt still den
 *     Vorgaengerwert zu behalten. Die Alpha-Ruecknahme der Leinwand rundet bei sehr
 *     kleinen Deckkraeften; fuer Kontrastschwellen ist das ohne Belang.
 *
 * (4) KEINE NEGATIVKONTROLLE. Meldet ein Lauf alles gruen, ist das ohne Gegenprobe
 *     nicht davon zu unterscheiden, dass der Detektor blind ist. Deshalb der erste Test
 *     unten: eine bekannt durchfallende und eine bekannt bestehende Stelle, beide
 *     eingeschleust, beide muessen richtig einsortiert werden.
 */
/*
 * ACHTUNG bei `toContain`: die Erwartung ist VARIADISCH — ein zweites Argument ist kein
 * Meldungstext, sondern ein weiterer Suchbegriff. `toContain('x', 'Erklaerung')` sucht
 * also nach beidem und schlaegt an der Erklaerung fehl; bei `not->toContain` geht es
 * still durch und schwaecht die Pruefung. Deshalb steht hier ueberall
 * `expect($…->contains(…))->toBeTrue('Meldung')`.
 */
const K1_MESSEN = <<<'JS'
const leinwand = document.createElement('canvas');
leinwand.width = 1;
leinwand.height = 1;
const stift = leinwand.getContext('2d', { willReadFrequently: true });

const farbe = (s) => {
  if (!s || s === 'transparent' || s === 'none') return { r: 0, g: 0, b: 0, a: 0 };
  stift.clearRect(0, 0, 1, 1);
  stift.fillStyle = 'rgba(0, 0, 0, 0)';
  stift.fillStyle = s;
  stift.fillRect(0, 0, 1, 1);
  const d = stift.getImageData(0, 0, 1, 1).data;
  return { r: d[0], g: d[1], b: d[2], a: d[3] / 255 };
};

const ueber = (vorn, hinten) => {
  const a = vorn.a + hinten.a * (1 - vorn.a);
  if (a === 0) return { r: 0, g: 0, b: 0, a: 0 };
  const k = (v, h) => (v * vorn.a + h * hinten.a * (1 - vorn.a)) / a;
  return { r: k(vorn.r, hinten.r), g: k(vorn.g, hinten.g), b: k(vorn.b, hinten.b), a };
};

const leuchtdichte = (c) => {
  const f = (v) => {
    v /= 255;
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
};

const kontrast = (x, y) => {
  const a = leuchtdichte(x);
  const b = leuchtdichte(y);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
};

const untergrund = (el) => {
  const ebenen = [];
  let k = el;
  while (k) {
    const cs = getComputedStyle(k);
    if (cs.backgroundImage && cs.backgroundImage !== 'none') {
      return { unklar: 'background-image an ' + k.tagName.toLowerCase() };
    }
    const c = farbe(cs.backgroundColor);
    if (c && c.a > 0) {
      ebenen.push(c);
      if (c.a >= 1) {
        return { farbe: ebenen.reduceRight((h, v) => ueber(v, h)), ebenen: ebenen.length };
      }
    }
    k = k.parentElement;
  }
  return { unklar: 'keine deckende Ebene bis zur Wurzel' };
};

const sichtbar = (el) => {
  const cs = getComputedStyle(el);
  if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) === 0) return false;
  const r = el.getBoundingClientRect();
  return r.width > 0 && r.height > 0;
};

const messen = () => {
  const treffer = [];
  const unklar = [];
  let geprueft = 0;

  for (const el of document.querySelectorAll('body *')) {
    const eigenerText = Array.from(el.childNodes)
      .filter((n) => n.nodeType === 3 && n.textContent.trim().length > 0)
      .map((n) => n.textContent.trim())
      .join(' ');
    if (!eigenerText || !sichtbar(el)) continue;

    const cs = getComputedStyle(el);
    const u = untergrund(el);
    if (u.unklar) {
      unklar.push({ text: eigenerText.slice(0, 40), grund: u.unklar, tag: el.tagName.toLowerCase() });
      continue;
    }

    const vg = farbe(cs.color);
    if (!vg) continue;
    const text = vg.a < 1 ? ueber(vg, u.farbe) : vg;

    const px = parseFloat(cs.fontSize);
    const fett = parseInt(cs.fontWeight, 10) >= 700;
    const gross = px >= 24 || (fett && px >= 18.66);
    const schwelle = gross ? 3 : 4.5;

    geprueft++;
    const wert = kontrast(text, u.farbe);
    if (wert < schwelle) {
      treffer.push({
        text: eigenerText.slice(0, 40),
        tag: el.tagName.toLowerCase(),
        vordergrund: cs.color,
        untergrund: 'rgb(' + Math.round(u.farbe.r) + ', ' + Math.round(u.farbe.g) + ', ' + Math.round(u.farbe.b) + ')',
        ebenen: u.ebenen,
        px: px,
        fett: fett,
        wert: Math.round(wert * 100) / 100,
        schwelle: schwelle,
        klassen: (el.getAttribute('class') || '').slice(0, 70),
      });
    }
  }

  return {
    dunkel: document.documentElement.classList.contains('dark'),
    geprueft: geprueft,
    treffer: treffer,
    unklar: unklar,
  };
};
JS;

const K1_LAUF = '(() => { '.K1_MESSEN.' return messen(); })()';

/**
 * Negativkontrolle. Zwei eingeschleuste Absaetze mit bekannten Werten:
 * `#777777` auf `#888888` = 1,22:1 (muss auffallen), `#ffffff` auf `#000000` = 21:1
 * (darf nicht auffallen). Faellt einer der beiden falsch aus, misst der Lauf nichts.
 *
 * Beide Farben sind undurchsichtig und ohne Verlauf gesetzt — die Kontrolle prueft den
 * Detektor, nicht die Kompositionskette. Deren eigene Gegenprobe ist der dritte Test.
 */
test('der Kontrastdetektor findet eine bekannt durchfallende Stelle', function () {
    $mess = seite('/meetups')->script('(() => { '.K1_MESSEN.<<<'JS'
        const bauen = (vg, bg, marke) => {
          const d = document.createElement('div');
          d.style.cssText = 'background:' + bg + ';color:' + vg + ';position:fixed;top:0;left:0;font-size:16px';
          d.textContent = marke;
          document.body.appendChild(d);
        };
        bauen('#777777', '#888888', 'K1-KONTROLLE-SCHLECHT');
        bauen('#ffffff', '#000000', 'K1-KONTROLLE-GUT');
        bauen('oklch(0.871 0.006 286.286)', 'oklch(0.21 0.006 285.885)', 'K1-KONTROLLE-OKLCH-GUT');
        bauen('oklch(0.442 0.017 285.786)', 'oklch(0.21 0.006 285.885)', 'K1-KONTROLLE-OKLCH-SCHLECHT');
        return messen();
        JS.' })()');

    $texte = collect($mess['treffer'])->pluck('text');

    expect($texte->contains('K1-KONTROLLE-SCHLECHT'))
        ->toBeTrue('Die bekannt durchfallende Stelle (1,22:1) wurde nicht gefunden — der Detektor ist blind.');
    expect($texte->contains('K1-KONTROLLE-GUT'))
        ->toBeFalse('Die bekannt bestehende Stelle (21:1) wurde als Befund gemeldet — der Detektor erfindet.');

    // Dieselbe Pruefung in oklch — dem Format, in dem Tailwind v4 und Flux ihre Farben
    // tatsaechlich ausliefern. Ein Parser, der nur rgb() versteht, kommt hier auf
    // Unsinnswerte und meldet die gute Paarung als Befund.
    expect($texte->contains('K1-KONTROLLE-OKLCH-SCHLECHT'))
        ->toBeTrue('In oklch angegebene Farben werden falsch gelesen — genau daran scheiterte der erste Lauf.');
    expect($texte->contains('K1-KONTROLLE-OKLCH-GUT'))
        ->toBeFalse('Eine gute oklch-Paarung wurde als Befund gemeldet — der Parser rechnet falsch.');

    $schlecht = collect($mess['treffer'])->firstWhere('text', 'K1-KONTROLLE-SCHLECHT');
    expect($schlecht['wert'])->toBeGreaterThan(1.1)->toBeLessThan(1.35);
})->group('a11y');

/**
 * Gegenprobe der Kompositionskette — der Teil, an dem die Messreihe zweimal
 * gescheitert ist.
 *
 * Zwei gestapelte halbtransparente Ebenen ueber einem dunklen Koerper: mit der
 * falschen Alpha-Rechnung (`outA = 1`) wird die Kette undurchsichtig, bevor der
 * Koerper angewandt ist, und meldet fuer weissen Text 1,00:1. Richtig gerechnet liegt
 * der Wert klar ueber 4,5. Und eine Ebene mit Verlauf muss als `unklar` landen, nicht
 * als Befund.
 */
test('die Kompositionskette rechnet Transparenz und Verlaeufe richtig', function () {
    $mess = seite('/meetups')->script('(() => { '.K1_MESSEN.<<<'JS'
        const grund = document.createElement('div');
        grund.style.cssText = 'background:#09090b;position:fixed;top:0;left:0;padding:4px';
        const e1 = document.createElement('div');
        e1.style.cssText = 'background:rgba(255,255,255,0.1);padding:4px';
        const e2 = document.createElement('div');
        e2.style.cssText = 'background:rgba(255,255,255,0.2);color:#ffffff;font-size:16px';
        e2.textContent = 'K1-GESTAPELT';
        e1.appendChild(e2);
        grund.appendChild(e1);
        document.body.appendChild(grund);

        const verlauf = document.createElement('div');
        verlauf.style.cssText = 'background:linear-gradient(to right,#000,#fff);color:#808080;position:fixed;top:40px;left:0;font-size:16px';
        verlauf.textContent = 'K1-VERLAUF';
        document.body.appendChild(verlauf);

        return messen();
        JS.' })()');

    $treffer = collect($mess['treffer'])->pluck('text');

    expect($treffer->contains('K1-GESTAPELT'))
        ->toBeFalse('Weiss auf zwei transparenten Ebenen ueber #09090b faellt durch — die Alpha-Rechnung stimmt nicht.');

    expect(collect($mess['unklar'])->pluck('text')->contains('K1-VERLAUF'))
        ->toBeTrue('Ein Verlauf muss als unklar ausgewiesen werden, nicht stillschweigend uebergangen.');
    expect($treffer->contains('K1-VERLAUF'))
        ->toBeFalse('Ein Verlauf wurde als messbarer Untergrund behandelt — das erfindet Befunde.');
})->group('a11y');

test('jeder sichtbare Text erreicht seine Kontrastschwelle', function (string $pfad) {
    $seite = seiteFuerA11y($pfad);

    $mess = $seite->script(K1_LAUF);

    expect($mess['dunkel'])->toBeTrue('Der Lauf misst nicht im Dunkelmodus — dann misst er die falsche Farbwelt.');
    expect($mess['geprueft'])->toBeGreaterThan(3, "Auf {$pfad} wurden nur {$mess['geprueft']} Textstellen geprueft — der Lauf ist kaputt, nicht die Seite.");

    $lage = "Auf {$pfad}: {$mess['geprueft']} Textstellen geprueft, "
        .count($mess['unklar']).' unklar (Verlauf o. ae.).';

    $namen = collect($mess['treffer'])
        ->map(fn (array $b): string => "{$b['wert']}:1 (Schwelle {$b['schwelle']}) — {$b['vordergrund']} auf {$b['untergrund']} "
            ."ueber {$b['ebenen']} Ebene(n), {$b['px']}px".($b['fett'] ? ' fett' : '').", \"{$b['text']}\" class=\"{$b['klassen']}\"")
        ->implode("\n  ");

    expect($mess['treffer'])->toBe([], "{$lage}\nUnter der Schwelle:\n  {$namen}");
})->with([
    '/meetups',
    '/events',
    '/courses',
    '/more',
    '/profile',
    '/forge',
    // `?tab=repos` ist eine EIGENE Lage, keine Wiederholung von `/forge`: auf
    // dem mobilen Viewport ist die Bühne einspaltig und der Startwert des Tabs
    // „Aktivität", die Werkbank stand also in KEINEM der vier Kriterien je im
    // Bild. Gemessen wird hier ihr LEERZUSTAND — dieser Prüfstand bringt kein
    // Relay mit (`NOSTR_WORKSPACE_URL` zeigt bewusst auf einen toten Port), also
    // ist `overview.repos` leer.
    //
    // **Was diese Zeile NICHT abdeckt, und das gehört dazugesagt:** das
    // Suchfeld der Forge (P5) steht zwar im Dokument, ist aber unsichtbar —
    // `x-show="overview.repos.length > 0"`, und ohne Relay gibt es keine.
    // Gemessen (2026-08-23): `{imDom:true, sichtbar:false, repos:0}`. Seine
    // Barrierefreiheit wird deshalb dort geprüft, wo echte Daten liegen:
    // `einundzwanzig-group/tests/e2e/forge-patches.spec.ts` (zugänglicher Name,
    // Zielgröße, sichtbarer Fokus) gegen den worker-eigenen zooid.
    '/forge?tab=repos',
])->group('a11y');

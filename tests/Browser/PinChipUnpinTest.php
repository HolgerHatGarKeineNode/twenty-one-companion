<?php

declare(strict_types=1);

/**
 * The unpin control on Start's pinned row (after the v1.13.0 device sighting).
 *
 * It used to stand NEXT to each chip as a bare Heroicons `map-pin` — in an app full of
 * meetups and maps that reads as "location", and outside the chip border it looked like a
 * stray marker rather than a control. Now it is a × inside the chip (`pin-toggle` form
 * `chip`), and every other pin toggle draws a thumbtack (`flux:icon.pin`, Lucide).
 *
 * Opened with `visit()` + `setViewportSize()`, not `seite()`: `on()->mobile()` brings its
 * own viewport and beats a later resize (the K4 trap, see TargetSizeTest.php).
 */

/**
 * The four Heroicons `map-pin` paths (outline/solid/mini/micro) by their opening command —
 * the glyph this test says is gone from every pin toggle.
 */
const MAP_PIN_PATH_STARTS = ['M15 10.5a3', 'm11.54 22.351', 'm9.69 18.933', 'm7.539 14.841'];

/**
 * Per chip on Start: the frame, the link and the × with their boxes, the ×'s name and
 * pressed state, and the WCAG 2.5.8 verdict — ≥ 24 × 24, or the spacing exception (a 24 px
 * circle around its centre meets no other target's circle; same rule as K4).
 */
const PIN_CHIP_MEASURE = <<<'JS'
    () => {
        const targets = [...document.querySelectorAll('a[href], button, input, select, textarea, summary, [role="button"], [tabindex]:not([tabindex="-1"])')]
            .filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden'; });
        const centre = (r) => ({ x: r.left + r.width / 2, y: r.top + r.height / 2 });
        const box = (r) => ({ left: r.left, top: r.top, right: r.right, bottom: r.bottom, width: r.width, height: r.height });

        return [...document.querySelectorAll('[data-start-angeheftet] [data-pin-chip-frame]')].map((frame) => {
            const link = frame.querySelector('[data-pin-chip]');
            const toggle = frame.querySelector('[data-pin-toggle]');
            const f = frame.getBoundingClientRect();
            const t = toggle ? toggle.getBoundingClientRect() : null;
            let spaced = false;
            if (t) {
                const c = centre(t);
                spaced = targets.every((other) => {
                    if (other === toggle) return true;
                    const o = centre(other.getBoundingClientRect());
                    return Math.hypot(o.x - c.x, o.y - c.y) >= 24;
                });
            }

            return {
                key: link ? link.getAttribute('data-pin-chip') : null,
                frame: box(f),
                link: link ? box(link.getBoundingClientRect()) : null,
                toggle: t ? box(t) : null,
                toggleInsideLink: !!(toggle && link && link.contains(toggle)),
                name: toggle ? (toggle.getAttribute('aria-label') || '').trim() : null,
                pressed: toggle ? toggle.getAttribute('aria-pressed') : null,
                bigEnough: !!t && t.width >= 24 && t.height >= 24,
                spaced,
            };
        });
    }
    JS;

function pinChipWait(object $page, string $expression): mixed
{
    for ($i = 0; $i < 50; $i++) {
        $value = $page->script($expression);

        if ($value) {
            return $value;
        }

        usleep(200_000);
    }

    return null;
}

function pinChipStart(int $width): object
{
    $page = visit('/start')->inDarkMode();
    $page->page()->setViewportSize($width, 900);
    $page->page()->waitForLoadState('networkidle');

    expect($page->script('() => window.innerWidth'))->toBe($width);

    pinChipWait($page, "() => !!Alpine.store('pinSet')");
    // `toggle()` flips, and a guest's set may already hold an entry (it lives in
    // `localStorage`) — so pin only what is not pinned yet.
    $page->script("() => { const pins = Alpine.store('pinSet'); for (const key of ['area:kurse', 'area:meetups']) { if (!pins.has(key)) pins.toggle(key); } }");
    pinChipWait($page, <<<'JS'
        () => ['area:kurse', 'area:meetups'].every((key) => document.querySelector('[data-start-angeheftet] [data-pin-chip="' + key + '"]'))
        JS);

    return $page;
}

it('puts the unpin × inside each pinned chip on Start, named and at least 24 × 24', function () {
    $page = pinChipStart(320);

    $chips = $page->script(PIN_CHIP_MEASURE);

    expect(count($chips))->toBeGreaterThanOrEqual(2);

    foreach ($chips as $chip) {
        $where = "chip {$chip['key']}";

        expect($chip['toggle'])->not->toBeNull("{$where}: no unpin button inside the chip frame")
            ->and($chip['toggleInsideLink'])->toBeFalse("{$where}: the button sits inside the link and would navigate")
            ->and($chip['toggle']['left'])->toBeGreaterThanOrEqual($chip['frame']['left'] - 0.5, "{$where}: × sticks out left")
            ->and($chip['toggle']['right'])->toBeLessThanOrEqual($chip['frame']['right'] + 0.5, "{$where}: × sticks out right")
            ->and($chip['toggle']['top'])->toBeGreaterThanOrEqual($chip['frame']['top'] - 0.5, "{$where}: × sticks out at the top")
            ->and($chip['toggle']['bottom'])->toBeLessThanOrEqual($chip['frame']['bottom'] + 0.5, "{$where}: × sticks out at the bottom")
            ->and($chip['link']['right'])->toBeLessThanOrEqual($chip['toggle']['left'] + 0.5, "{$where}: link and × overlap")
            ->and($chip['name'])->toBe('Anheftung von Bereich aufheben', "{$where}: the × has no accessible name")
            ->and($chip['pressed'])->toBe('true', "{$where}: aria-pressed is gone")
            ->and($chip['bigEnough'] || $chip['spaced'])->toBeTrue("{$where}: target {$chip['toggle']['width']}×{$chip['toggle']['height']} is under 24×24 and crowded (WCAG 2.5.8)");
    }
});

it('draws no map-pin glyph in any pin toggle, and a thumbtack where the toggle is a glyph', function () {
    // 1280 px: the desktop rail shows the same pins as a bar, with the ICON form of the
    // toggle next to each row — so both forms are on this one page.
    $page = pinChipStart(1280);
    pinChipWait($page, "() => document.querySelectorAll('[data-rail-pins] [data-pin-toggle]').length >= 2");

    $glyphs = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-pin-toggle]')].map((toggle) => ({
            form: toggle.closest('[data-pin-chip-frame]') ? 'chip' : 'icon',
            thumbtacks: toggle.querySelectorAll('svg[data-icon-pin]').length,
            paths: [...toggle.querySelectorAll('svg path')].map((p) => p.getAttribute('d') || ''),
        }))
        JS);

    $chipCount = collect($glyphs)->where('form', 'chip')->count();

    expect($chipCount)->toBeGreaterThanOrEqual(2)
        ->and(collect($glyphs)->where('form', 'icon')->count())->toBe($chipCount, 'every pinned row of the rail carries its own glyph toggle');

    foreach ($glyphs as $glyph) {
        foreach ($glyph['paths'] as $path) {
            foreach (MAP_PIN_PATH_STARTS as $start) {
                expect(str_starts_with($path, $start))->toBeFalse("a {$glyph['form']} pin toggle still draws Heroicons map-pin ({$start}…)");
            }
        }

        if ($glyph['form'] === 'icon') {
            // Two glyphs per toggle: filled (pinned) and outline (not) — the shape carries
            // the state, not only the colour (WCAG 1.4.1).
            expect($glyph['thumbtacks'])->toBe(2, 'the icon-form toggle does not draw the thumbtack in both states');
        }
    }
});

it('still opens the item when the chip label is tapped', function () {
    $page = pinChipStart(320);

    $page->script("() => document.querySelector('[data-start-angeheftet] [data-pin-chip=\"area:kurse\"]').click()");
    $url = pinChipWait($page, "() => location.pathname !== '/start' ? location.pathname : ''");

    expect($url)->toBe(route('group.bereich.kurse', absolute: false), 'tapping the chip label did not open the pinned area');
});

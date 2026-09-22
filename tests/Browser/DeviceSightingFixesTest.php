<?php

declare(strict_types=1);

/**
 * Two findings of the device sighting v1.13.0 that only a browser can see: both surfaces are
 * decided in the island, the server renders the same markup either way.
 */
function waitForScript(object $page, string $expression): mixed
{
    $value = null;

    for ($i = 0; $i < 50; $i++) {
        $value = $page->script($expression);

        if ($value) {
            return $value;
        }

        usleep(200_000);
    }

    return $value;
}

it('names a pinned area on Start like its tile, and its unpin toggle carries a name', function () {
    // The chip read „wallet": `pinRows` knows only the key of an area, and the chip printed
    // it. The pin glyph next to it is the chip's unpin toggle, not a second chip.
    $page = seite('/start');

    waitForScript($page, "() => !!Alpine.store('pinSet')");
    $page->script("() => Alpine.store('pinSet').toggle('area:kurse')");

    $chip = waitForScript($page, "() => document.querySelector('[data-start-angeheftet] [data-pin-chip=\"area:kurse\"]')?.textContent.trim()");
    $toggle = $page->script("() => document.querySelector('[data-start-angeheftet] [data-pin-toggle][data-pin-key=\"area:kurse\"]')?.getAttribute('aria-label')");

    expect($chip)->toBe('Kurse')
        ->and($toggle)->toBe('Anheftung von Bereich aufheben');
});

it('shows neither „still open" nor a pay button when the current fee year is paid', function () {
    // The headline and the button read `membership_status`, the fee box read
    // `current_year.paid` — „Your fee for this year is still open." and „Pay the fee" stood
    // above „Fee year 2026 · paid". The payload is fed to the island's own `_applyMe`, so
    // the decision under test is the bundled one, not a copy.
    $page = seiteAlsNostrNutzer('/ich/verein');

    waitForScript($page, "() => !!document.querySelector('[x-data=\"nostrVereinMitgliedschaft\"]')?._x_dataStack");
    $page->script(<<<'JS'
        () => {
            const card = Alpine.$data(document.querySelector('[x-data="nostrVereinMitgliedschaft"]'));
            card.angemeldet = true;
            card.laden = false;
            card._applyMe({
                ohneAkte: false,
                membershipStatus: 'awaiting_payment',
                me: { statutesAccepted: true, paid: true, year: 2026, fee: 21000, currency: 'SATS', receiptUrl: 'https://btcpay.example/i/x/receipt', status: 'Active' },
            });
        }
    JS);
    usleep(300_000);

    $visible = $page->script(<<<'JS'
        () => {
            const shown = (selector) => {
                const el = document.querySelector(selector);
                return !!el && el.offsetParent !== null;
            };
            return {
                paid: shown('[data-verein-bezahlt]'),
                open: shown('[data-verein-satz="zahlung-offen"]'),
                pay: shown('[data-verein-beitritt]'),
                awaiting: shown('[data-verein-satz="freischaltung-offen"]'),
            };
        }
    JS);

    expect($visible)->toBe(['paid' => true, 'open' => false, 'pay' => false, 'awaiting' => true]);
});

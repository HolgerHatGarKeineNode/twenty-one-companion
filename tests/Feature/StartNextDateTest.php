<?php

/**
 * „Dein nächster Termin" on Start (device sighting v1.13.0).
 *
 * The card showed an empty strip under its row: the RSVP row below the date hides itself
 * with `x-show` while the date is not open for an answer, but its hairline and padding sat on
 * a wrapper AROUND it, which stayed. Whether the row is shown is decided in the island; what
 * the server decides — and what is checked here — is that the footer styling travels with
 * the element that hides.
 */
it('puts the footer of the next-date card on the RSVP row that hides itself', function () {
    completeOnboarding();
    withoutPortalToken();

    $html = (string) $this->get('/start')->assertOk()->getContent();

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();
    $xpath = new DOMXPath($document);

    $card = $xpath->query('//section[@data-start-naechster-termin]//div[contains(concat(" ", normalize-space(@class), " "), " surface-card ")]')->item(0);
    expect($card)->not->toBeNull('the next-date card is gone — this test measures nothing');

    $rows = [];
    foreach ($card->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $rows[] = $child;
        }
    }

    // Two rows: the date link, and the RSVP row. The second one IS the element with the
    // `x-show` — not a padded wrapper that contains it.
    expect($rows)->toHaveCount(2)
        ->and($rows[1]->getAttribute('x-show'))->toBe('zeile.offen || zeile.myStatus')
        ->and($rows[1]->getAttribute('class'))->toContain('border-t')->toContain('pt-2')->toContain('pb-3');
});

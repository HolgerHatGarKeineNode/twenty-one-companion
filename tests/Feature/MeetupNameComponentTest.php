<?php

use Illuminate\Support\Facades\Blade;

function renderMeetupName(string $name): string
{
    return Blade::render('<x-meetup-name :name="$name"/>', ['name' => $name]);
}

it('mutes the brand prefix and lets the distinguishing part lead', function () {
    $html = renderMeetupName('Einundzwanzig Linz');

    // Präfix gedämpft (text-zinc-400), Rest hervorgehoben (font-semibold).
    expect($html)->toContain('text-zinc-400')
        ->and($html)->toContain('Einundzwanzig')
        ->and($html)->toMatch('/font-semibold[^>]*>\s*Linz/');
});

it('handles a multi-word brand prefix like TWENTY ONE', function () {
    $html = renderMeetupName('TWENTY ONE Vienna');

    expect($html)->toContain('text-zinc-400')
        ->and($html)->toContain('TWENTY ONE')
        ->and($html)->toMatch('/font-semibold[^>]*>\s*Vienna/');
});

it('leaves names without a brand prefix untouched', function () {
    $html = renderMeetupName('Bitcoin Austria');

    expect($html)->not->toContain('text-zinc-400')
        ->and($html)->toContain('Bitcoin Austria');
});

it('does not split when the name is only the brand word', function () {
    $html = renderMeetupName('Einundzwanzig');

    expect($html)->not->toContain('text-zinc-400')
        ->and($html)->toContain('Einundzwanzig');
});

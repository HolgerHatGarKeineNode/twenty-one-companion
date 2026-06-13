<?php

use Illuminate\Support\Facades\Blade;

it('renders the skeleton card with the requested number of placeholders', function () {
    $html = Blade::render('<x-skeleton-card :count="2" />');

    expect(substr_count($html, 'skeleton'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('rounded-card')
        ->and($html)->toContain('aria-hidden="true"');
});

it('renders the detail skeleton variant', function () {
    $html = Blade::render('<x-skeleton-card variant="detail" />');

    expect($html)->toContain('skeleton')
        ->and($html)->toContain('rounded-card');
});

it('renders the list link card with press feedback and haptics', function () {
    $html = Blade::render('<x-list-link-card href="/x">Inhalt</x-list-link-card>');

    expect($html)->toContain('pressable')
        ->and($html)->toContain('surface-card')
        ->and($html)->toContain("\$haptic('light')")
        ->and($html)->toContain('Inhalt');
});

it('renders the place card with the new card tokens', function () {
    $html = Blade::render('<x-place-card name="Wien" subtitle="AT" />');

    expect($html)->toContain('surface-card')
        ->and($html)->toContain('Wien');
});

it('renders the bottom sheet with a grabber handle', function () {
    $html = Blade::render('<x-sheet name="demo" heading="Titel">Body</x-sheet>');

    expect($html)->toContain('rounded-full') // Greifer
        ->and($html)->toContain('Titel')
        ->and($html)->toContain('Body');
});

it('renders the empty state with the icon tile and a call to action slot', function () {
    $html = Blade::render(
        '<x-empty-state icon="map-pin" heading="Leer"><button>Anlegen</button></x-empty-state>'
    );

    expect($html)->toContain('empty-state')
        ->and($html)->toContain('rounded-tile')
        ->and($html)->toContain('Leer')
        ->and($html)->toContain('Anlegen');
});

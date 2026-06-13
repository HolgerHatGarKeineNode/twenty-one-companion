<?php

use Illuminate\Support\Facades\Blade;

it('renders the gated content when a portal token is present', function () {
    withPortalToken();

    $html = Blade::render('<x-requires-portal>GEHEIMES FORMULAR</x-requires-portal>');

    expect($html)->toContain('GEHEIMES FORMULAR')
        ->and($html)->not->toContain('Konto verbinden');
});

it('renders the connect call-to-action instead of the content without a token', function () {
    withoutPortalToken();

    $html = Blade::render('<x-requires-portal>GEHEIMES FORMULAR</x-requires-portal>');

    expect($html)->not->toContain('GEHEIMES FORMULAR')
        ->and($html)->toContain('Konto verbinden')
        ->and($html)->toContain(route('profile'));
});

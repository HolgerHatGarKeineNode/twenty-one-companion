<?php

use App\Http\Integrations\Portal\Requests\GetMobileMeetupsRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('re-fetches the page data on the global portal-refresh event', function () {
    withoutPortalToken();
    MockClient::global([GetMobileMeetupsRequest::class => MockResponse::make([])]);

    // load() (wire:init) rendert einmal mit Daten (1 Abruf). Ohne Refresh käme
    // der zweite Render aus dem Fresh-Cache; der globale portal-refresh-Event
    // (vom Header-Refresh-Button) verwirft ihn und erzwingt einen frischen Abruf
    // — also genau zwei Portal-Requests. Bestätigt zudem das Stopp-Signal an das
    // Layout-Alpine (portal-refreshed).
    Livewire::test('pages::meetups.index')
        ->call('load')
        ->assertOk()
        ->dispatch('portal-refresh')
        ->assertOk()
        ->assertDispatched('portal-refreshed');

    MockClient::global()->assertSentCount(2);
});

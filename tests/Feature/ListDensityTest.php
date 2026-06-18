<?php

use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Services\AppPreferences;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('defaults to comfortable density', function () {
    expect(app(AppPreferences::class)->density())->toBe('comfortable');
});

it('rejects an invalid density value', function () {
    app(AppPreferences::class)->setDensity('compact');
    app(AppPreferences::class)->setDensity('huge');

    expect(app(AppPreferences::class)->density())->toBe('compact');
});

it('saves the chosen density from the profile page', function () {
    withoutPortalToken();

    Livewire::test('pages::profile.index')
        ->set('density', 'compact')
        ->assertHasNoErrors();

    expect(app(AppPreferences::class)->density())->toBe('compact');
});

it('applies the compact density class on the layout when chosen', function () {
    withoutPortalToken();
    app(AppPreferences::class)->setDensity('compact');
    MockClient::global([GetMapMeetupsRequest::class => MockResponse::make([])]);

    $this->get(route('meetups'))
        ->assertOk()
        ->assertSee('density-compact');
});

it('keeps the comfortable layout free of the compact class', function () {
    withoutPortalToken();
    MockClient::global([GetMapMeetupsRequest::class => MockResponse::make([])]);

    $this->get(route('meetups'))
        ->assertOk()
        ->assertDontSee('density-compact');
});

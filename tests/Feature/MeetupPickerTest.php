<?php

use App\Http\Integrations\Portal\Requests\AddMeetupToMineRequest;
use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

afterEach(fn () => MockClient::destroyGlobal());

it('filters existing meetups in-memory from two characters', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture(), viennaMeetupFixture()]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => []]),
    ]);

    Livewire::test('meetup-picker')
        ->call('open')
        ->set('search', 'wien')
        ->assertSee('Einundzwanzig Wien')
        ->assertDontSee('Einundzwanzig Aschaffenburg');
});

it('adds a selected existing meetup to mine by slug', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([viennaMeetupFixture()]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => []]),
        AddMeetupToMineRequest::class => MockResponse::make(['data' => myMeetupFixture(['slug' => 'wien'])], 201),
    ]);

    Livewire::test('meetup-picker')
        ->call('open')
        ->set('search', 'wien')
        ->assertSee('Hinzufügen')
        ->call('addToMine', 'wien')
        ->assertDispatched('meetup-saved')
        ->assertSet('search', '');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof AddMeetupToMineRequest
        && $request->resolveEndpoint() === '/my-meetups/wien');
});

it('marks meetups that are already mine instead of offering to add them', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([viennaMeetupFixture()]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [
            myMeetupFixture(['slug' => 'wien', 'name' => 'Einundzwanzig Wien']),
        ]]),
    ]);

    Livewire::test('meetup-picker')
        ->call('open')
        ->set('search', 'wien')
        ->assertSee('Dabei')
        ->assertDontSee('Hinzufügen');
});

it('does not add to mine without a portal token', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([viennaMeetupFixture()]),
    ]);

    Livewire::test('meetup-picker')
        ->call('open')
        ->call('addToMine', 'wien')
        ->assertNotDispatched('meetup-saved');

    MockClient::global()->assertNotSent(AddMeetupToMineRequest::class);
});

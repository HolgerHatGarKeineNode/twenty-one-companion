<?php

use App\Http\Integrations\Portal\Requests\AddMeetupLeaderRequest;
use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupLeadersRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use App\Http\Integrations\Portal\Requests\RemoveMeetupLeaderRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

afterEach(fn () => MockClient::destroyGlobal());

/** Gültiger npub für die clientseitige Kurzprüfung (npub1 + 58 Zeichen). */
function sampleNpub(): string
{
    return 'npub1'.str_repeat('q', 58);
}

it('lists the current leaders of a meetup', function () {
    withPortalToken();
    MockClient::global([
        GetMeetupLeadersRequest::class => MockResponse::make(['data' => [
            meetupLeaderFixture(['id' => 7, 'name' => 'Satoshi', 'is_creator' => true]),
            meetupLeaderFixture(['id' => 8, 'name' => 'Hal', 'is_creator' => false]),
        ]]),
    ]);

    Livewire::test('meetup-leaders')
        ->call('open', 21, 'Einundzwanzig Aschaffenburg')
        ->assertSee('Satoshi')
        ->assertSee('Hal')
        ->assertSee(__('Ersteller'));
});

it('appoints a leader by npub and refreshes the list', function () {
    withPortalToken();
    MockClient::global([
        GetMeetupLeadersRequest::class => MockResponse::make(['data' => [meetupLeaderFixture()]]),
        AddMeetupLeaderRequest::class => MockResponse::make(['data' => [meetupLeaderFixture()]], 201),
    ]);

    Livewire::test('meetup-leaders')
        ->call('open', 21, 'Einundzwanzig Aschaffenburg')
        ->set('npub', sampleNpub())
        ->call('addLeader')
        ->assertHasNoErrors()
        ->assertSet('npub', '');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof AddMeetupLeaderRequest
        && $request->body()->all()['npub'] === sampleNpub());
});

it('rejects an invalid npub client-side without sending', function () {
    withPortalToken();
    MockClient::global([
        GetMeetupLeadersRequest::class => MockResponse::make(['data' => [meetupLeaderFixture()]]),
    ]);

    Livewire::test('meetup-leaders')
        ->call('open', 21, 'Einundzwanzig Aschaffenburg')
        ->set('npub', 'hex-or-garbage')
        ->call('addLeader')
        ->assertHasErrors('npub');

    MockClient::global()->assertNotSent(AddMeetupLeaderRequest::class);
});

it('maps a server 422 back to the npub field', function () {
    withPortalToken();
    MockClient::global([
        GetMeetupLeadersRequest::class => MockResponse::make(['data' => [meetupLeaderFixture()]]),
        AddMeetupLeaderRequest::class => MockResponse::make([
            'message' => 'invalid',
            'errors' => ['npub' => ['Das ist kein gültiger npub.']],
        ], 422),
    ]);

    Livewire::test('meetup-leaders')
        ->call('open', 21, 'Einundzwanzig Aschaffenburg')
        ->set('npub', sampleNpub())
        ->call('addLeader')
        ->assertHasErrors('npub');
});

it('demotes a leader', function () {
    withPortalToken();
    MockClient::global([
        GetMeetupLeadersRequest::class => MockResponse::make(['data' => [
            meetupLeaderFixture(['id' => 7, 'is_creator' => true]),
            meetupLeaderFixture(['id' => 8, 'name' => 'Hal', 'is_creator' => false]),
        ]]),
        RemoveMeetupLeaderRequest::class => MockResponse::make(['data' => [meetupLeaderFixture()]]),
    ]);

    Livewire::test('meetup-leaders')
        ->call('open', 21, 'Einundzwanzig Aschaffenburg')
        ->call('removeLeader', 8)
        ->assertHasNoErrors();

    MockClient::global()->assertSent(RemoveMeetupLeaderRequest::class);
});

it('opens the leader manager from the meetup editor when the user is a leader', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21, 'is_leader' => true])]]),
    ]);

    Livewire::test('meetup-editor')
        ->call('open', 21)
        ->assertSet('canManageLeaders', true)
        ->assertSee(__('Leader verwalten'));
});

it('hides leader management for a non-leader member in the editor', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21, 'is_leader' => false])]]),
    ]);

    Livewire::test('meetup-editor')
        ->call('open', 21)
        ->assertSet('canManageLeaders', false);
});

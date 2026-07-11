<?php

use App\Http\Integrations\Portal\Requests\AddMeetupToMineRequest;
use App\Http\Integrations\Portal\Requests\CreateMeetupRequest;
use App\Http\Integrations\Portal\Requests\GetCitiesRequest;
use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use App\Http\Integrations\Portal\Requests\UpdateMeetupRequest;
use App\Http\Integrations\Portal\Requests\UploadMeetupLogoRequest;
use Livewire\Livewire;
use Native\Mobile\Facades\Network;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

afterEach(fn () => MockClient::destroyGlobal());

it('creates a meetup with the selected city and sends the payload', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
        CreateMeetupRequest::class => MockResponse::make(['data' => myMeetupFixture(['id' => 99])], 201),
    ]);

    Livewire::test('meetup-editor')
        ->set('form.name', 'Einundzwanzig Musterstadt')
        ->call('selectCity', 7, 'Musterstadt')
        ->set('form.intro', 'Hallo **Welt**')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('meetup-saved')
        ->assertSet('editingId', null);

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof CreateMeetupRequest
        && $request->body()->all()['name'] === 'Einundzwanzig Musterstadt'
        && $request->body()->all()['city_id'] === 7
        && $request->body()->all()['intro'] === 'Hallo **Welt**');
});

it('requires a name and a city before sending', function () {
    withPortalToken();

    Livewire::test('meetup-editor')
        ->call('save')
        ->assertHasErrors(['form.name' => 'required', 'form.city_id' => 'required'])
        ->assertNotDispatched('meetup-saved');
});

it('warns about a similar (non-exact) duplicate and lets the user override', function () {
    withPortalToken();
    MockClient::global([
        // Ähnlicher Name, aber ANDERE Stadt (Graz statt Wien) → kein exakter
        // Treffer, nur die überstimmbare Warnung (Name enthält „Wien“).
        GetMapMeetupsRequest::class => MockResponse::make([wienMapFixture()]),
        CreateMeetupRequest::class => MockResponse::make(['data' => myMeetupFixture()], 201),
    ]);

    $component = Livewire::test('meetup-editor')
        ->set('form.name', 'Einundzwanzig Wien')
        ->call('selectCity', 9, 'Graz')
        ->call('save')
        ->assertNotDispatched('meetup-saved')
        ->assertSee('Gibt es das schon?');

    MockClient::global()->assertNotSent(CreateMeetupRequest::class);

    $component->set('ignoreDuplicates', true)
        ->call('save')
        ->assertDispatched('meetup-saved');

    MockClient::global()->assertSent(CreateMeetupRequest::class);
});

it('hard-blocks an exact name+city duplicate and refuses to create even when overridden', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([wienMapFixture()]),
        CreateMeetupRequest::class => MockResponse::make(['data' => myMeetupFixture()], 201),
    ]);

    // Exakt gleicher Name UND Stadt wie das bestehende Wien-Meetup.
    Livewire::test('meetup-editor')
        ->set('form.name', 'Einundzwanzig Wien')
        ->call('selectCity', 5, 'Wien')
        ->call('save')
        ->assertSee('Dieses Meetup gibt es schon')
        ->assertNotDispatched('meetup-saved')
        // Harte Sperre: auch mit gesetztem ignoreDuplicates wird nicht angelegt.
        ->set('ignoreDuplicates', true)
        ->call('save')
        ->assertNotDispatched('meetup-saved');

    MockClient::global()->assertNotSent(CreateMeetupRequest::class);
});

it('adds the existing meetup to mine instead of creating a duplicate', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([wienMapFixture()]),
        AddMeetupToMineRequest::class => MockResponse::make(['data' => myMeetupFixture(['slug' => 'wien'])], 201),
    ]);

    Livewire::test('meetup-editor')
        ->set('form.name', 'Einundzwanzig Wien')
        ->call('selectCity', 5, 'Wien')
        ->assertSee('Zu meinen Meetups hinzufügen')
        ->call('addExistingToMine')
        ->assertDispatched('meetup-saved')
        ->assertSet('editingId', null);

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof AddMeetupToMineRequest
        && $request->resolveEndpoint() === '/my-meetups/wien');
});

it('maps a 422 response back onto the form fields', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
        CreateMeetupRequest::class => MockResponse::make([
            'message' => 'The given data was invalid.',
            'errors' => ['name' => ['Dieser Name ist bereits vergeben.']],
        ], 422),
    ]);

    Livewire::test('meetup-editor')
        ->set('form.name', 'Einundzwanzig Musterstadt')
        ->call('selectCity', 7, 'Musterstadt')
        ->call('save')
        ->assertHasErrors('form.name')
        ->assertSee('Dieser Name ist bereits vergeben.')
        ->assertNotDispatched('meetup-saved');
});

it('loads an own meetup for editing and sends an update', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        UpdateMeetupRequest::class => MockResponse::make(['data' => myMeetupFixture(['id' => 21])], 200),
    ]);

    Livewire::test('meetup-editor')
        ->call('open', 21)
        ->assertSet('editingId', 21)
        ->assertSet('form.name', 'Einundzwanzig Aschaffenburg')
        ->assertSet('form.cityName', 'Aschaffenburg')
        ->set('form.intro', 'Aktualisierte Beschreibung')
        ->call('save')
        ->assertDispatched('meetup-saved');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof UpdateMeetupRequest
        && $request->resolveEndpoint() === '/meetup/21'
        && $request->body()->all()['intro'] === 'Aktualisierte Beschreibung');
});

it('loads and sends the RSVP settings of an own meetup', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [
            myMeetupFixture(['id' => 21, 'rsvp_enabled' => false, 'attendees_public' => false]),
        ]]),
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        UpdateMeetupRequest::class => MockResponse::make(['data' => myMeetupFixture(['id' => 21])], 200),
    ]);

    Livewire::test('meetup-editor')
        ->call('open', 21)
        ->assertSet('form.rsvp_enabled', false)
        ->assertSet('form.attendees_public', false)
        ->set('form.rsvp_enabled', true)
        ->set('form.attendees_public', true)
        ->call('save')
        ->assertDispatched('meetup-saved');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof UpdateMeetupRequest
        && $request->body()->all()['rsvp_enabled'] === true
        && $request->body()->all()['attendees_public'] === true);
});

it('keeps the editor open and reports a 403 when editing a foreign meetup', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        UpdateMeetupRequest::class => MockResponse::make(['message' => 'This action is unauthorized.'], 403),
    ]);

    Livewire::test('meetup-editor')
        ->call('open', 21)
        ->set('form.name', 'Geändert')
        ->call('save')
        ->assertNotDispatched('meetup-saved')
        ->assertSet('editingId', 21);
});

it('searches cities for the picker from two characters', function () {
    withPortalToken();
    MockClient::global([
        GetCitiesRequest::class => MockResponse::make([cityFixture()]),
    ]);

    Livewire::test('meetup-editor')
        ->set('cityQuery', 'Reg')
        ->assertSee('Regensburg')
        ->assertSee('Germany');
});

it('renders a markdown preview of the description', function () {
    withPortalToken();

    Livewire::test('meetup-editor')
        ->set('form.intro', 'Hallo **Welt**')
        ->call('togglePreview')
        ->assertSet('showPreview', true)
        ->assertSeeHtml('<strong>Welt</strong>');
});

it('is not reachable without a portal token via the writer gate', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    Livewire::test('meetup-editor')
        ->set('form.name', 'Einundzwanzig Musterstadt')
        ->call('selectCity', 7, 'Musterstadt')
        ->call('save')
        ->assertNotDispatched('meetup-saved');

    MockClient::global()->assertNotSent(CreateMeetupRequest::class);
});

it('warns when a logo is picked on an expensive network', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);
    Network::shouldReceive('status')->andReturn((object) ['connected' => true, 'isExpensive' => true]);

    $path = fakeImagePath('logo.jpg');

    Livewire::test('meetup-editor')
        ->call('handlePhotoTaken', $path, 'image/jpeg', 'meetup-logo')
        ->assertSet('imagePath', $path)
        ->assertDispatched('toast-show');
});

it('does not warn when a logo is picked on wifi', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);
    Network::shouldReceive('status')->andReturn((object) ['connected' => true, 'isExpensive' => false]);

    $path = fakeImagePath('logo.jpg');

    Livewire::test('meetup-editor')
        ->call('handlePhotoTaken', $path, 'image/jpeg', 'meetup-logo')
        ->assertSet('imagePath', $path)
        ->assertNotDispatched('toast-show');
});

it('uploads the selected logo to the new meetup after creating', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
        CreateMeetupRequest::class => MockResponse::make(['data' => myMeetupFixture(['id' => 99])], 201),
        UploadMeetupLogoRequest::class => MockResponse::make(['data' => myMeetupFixture(['id' => 99])], 200),
    ]);

    Livewire::test('meetup-editor')
        ->set('form.name', 'Einundzwanzig Musterstadt')
        ->call('selectCity', 7, 'Musterstadt')
        ->set('imagePath', fakeImagePath('logo.jpg'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('meetup-saved');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof UploadMeetupLogoRequest
        && $request->resolveEndpoint() === '/meetup/99/logo');
});

it('uploads the selected logo to the existing meetup when editing', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        UpdateMeetupRequest::class => MockResponse::make(['data' => myMeetupFixture(['id' => 21])], 200),
        UploadMeetupLogoRequest::class => MockResponse::make(['data' => myMeetupFixture(['id' => 21])], 200),
    ]);

    Livewire::test('meetup-editor')
        ->call('open', 21)
        ->set('imagePath', fakeImagePath('logo.jpg'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('meetup-saved');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof UploadMeetupLogoRequest
        && $request->resolveEndpoint() === '/meetup/21/logo');
});

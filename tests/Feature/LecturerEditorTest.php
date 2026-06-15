<?php

use App\Http\Integrations\Portal\Requests\CreateLecturerRequest;
use App\Http\Integrations\Portal\Requests\GetMyLecturersRequest;
use App\Http\Integrations\Portal\Requests\UpdateLecturerRequest;
use App\Http\Integrations\Portal\Requests\UploadLecturerAvatarRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

afterEach(fn () => MockClient::destroyGlobal());

it('creates a lecturer and sends the payload', function () {
    withPortalToken();
    MockClient::global([
        CreateLecturerRequest::class => MockResponse::make(['data' => myLecturerFixture(['id' => 99])], 201),
    ]);

    Livewire::test('lecturer-editor')
        ->call('open')
        ->set('form.name', 'Toni Stack')
        ->set('form.subtitle', 'Bitcoin-Educator')
        ->set('form.description', 'Seit 2017 dabei.')
        ->set('form.website', 'https://tonistack.example')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('lecturer-saved', id: 99, name: 'Toni Stack')
        ->assertDispatched('teaching-changed')
        ->assertSet('editingId', null);

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof CreateLecturerRequest
        && $request->body()->all()['name'] === 'Toni Stack'
        && $request->body()->all()['subtitle'] === 'Bitcoin-Educator'
        && $request->body()->all()['active'] === true);
});

it('requires a name before sending', function () {
    withPortalToken();

    Livewire::test('lecturer-editor')
        ->call('open')
        ->call('save')
        ->assertHasErrors(['form.name' => 'required'])
        ->assertNotDispatched('teaching-changed');
});

it('rejects an invalid website url', function () {
    withPortalToken();

    Livewire::test('lecturer-editor')
        ->call('open')
        ->set('form.name', 'Toni Stack')
        ->set('form.website', 'not-a-url')
        ->call('save')
        ->assertHasErrors(['form.website' => 'url'])
        ->assertNotDispatched('teaching-changed');
});

it('loads an own lecturer for editing and sends an update', function () {
    withPortalToken();
    MockClient::global([
        GetMyLecturersRequest::class => MockResponse::make(['data' => [myLecturerFixture(['id' => 3])]]),
        UpdateLecturerRequest::class => MockResponse::make(['data' => myLecturerFixture(['id' => 3])], 200),
    ]);

    Livewire::test('lecturer-editor')
        ->call('open', 3)
        ->assertSet('editingId', 3)
        ->assertSet('form.name', 'Toni Stack')
        ->assertSet('form.subtitle', 'Bitcoin-Educator')
        ->set('form.subtitle', 'Bitcoin-Lehrer')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('teaching-changed')
        ->assertNotDispatched('lecturer-saved');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof UpdateLecturerRequest
        && $request->resolveEndpoint() === '/lecturers/3'
        && $request->body()->all()['subtitle'] === 'Bitcoin-Lehrer');
});

it('keeps the editor open and reports a 403 when editing a foreign lecturer', function () {
    withPortalToken();
    MockClient::global([
        GetMyLecturersRequest::class => MockResponse::make(['data' => [myLecturerFixture(['id' => 3])]]),
        UpdateLecturerRequest::class => MockResponse::make(['message' => 'This action is unauthorized.'], 403),
    ]);

    Livewire::test('lecturer-editor')
        ->call('open', 3)
        ->set('form.name', 'Geändert')
        ->call('save')
        ->assertNotDispatched('teaching-changed')
        ->assertSet('editingId', 3);
});

it('maps a 422 response back onto the form fields', function () {
    withPortalToken();
    MockClient::global([
        CreateLecturerRequest::class => MockResponse::make([
            'message' => 'The given data was invalid.',
            'errors' => ['name' => ['Der Name ist ungültig.']],
        ], 422),
    ]);

    Livewire::test('lecturer-editor')
        ->call('open')
        ->set('form.name', 'x')
        ->call('save')
        ->assertHasErrors('form.name')
        ->assertSee('Der Name ist ungültig.')
        ->assertNotDispatched('teaching-changed');
});

it('prefills the name when opened from an inline create flow', function () {
    withPortalToken();

    Livewire::test('lecturer-editor')
        ->call('open', null, 'Neuer Referent')
        ->assertSet('form.name', 'Neuer Referent')
        ->assertSet('editingId', null);
});

it('renders a markdown preview of the bio', function () {
    withPortalToken();

    Livewire::test('lecturer-editor')
        ->call('open')
        ->set('form.description', 'Hallo **Welt**')
        ->call('togglePreview')
        ->assertSet('showPreview', true)
        ->assertSeeHtml('<strong>Welt</strong>');
});

it('does not send a write without a portal token', function () {
    withoutPortalToken();

    Livewire::test('lecturer-editor')
        ->call('open')
        ->set('form.name', 'Toni Stack')
        ->call('save')
        ->assertNotDispatched('teaching-changed');
});

it('uploads the selected avatar to the new lecturer after creating', function () {
    withPortalToken();
    MockClient::global([
        CreateLecturerRequest::class => MockResponse::make(['data' => myLecturerFixture(['id' => 99])], 201),
        UploadLecturerAvatarRequest::class => MockResponse::make(['data' => myLecturerFixture(['id' => 99])], 200),
    ]);

    Livewire::test('lecturer-editor')
        ->call('open')
        ->set('form.name', 'Toni Stack')
        ->set('imagePath', fakeImagePath())
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('teaching-changed');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof UploadLecturerAvatarRequest
        && $request->resolveEndpoint() === '/lecturers/99/avatar');
});

it('uploads the selected avatar to the existing lecturer when editing', function () {
    withPortalToken();
    MockClient::global([
        GetMyLecturersRequest::class => MockResponse::make(['data' => [myLecturerFixture(['id' => 3])]]),
        UpdateLecturerRequest::class => MockResponse::make(['data' => myLecturerFixture(['id' => 3])], 200),
        UploadLecturerAvatarRequest::class => MockResponse::make(['data' => myLecturerFixture(['id' => 3])], 200),
    ]);

    Livewire::test('lecturer-editor')
        ->call('open', 3)
        ->set('imagePath', fakeImagePath())
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('teaching-changed');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof UploadLecturerAvatarRequest
        && $request->resolveEndpoint() === '/lecturers/3/avatar');
});

it('does not upload an avatar when none was selected', function () {
    withPortalToken();
    MockClient::global([
        CreateLecturerRequest::class => MockResponse::make(['data' => myLecturerFixture(['id' => 99])], 201),
    ]);

    Livewire::test('lecturer-editor')
        ->call('open')
        ->set('form.name', 'Toni Stack')
        ->call('save')
        ->assertHasNoErrors();

    MockClient::global()->assertNotSent(UploadLecturerAvatarRequest::class);
});

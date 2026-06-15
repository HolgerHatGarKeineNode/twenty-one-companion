<?php

use App\Http\Integrations\Portal\Requests\CreateCourseRequest;
use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetLecturersRequest;
use App\Http\Integrations\Portal\Requests\UpdateCourseRequest;
use App\Http\Integrations\Portal\Requests\UploadCourseLogoRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

afterEach(fn () => MockClient::destroyGlobal());

it('creates a course with the selected lecturer and sends the payload', function () {
    withPortalToken();
    MockClient::global([
        CreateCourseRequest::class => MockResponse::make(['data' => ['id' => 99, 'name' => 'Bitcoin 101']], 201),
    ]);

    Livewire::test('course-editor')
        ->call('open')
        ->set('form.name', 'Bitcoin 101')
        ->call('selectLecturer', 3, 'Toni Stack')
        ->set('form.description', 'Grundlagen.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('teaching-changed')
        ->assertSet('editingId', null);

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof CreateCourseRequest
        && $request->body()->all()['name'] === 'Bitcoin 101'
        && $request->body()->all()['lecturer_id'] === 3
        && $request->body()->all()['description'] === 'Grundlagen.');
});

it('uploads the selected logo to the new course after creating', function () {
    withPortalToken();
    MockClient::global([
        CreateCourseRequest::class => MockResponse::make(['data' => ['id' => 99, 'name' => 'Bitcoin 101']], 201),
        UploadCourseLogoRequest::class => MockResponse::make(['data' => ['id' => 99, 'name' => 'Bitcoin 101']], 200),
    ]);

    Livewire::test('course-editor')
        ->call('open')
        ->set('form.name', 'Bitcoin 101')
        ->call('selectLecturer', 3, 'Toni Stack')
        ->set('imagePath', fakeImagePath('logo.jpg'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('teaching-changed');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof UploadCourseLogoRequest
        && $request->resolveEndpoint() === '/courses/99/logo');
});

it('uploads the selected logo to the existing course when editing', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5])]),
        UpdateCourseRequest::class => MockResponse::make(['data' => ['id' => 5]], 200),
        UploadCourseLogoRequest::class => MockResponse::make(['data' => ['id' => 5]], 200),
    ]);

    Livewire::test('course-editor')
        ->call('open', 5)
        ->set('imagePath', fakeImagePath('logo.jpg'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('teaching-changed');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof UploadCourseLogoRequest
        && $request->resolveEndpoint() === '/courses/5/logo');
});

it('requires a name and lecturer before sending', function () {
    withPortalToken();

    Livewire::test('course-editor')
        ->call('open')
        ->call('save')
        ->assertHasErrors([
            'form.name' => 'required',
            'form.lecturer_id' => 'required',
        ])
        ->assertNotDispatched('teaching-changed');
});

it('searches lecturers for the picker from two characters', function () {
    withPortalToken();
    MockClient::global([
        GetLecturersRequest::class => MockResponse::make([detailedLecturerFixture(['name' => 'Toni Stack'])]),
    ]);

    Livewire::test('course-editor')
        ->call('open')
        ->set('lecturerQuery', 'Ton')
        ->assertSee('Toni Stack');
});

it('adopts a lecturer created inline via the lecturer-saved event', function () {
    withPortalToken();

    Livewire::test('course-editor')
        ->call('open')
        ->dispatch('lecturer-saved', id: 3, name: 'Toni Stack')
        ->assertSet('form.lecturer_id', 3)
        ->assertSet('form.lecturerName', 'Toni Stack');
});

it('does not overwrite an already chosen lecturer when a lecturer is saved', function () {
    withPortalToken();

    Livewire::test('course-editor')
        ->call('open')
        ->call('selectLecturer', 8, 'Andere Person')
        ->dispatch('lecturer-saved', id: 3, name: 'Toni Stack')
        ->assertSet('form.lecturer_id', 8)
        ->assertSet('form.lecturerName', 'Andere Person');
});

it('loads an own course for editing and sends an update', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5, 'name' => 'Bitcoin, Blockchain und Geld'])]),
        UpdateCourseRequest::class => MockResponse::make(['id' => 5], 200),
    ]);

    Livewire::test('course-editor')
        ->call('open', 5)
        ->assertSet('editingId', 5)
        ->assertSet('form.name', 'Bitcoin, Blockchain und Geld')
        ->assertSet('form.lecturer_id', 3)
        ->assertSet('form.lecturerName', 'Toni Stack')
        ->set('form.name', 'Bitcoin & Geld')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('teaching-changed');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof UpdateCourseRequest
        && $request->resolveEndpoint() === '/courses/5'
        && $request->body()->all()['name'] === 'Bitcoin & Geld');
});

it('keeps the editor open and reports a 403 when editing a foreign course', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5])]),
        UpdateCourseRequest::class => MockResponse::make(['message' => 'This action is unauthorized.'], 403),
    ]);

    Livewire::test('course-editor')
        ->call('open', 5)
        ->set('form.name', 'Geändert')
        ->call('save')
        ->assertNotDispatched('teaching-changed')
        ->assertSet('editingId', 5);
});

it('maps a 422 response back onto the form fields', function () {
    withPortalToken();
    MockClient::global([
        CreateCourseRequest::class => MockResponse::make([
            'message' => 'The given data was invalid.',
            'errors' => ['name' => ['Der Kursname ist ungültig.']],
        ], 422),
    ]);

    Livewire::test('course-editor')
        ->call('open')
        ->set('form.name', 'x')
        ->call('selectLecturer', 3, 'Toni Stack')
        ->call('save')
        ->assertHasErrors('form.name')
        ->assertSee('Der Kursname ist ungültig.')
        ->assertNotDispatched('teaching-changed');
});

it('does not send a write without a portal token', function () {
    withoutPortalToken();

    Livewire::test('course-editor')
        ->call('open')
        ->set('form.name', 'Bitcoin 101')
        ->call('selectLecturer', 3, 'Toni Stack')
        ->call('save')
        ->assertNotDispatched('teaching-changed');
});

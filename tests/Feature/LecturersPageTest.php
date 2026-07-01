<?php

use App\Http\Integrations\Portal\Requests\GetLecturerRequest;
use Livewire\Livewire;
use Native\Mobile\Facades\Browser;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('shows the lecturer profile with courses and links', function () {
    withoutPortalToken();
    MockClient::global([
        GetLecturerRequest::class => MockResponse::make(lecturerDetailFixture()),
    ]);

    Livewire::test('pages::lecturers.show', ['id' => 3])
        ->assertSee('Toni Stack')
        ->assertSee('Bitcoin-Educator')
        ->assertSee('Ich halte Kurse zu')
        ->assertSee('Bitcoin, Blockchain und Geld')
        ->assertSee(route('courses.show', 5))
        ->assertSee('Website')
        ->assertSee('Nostr');
});

it('shows a friendly fallback for unknown lecturers', function () {
    withoutPortalToken();
    MockClient::global([
        GetLecturerRequest::class => MockResponse::make(['message' => 'Not Found'], 404),
    ]);

    Livewire::test('pages::lecturers.show', ['id' => 999])
        ->assertSee('Referent nicht gefunden');
});

it('opens lecturer web links in the in-app browser but refuses other schemes', function () {
    withoutPortalToken();
    MockClient::global([
        GetLecturerRequest::class => MockResponse::make(lecturerDetailFixture()),
    ]);

    Browser::shouldReceive('inApp')->once()->with('https://tonistack.example');
    Browser::shouldReceive('open')->never();

    Livewire::test('pages::lecturers.show', ['id' => 3])
        ->call('openLink', 'https://tonistack.example')
        ->call('openLink', 'nostrsigner:xyz');
});

it('renders the lecturer page over http', function () {
    withoutPortalToken();
    MockClient::global([
        GetLecturerRequest::class => MockResponse::make(lecturerDetailFixture()),
    ]);

    $this->get(route('lecturers.show', 3))
        ->assertOk()
        ->assertSee('Toni Stack');
});

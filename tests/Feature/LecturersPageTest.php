<?php

use App\Http\Integrations\Portal\Requests\GetLecturerRequest;
use Livewire\Livewire;
use Native\Mobile\Facades\Browser;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The lecturer profile — AFTER the move (P5).
 *
 * The page itself has lived in the package since P4 (`/bereich/kurse/referenten/{id}`,
 * D9); P5 deleted this app's copy. There was no write surface here other than the edit
 * button of one's own profile, and since then that button stands in the host slot at the
 * foot of the page (`partials/portal/detail-aktionen`, measured in
 * `PortalCatalogBindingTest`).
 *
 * What is measured here is therefore what THIS host contributes: that its data and its
 * native affordances carry the package page — and that the old address leads there.
 */
afterEach(fn () => MockClient::destroyGlobal());

it('shows the lecturer profile through the package page with this app\'s data', function () {
    completeOnboarding();
    withoutPortalToken();
    MockClient::global([
        GetLecturerRequest::class => MockResponse::make(lecturerDetailFixture()),
    ]);

    Livewire::test('group::referent', ['id' => 3])
        ->assertSee('Toni Stack')
        ->assertSee('Bitcoin-Educator')
        ->assertSee('Bitcoin, Blockchain und Geld')
        ->assertSee(route('group.bereich.kurse.show', 5))
        ->assertSee('Website')
        ->assertSee('Nostr');
});

it('shows a friendly fallback for unknown lecturers', function () {
    withoutPortalToken();
    MockClient::global([
        GetLecturerRequest::class => MockResponse::make(['message' => 'Not Found'], 404),
    ]);

    Livewire::test('group::referent', ['id' => 999])
        ->assertSee('Referent nicht gefunden');
});

it('opens lecturer web links in the in-app browser but refuses other schemes', function () {
    // This host's native seam (`NativePortalAffordances`) carries the package page: a
    // Portal link stays in the in-app browser, a `nostrsigner:` is refused.
    withoutPortalToken();
    MockClient::global([
        GetLecturerRequest::class => MockResponse::make(lecturerDetailFixture()),
    ]);

    Browser::shouldReceive('inApp')->once()->with('https://tonistack.example');
    Browser::shouldReceive('open')->never();

    Livewire::test('group::referent', ['id' => 3])
        ->call('openLink', 'https://tonistack.example')
        ->call('openLink', 'nostrsigner:xyz');
});

it('renders the lecturer page over http', function () {
    completeOnboarding();
    withoutPortalToken();
    MockClient::global([
        GetLecturerRequest::class => MockResponse::make(lecturerDetailFixture()),
    ]);

    $this->get(route('group.bereich.referenten.show', 3))
        ->assertOk()
        ->assertSee('Toni Stack');
});

it('forwards the old lecturer address', function () {
    completeOnboarding();

    $this->get('/lecturers/3')->assertRedirect('/bereich/kurse/referenten/3');
});

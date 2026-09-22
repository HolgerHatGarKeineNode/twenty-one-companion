<?php

use App\Http\Integrations\Portal\Requests\GetCountriesRequest;
use App\Http\Integrations\Portal\Requests\GetMyCitiesRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('shows the connect CTA for guests', function () {
    withoutPortalToken();

    Livewire::test('pages::mine.places')
        ->assertSee(__('Mit Portal verbinden'))
        ->assertSee(__('Konto verbinden'))
        ->assertDontSee(__('Stadt bearbeiten'));
});

it('lists own cities with the resolved country and an edit affordance', function () {
    withPortalToken();
    MockClient::global([
        GetMyCitiesRequest::class => MockResponse::make(['data' => [myCityFixture(['id' => 80, 'country_id' => 1, 'name' => 'Regensburg'])]]),
        GetCountriesRequest::class => MockResponse::make([countryFixture(['id' => 1, 'name' => 'Germany'])]),
    ]);

    Livewire::test('pages::mine.places')
        ->assertSee('Regensburg')
        ->assertSee('Germany')
        ->assertSee(__('Stadt anlegen'))
        ->assertSee(__('Stadt bearbeiten'));
});

it('shows the empty-state create CTA when the user has no cities', function () {
    withPortalToken();
    MockClient::global([
        GetMyCitiesRequest::class => MockResponse::make(['data' => []]),
    ]);

    Livewire::test('pages::mine.places')
        ->assertSee(__('Noch keine eigenen Städte'))
        ->assertSee(__('Stadt anlegen'));
});

it('refreshes the lists when places change', function () {
    withPortalToken();
    MockClient::global([
        GetMyCitiesRequest::class => MockResponse::make(['data' => [myCityFixture(['name' => 'Regensburg'])]]),
        GetCountriesRequest::class => MockResponse::make([countryFixture(['id' => 1, 'name' => 'Germany'])]),
    ]);

    Livewire::test('pages::mine.places')
        ->assertSee('Regensburg')
        ->call('refreshLists')
        ->assertSee('Regensburg');
});

it('answers the old venue view with a 301 to the cities, keeping the scope', function () {
    // The portal removed its venue model (einundzwanzig-portal 5aba6dc); the „Orte" tab is
    // gone, and a shared or bookmarked link to it lands on the city view of this page.
    completeOnboarding();
    withoutPortalToken();

    $this->get('/ich/inhalte/orte?tab=orte')->assertStatus(301)->assertRedirect('/ich/inhalte/orte');
    $this->get('/ich/inhalte/orte?umfang=alle&tab=orte')->assertStatus(301)->assertRedirect('/ich/inhalte/orte?umfang=alle');
    $this->get('/ich/inhalte/orte')->assertOk()->assertSee(__('Meine Städte'))->assertDontSee('data-orte-liste="orte"', false);
});

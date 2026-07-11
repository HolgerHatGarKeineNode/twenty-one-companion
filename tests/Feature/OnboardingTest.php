<?php

use App\Http\Integrations\Portal\Requests\GetCountriesRequest;
use App\Http\Integrations\Portal\Requests\GetMobileMeetupsRequest;
use App\Services\AppPreferences;
use Livewire\Livewire;
use Native\Mobile\Facades\SecureStorage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

/** Map-Meetups, damit die Regions-Auswahl (CountryOptions) Treffer hat. */
function mockOnboardingMeetups(): void
{
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([
            mobileMeetupFixture(),
            mobileMeetupFixture(['name' => 'Einundzwanzig Wien', 'country' => 'AT', 'city' => 'Wien']),
        ]),
        GetCountriesRequest::class => MockResponse::make([
            ['id' => 1, 'name' => 'Deutschland', 'code' => 'de', 'flag' => 'https://example.test/de.svg'],
            ['id' => 2, 'name' => 'Österreich', 'code' => 'at', 'flag' => 'https://example.test/at.svg'],
        ]),
    ]);
}

it('redirects to the onboarding until it is completed', function () {
    resetOnboarding();

    $this->get(route('home'))->assertRedirect(route('onboarding'));
    $this->get(route('meetups'))->assertRedirect(route('onboarding'));
    $this->get(route('profile'))->assertRedirect(route('onboarding'));
});

it('keeps the deep-link auth callbacks outside the onboarding gate and returns to the pager', function () {
    resetOnboarding();
    SecureStorage::shouldReceive('set')
        ->once()
        ->with('portal_api_token', '12|secrettoken')
        ->andReturnTrue();

    // Token wird gespeichert (nicht von der Middleware verschluckt) und der
    // mitten im Onboarding angemeldete Nutzer landet wieder im Pager.
    $this->get('/auth?token='.urlencode('12|secrettoken'))
        ->assertRedirect(route('onboarding'))
        ->assertSessionHas('portal-connected');
});

it('starts at the language step so the user picks a language before reading anything', function () {
    resetOnboarding();
    withoutPortalToken();

    $this->get(route('onboarding'))
        ->assertOk()
        ->assertSee(__('Deine Sprache'))
        ->assertSee('Deutsch')
        ->assertSee('English')
        ->assertSee(__('Weiter'));
});

it('applies the chosen language immediately on the first step', function () {
    resetOnboarding();
    withoutPortalToken();

    Livewire::test('pages::onboarding.index')
        ->assertSet('step', AppPreferences::STEP_LANGUAGE)
        ->set('locale', 'en');

    expect(app(AppPreferences::class)->locale())->toBe('en');
});

it('walks through the pager and completes the onboarding', function () {
    resetOnboarding();
    withoutPortalToken();
    mockOnboardingMeetups();

    Livewire::test('pages::onboarding.index')
        ->assertSet('step', AppPreferences::STEP_LANGUAGE)
        ->assertSee(__('Deine Sprache'))
        ->call('next') // → Welcome
        ->assertSet('step', AppPreferences::STEP_WELCOME)
        ->assertSee(__('Meetups finden'))
        ->call('next') // → Region
        ->assertSet('step', AppPreferences::STEP_REGION)
        ->assertSee(__('Deine Region'))
        ->set('country', 'at')
        ->call('next') // → Push (kein Portal-Schritt mehr — Login nur bei Bedarf)
        ->assertSet('step', AppPreferences::STEP_NOTIFICATIONS)
        ->assertSee(__('Nichts mehr verpassen'))
        ->call('skip') // Push überspringen → Fertig
        ->assertSet('step', AppPreferences::STEP_DONE)
        ->call('finish')
        ->assertRedirect(route('meetups'));

    $preferences = app(AppPreferences::class);

    expect($preferences->isOnboarded())->toBeTrue()
        ->and($preferences->locale())->toBe('de')
        ->and($preferences->country())->toBe('at');
});

it('persists the reached step so a restart resumes mid-pager', function () {
    resetOnboarding();
    withoutPortalToken();
    mockOnboardingMeetups();

    Livewire::test('pages::onboarding.index')
        ->call('next')
        ->call('next');

    // Schritt landet in den Preferences …
    expect(app(AppPreferences::class)->onboardingStep())->toBe(AppPreferences::STEP_REGION);
});

it('resumes at the saved step after an app restart', function () {
    resetOnboarding();
    withoutPortalToken();
    mockOnboardingMeetups();
    app(AppPreferences::class)->setOnboardingStep(AppPreferences::STEP_REGION);

    Livewire::test('pages::onboarding.index')
        ->assertSet('step', AppPreferences::STEP_REGION)
        ->assertSee(__('Deine Region'));
});

it('can step back through the pager', function () {
    resetOnboarding();
    withoutPortalToken();

    Livewire::test('pages::onboarding.index')
        ->assertSet('step', AppPreferences::STEP_LANGUAGE)
        ->call('next')
        ->assertSet('step', AppPreferences::STEP_WELCOME)
        ->call('back')
        ->assertSet('step', AppPreferences::STEP_LANGUAGE);
});

it('advances past the notification priming when enabling notifications', function () {
    resetOnboarding();
    withoutPortalToken();
    app(AppPreferences::class)->setOnboardingStep(AppPreferences::STEP_NOTIFICATIONS);

    // PushNotifications::enroll() ist im Test ein geguardeter No-op (kein
    // nativephp_call) — der Flow muss trotzdem zum Fertig-Schritt weiterlaufen.
    Livewire::test('pages::onboarding.index')
        ->call('enableNotifications')
        ->assertSet('step', AppPreferences::STEP_DONE);
});

it('stores the selection and redirects to the start page when finishing', function () {
    resetOnboarding();
    withoutPortalToken();
    mockOnboardingMeetups();
    app(AppPreferences::class)->setOnboardingStep(AppPreferences::STEP_DONE);

    Livewire::test('pages::onboarding.index')
        ->assertSet('locale', 'de')
        ->assertSet('country', 'de')
        ->set('country', 'at')
        ->call('finish')
        ->assertRedirect(route('meetups'));

    $preferences = app(AppPreferences::class);

    expect($preferences->isOnboarded())->toBeTrue()
        ->and($preferences->country())->toBe('at');
});

it('rejects an unknown region', function () {
    resetOnboarding();
    withoutPortalToken();
    mockOnboardingMeetups();
    app(AppPreferences::class)->setOnboardingStep(AppPreferences::STEP_REGION);

    Livewire::test('pages::onboarding.index')
        ->set('country', 'xx')
        ->call('next')
        ->assertHasErrors(['country']);

    expect(app(AppPreferences::class)->isOnboarded())->toBeFalse();
});

it('offers only countries that actually have meetups, with a dach fallback when offline', function () {
    resetOnboarding();
    withoutPortalToken();
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([], 500),
    ]);
    app(AppPreferences::class)->setOnboardingStep(AppPreferences::STEP_REGION);

    Livewire::test('pages::onboarding.index')
        ->assertSee('Deutschland')
        ->assertSee('Österreich')
        ->assertSee('Schweiz');
});

it('redirects onboarded users away from the onboarding', function () {
    withoutPortalToken();
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([]),
    ]);

    // Schon onboardet → über die Start-Weiche (/), die client-seitig Chat vs. Meetups wählt.
    $this->get(route('onboarding'))->assertRedirect(route('home'));
});

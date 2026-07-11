<?php

use App\Http\Integrations\Portal\Requests\GetMobileMeetupsRequest;
use App\Services\AppPreferences;
use App\Services\BrandResolver;
use App\Support\Brand;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('maps a country to its brand', function (string $country, Brand $expected) {
    expect(Brand::forCountry($country))->toBe($expected);
})->with([
    'Deutschland' => ['de', Brand::Einundzwanzig],
    'Österreich' => ['at', Brand::Einundzwanzig],
    'Schweiz' => ['ch', Brand::Einundzwanzig],
    'Liechtenstein' => ['li', Brand::Einundzwanzig],
    'Lettland nutzt DE-Marke' => ['lv', Brand::Einundzwanzig],
    'Ungarn' => ['hu', Brand::Huszonegy],
    'Niederlande' => ['nl', Brand::Eenentwintig],
    'Belgien' => ['be', Brand::Eenentwintig],
    'Polen' => ['pl', Brand::DwadziesciaJeden],
    'Portugal' => ['pt', Brand::VinteEUm],
    'Brasilien' => ['br', Brand::VinteEUm],
    'Spanien' => ['es', Brand::Veintiuno],
    'Mexiko' => ['mx', Brand::Veintiuno],
    'Argentinien' => ['ar', Brand::Veintiuno],
]);

it('falls back to TWENTY ONE for unmapped or empty countries', function (string $country) {
    expect(Brand::forCountry($country))->toBe(Brand::TwentyOne);
})->with([
    'leer (alle Länder)' => [''],
    'USA' => ['us'],
    'Frankreich' => ['fr'],
    'unbekannt' => ['xx'],
]);

it('normalises casing and whitespace of the country code', function () {
    expect(Brand::forCountry('DE'))->toBe(Brand::Einundzwanzig)
        ->and(Brand::forCountry('  Hu '))->toBe(Brand::Huszonegy);
});

it('exposes the wordmark label and the international app name', function () {
    expect(Brand::Einundzwanzig->label())->toBe('EINUNDZWANZIG')
        ->and(Brand::Einundzwanzig->appName())->toBe('EINUNDZWANZIG Companion')
        ->and(Brand::Huszonegy->label())->toBe('HUSZONEGY')
        ->and(Brand::TwentyOne->appName())->toBe('TWENTY ONE Companion');
});

it('has a registered wordmark component for every brand', function (Brand $brand) {
    expect($brand->wordmarkComponent())->toBe('brand.wordmark.'.$brand->value)
        ->and(View::exists('components.brand.wordmark.'.$brand->value))->toBeTrue();
})->with(Brand::cases());

it('resolves the current brand from the stored region', function () {
    completeOnboarding(country: 'hu');

    expect(app(BrandResolver::class)->current())->toBe(Brand::Huszonegy);
});

it('resolves the default brand when no region is set', function () {
    completeOnboarding(country: '');

    expect(app(BrandResolver::class)->current())->toBe(Brand::TwentyOne);
});

it('supports the eight portal languages', function () {
    expect(AppPreferences::SUPPORTED_LOCALES)
        ->toBe(['de', 'en', 'es', 'hu', 'lv', 'nl', 'pl', 'pt']);

    $preferences = app(AppPreferences::class);

    foreach (['es', 'hu', 'lv', 'nl', 'pl', 'pt'] as $locale) {
        $preferences->setLocale($locale);
        expect($preferences->locale())->toBe($locale);
    }
});

it('celebrates a real brand change when switching region', function () {
    withoutPortalToken();
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([mobileMeetupFixture(['country' => 'HU'])]),
    ]);
    completeOnboarding(country: 'de');

    Livewire::test('pages::profile.index')
        ->set('country', 'hu')
        ->assertDispatched('brand-changed', slug: 'huszonegy', label: 'HUSZONEGY');

    expect(app(AppPreferences::class)->country())->toBe('hu');
});

it('does not celebrate when the brand stays the same', function () {
    withoutPortalToken();
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([]),
    ]);
    completeOnboarding(country: 'de');

    // DACH-Fallback: de/at/ch sind gültig und teilen sich die Marke EINUNDZWANZIG.
    Livewire::test('pages::profile.index')
        ->set('country', 'at')
        ->assertNotDispatched('brand-changed');
});

it('switches brand and celebrates when changing the meetups country filter', function () {
    withoutPortalToken();
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([mobileMeetupFixture(['country' => 'HU'])]),
    ]);
    completeOnboarding(country: 'de');

    Livewire::test('pages::meetups.index')
        ->set('country', 'hu')
        ->assertDispatched('brand-changed', slug: 'huszonegy', label: 'HUSZONEGY');

    expect(app(AppPreferences::class)->country())->toBe('hu');
});

it('reflects a country change on the same resolver instance (no stale memoization)', function () {
    // NativePHP läuft als langlebiger Prozess: scoped Singletons überleben
    // Requests. Dieselbe Resolver-Instanz muss eine zwischenzeitliche
    // Regionsänderung widerspiegeln, sonst friert das Branding beim Navigieren
    // auf der App-Start-Marke ein.
    completeOnboarding(country: 'de');
    $resolver = app(BrandResolver::class);
    expect($resolver->current())->toBe(Brand::Einundzwanzig);

    app(AppPreferences::class)->setCountry('hu');

    expect($resolver->current())->toBe(Brand::Huszonegy);
});

it('renders the current brand wordmark in the live top-bar component', function () {
    completeOnboarding(country: 'hu');

    $html = Blade::render('<x-brand-wordmark-live/>');

    // Aktive Marke ist server-seitig sichtbar (kein display:none), inaktive nicht.
    expect($html)->toContain("slug: 'huszonegy'")
        ->and($html)->toContain("slug === 'huszonegy'")
        ->and($html)->toContain("slug === 'einundzwanzig'");
});

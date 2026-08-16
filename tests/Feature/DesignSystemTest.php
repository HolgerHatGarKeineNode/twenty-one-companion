<?php

use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMobileMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('renders the skeleton card with the requested number of placeholders', function () {
    $html = Blade::render('<x-skeleton-card :count="2" />');

    expect(substr_count($html, 'skeleton'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('rounded-card')
        ->and($html)->toContain('aria-hidden="true"');
});

it('renders the detail skeleton variant', function () {
    $html = Blade::render('<x-skeleton-card variant="detail" />');

    expect($html)->toContain('skeleton')
        ->and($html)->toContain('rounded-card');
});

it('renders the list link card with press feedback and haptics', function () {
    $html = Blade::render('<x-list-link-card href="/x">Inhalt</x-list-link-card>');

    expect($html)->toContain('pressable')
        ->and($html)->toContain('surface-card')
        ->and($html)->toContain("\$haptic('light')")
        ->and($html)->toContain('Inhalt');
});

it('renders the place card with the new card tokens', function () {
    $html = Blade::render('<x-place-card name="Wien" subtitle="AT" />');

    expect($html)->toContain('surface-card')
        ->and($html)->toContain('Wien');
});

it('renders the bottom sheet with a grabber handle', function () {
    $html = Blade::render('<x-sheet name="demo" heading="Titel">Body</x-sheet>');

    expect($html)->toContain('rounded-full') // Greifer
        ->and($html)->toContain('Titel')
        ->and($html)->toContain('Body');
});

it('lets the sheet close button follow the locale instead of freezing', function () {
    // Der Schließen-Knopf des Pakets trägt `aria-label="{{ __('Close modal') }}"`
    // in einem `@blaze(fold: true)`-Stub — Blaze backt das Ergebnis beim
    // Kompilieren ein, der Text bliebe danach in JEDER Sprache derselbe.
    // Deshalb zeichnet x-sheet ihn selbst. Dieser Test misst genau das: zweimal
    // rendern, zwei verschiedene Sprachen, zwei verschiedene Texte.
    $render = function (string $locale): string {
        app()->setLocale($locale);

        return Blade::render('<x-sheet name="demo">Body</x-sheet>');
    };

    $de = $render('de');
    $en = $render('en');

    expect($de)->toContain('aria-label="Fenster schließen"')
        ->and($de)->not->toContain('Close modal')
        ->and($en)->toContain('aria-label="Close dialog"')
        ->and($en)->not->toContain('Fenster schließen');
});

it('labels the clear button of an input from the app strings, not the package default', function () {
    // resources/views/flux/input/clearable.blade.php übersteuert den Paket-Stub.
    // Anders als beim Sheet bleibt der Text beim Kompilieren eingefroren (der
    // umgebende flux:input ist gefaltet) — diese Kopie entscheidet also nur,
    // WELCHE Sprache einfriert. Der Test hält genau das fest: die Standard-
    // sprache der App gewinnt, nicht das englische Paket-Label.
    app()->setLocale('de');

    $html = Blade::render('<flux:input clearable wire:model="q"/>', [
        'errors' => new ViewErrorBag,
    ]);

    expect($html)->toContain('aria-label="Eingabe leeren"')
        ->and($html)->not->toContain('aria-label="Clear input"');
});

it('renders the empty state with the icon tile and a call to action slot', function () {
    $html = Blade::render(
        '<x-empty-state icon="map-pin" heading="Leer"><button>Anlegen</button></x-empty-state>'
    );

    expect($html)->toContain('empty-state')
        ->and($html)->toContain('rounded-tile')
        ->and($html)->toContain('Leer')
        ->and($html)->toContain('Anlegen');
});

it('renders the language picker without a native select so it keeps the dark theme', function () {
    // Ein natives <select> lässt die Android-WebView als System-Dialog
    // aufklappen, der sein Theme aus dem App-Context zieht — in der dunklen
    // App eine weiße Liste. Die Listbox-Variante ist reines HTML.
    $html = Blade::render('<x-locale-radio-group/>');

    expect($html)->not->toContain('<select')
        ->and($html)->toContain('<ui-select');

    foreach (['Deutsch', 'English', 'Español', 'Magyar', 'Latviešu', 'Nederlands', 'Polski', 'Português'] as $language) {
        expect($html)->toContain($language);
    }
});

it('renders the region picker without a native select', function () {
    $html = Blade::render('<x-country-select :countries="$countries"/>', [
        'countries' => collect([
            ['code' => 'de', 'name' => 'Deutschland'],
            ['code' => 'at', 'name' => 'Österreich'],
        ]),
    ]);

    expect($html)->not->toContain('<select')
        ->and($html)->toContain('<ui-select')
        ->and($html)->toContain('Deutschland')
        ->and($html)->toContain('Österreich')
        ->and($html)->toContain(__('Alle Länder'));
});

it('keeps the country filters of the browse pages free of native selects', function () {
    // Dieselbe Falle wie bei der Sprachauswahl, nur inline auf den drei
    // Browse-Seiten: ein natives <select> reißt hier ein weißes System-Blatt
    // über die dunkle Liste.
    withoutPortalToken();
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([mobileMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    // Meetups und Termine holen ihre Länder-Optionen erst im Lazy-Load; die
    // Karte rendert sie sofort.
    foreach (['pages::meetups.index' => true, 'pages::events.index' => true, 'pages::map.index' => false] as $page => $lazy) {
        $component = Livewire::test($page);
        $html = ($lazy ? $component->call('load') : $component)->html();

        expect($html)->not->toContain('<select')
            ->and($html)->toContain('<ui-select');
    }
});

it('keeps the settings page free of native selects', function () {
    withoutPortalToken();
    MockClient::global([GetMobileMeetupsRequest::class => MockResponse::make([])]);

    // Sprache, Region und Zeitzone sitzen hier nebeneinander — bliebe eine
    // davon nativ, klappte genau dort ein weißes Blatt auf.
    $html = Livewire::test('pages::profile.index')->html();

    expect($html)->not->toContain('<select')
        ->and(substr_count($html, '</ui-select>'))->toBe(3);

    // Kein `searchable` an der Zeitzone: dessen Flux-Strings frieren beim
    // Kompilieren auf eine Sprache ein (Begründung an der Komponente).
    expect($html)->not->toContain('data-flux-select-search');
});

it('keeps the editor sheets free of native selects', function () {
    withPortalToken();
    // Ohne gecachtes Profil bleibt myCourses() leer und das Kurs-Sheet zeigt
    // seinen Leerzustand statt der Auswahl.
    withCachedPortalProfile();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5])]),
    ]);

    foreach (['event-editor', 'course-event-editor'] as $editor) {
        $html = Livewire::test($editor)->call('open')->html();

        // Das toContain ist die Kalibrierung: ohne es wäre der Test auch dann
        // grün, wenn das Sheet die Auswahl gar nicht gerendert hat.
        expect($html)->not->toContain('<select')
            ->and($html)->toContain('<ui-select');
    }
});

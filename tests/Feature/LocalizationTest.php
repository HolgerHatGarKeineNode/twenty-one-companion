<?php

use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use Illuminate\Support\Facades\File;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

/**
 * Quellen der Mobile-UI, deren Übersetzungs-Keys vollständig in
 * lang/en.json vorhanden sein müssen. Die Starter-Kit-Views
 * (pages/auth, pages/settings, …) haben englische Quell-Strings und
 * bleiben bewusst außen vor.
 *
 * @return list<string>
 */
function mobileUiSources(): array
{
    $files = [
        resource_path('views/layouts/mobile.blade.php'),
        resource_path('views/components/create-fab.blade.php'),
        resource_path('views/livewire/portal/connect.blade.php'),
        resource_path('views/livewire/global-search.blade.php'),
        resource_path('views/livewire/meetup-picker.blade.php'),
        resource_path('views/livewire/meetup-editor.blade.php'),
        resource_path('views/livewire/meetup-privacy-hint-banner.blade.php'),
        resource_path('views/livewire/event-editor.blade.php'),
        resource_path('views/livewire/venue-editor.blade.php'),
        resource_path('views/livewire/city-editor.blade.php'),
        resource_path('views/livewire/lecturer-editor.blade.php'),
        resource_path('views/livewire/course-editor.blade.php'),
        resource_path('views/livewire/course-event-editor.blade.php'),
        resource_path('views/components/image-picker.blade.php'),
        resource_path('views/components/empty-state.blade.php'),
        resource_path('views/components/error-state.blade.php'),
        resource_path('views/components/portal-status.blade.php'),
        resource_path('views/components/locale-radio-group.blade.php'),
        resource_path('views/components/country-select.blade.php'),
        resource_path('views/components/list-link-card.blade.php'),
        resource_path('views/components/place-card.blade.php'),
        resource_path('views/components/meetup-avatar.blade.php'),
        resource_path('views/components/bottom-nav-item.blade.php'),
        app_path('Livewire/PortalPage.php'),
        app_path('Data/Portal/MapMeetupData.php'),
        app_path('Data/Portal/LecturerDetailData.php'),
    ];

    foreach (['meetups', 'events', 'map', 'courses', 'lecturers', 'profile', 'onboarding', 'mine', 'more'] as $module) {
        foreach (File::files(resource_path("views/pages/{$module}")) as $file) {
            $files[] = $file->getPathname();
        }
    }

    return array_values(array_filter($files, fn (string $path): bool => file_exists($path)));
}

it('covers every translation key of the mobile ui in lang/en.json', function () {
    $english = json_decode((string) file_get_contents(base_path('lang/en.json')), associative: true);

    expect($english)->toBeArray();

    $missing = [];

    foreach (mobileUiSources() as $path) {
        $code = (string) file_get_contents($path);

        preg_match_all("/(?:__|trans_choice)\(\s*'((?:[^'\\\\]|\\\\.)*)'/u", $code, $matches);

        foreach ($matches[1] as $key) {
            $key = stripcslashes($key);

            if (! array_key_exists($key, $english)) {
                $missing[$key] = basename($path);
            }
        }
    }

    expect($missing)->toBe([], 'Keys ohne englische Übersetzung: '.json_encode($missing, JSON_UNESCAPED_UNICODE));
});

it('renders the meetups page in english when the locale preference is en', function () {
    completeOnboarding(locale: 'en');
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('meetups'))
        ->assertOk()
        ->assertSee('All countries')
        ->assertSee('Search meetup or city');
});

it('renders the meetups page in german by default', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('meetups'))
        ->assertOk()
        ->assertSee('Alle Länder');
});

it('covers every Mehr-Hub key in ALL locale files (not just en)', function () {
    // Der Mehr-Hub (config-Nav-Label + Karten-Subtitles) muss in JEDER
    // ausgelieferten Sprache aufgelöst werden — sonst greift der de-Fallback und
    // der Screen wird gemischtsprachig (spanische Labels, deutsche Untertitel).
    // Die en-only-Coverage oben hat genau diese Lücke NICHT erkannt.
    $code = (string) file_get_contents(resource_path('views/pages/more/⚡index.blade.php'));
    preg_match_all("/(?:__|trans_choice)\(\s*'((?:[^'\\\\]|\\\\.)*)'/u", $code, $m);
    $keys = array_unique(array_map('stripcslashes', $m[1]));

    $missing = [];
    foreach (['en', 'es', 'pt', 'nl', 'pl', 'hu', 'lv'] as $loc) {
        $data = json_decode((string) file_get_contents(base_path("lang/{$loc}.json")), true);
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                $missing[$loc][] = $key;
            }
        }
    }

    expect($missing)->toBe([], 'Mehr-Hub-Keys ohne Übersetzung: '.json_encode($missing, JSON_UNESCAPED_UNICODE));
});

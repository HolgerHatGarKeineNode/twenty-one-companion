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

    // `profile` and `more` are gone with P2: the app's settings sections are injected into
    // the package hub (`views/livewire/settings/*`, collected below) and the "Mehr" hub is
    // replaced by Start and „Ich".
    foreach (File::files(resource_path('views/livewire/settings')) as $file) {
        $files[] = $file->getPathname();
    }
    foreach (File::files(resource_path('views/partials/settings')) as $file) {
        $files[] = $file->getPathname();
    }
    $files[] = resource_path('views/partials/ich/inhalte.blade.php');

    foreach (['meetups', 'events', 'map', 'courses', 'lecturers', 'onboarding', 'mine'] as $module) {
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

it('covers every key of the injected settings sections in ALL locale files (not just en)', function () {
    // Until P2 this case measured the "Mehr" hub. That hub is gone; what took its place as
    // the app's OWN screen text are the sections injected into the package settings hub
    // (`views/livewire/settings/*`). The demand is unchanged and it is the stricter one: every
    // shipped language has to resolve them, otherwise the German fallback kicks in and the
    // screen becomes mixed-language (Spanish labels, German descriptions). The en-only
    // coverage above does NOT catch that gap.
    $keys = [];

    foreach (File::files(resource_path('views/livewire/settings')) as $file) {
        $code = (string) file_get_contents($file->getPathname());
        preg_match_all("/(?:__|trans_choice)\(\s*'((?:[^'\\\\]|\\\\.)*)'/u", $code, $m);
        $keys = [...$keys, ...array_map('stripcslashes', $m[1])];
    }

    // Fail-closed: a probe that collects nothing would report a clean result.
    expect($keys)->not->toBeEmpty('no translation key found — the probe measures nothing');

    $keys = array_unique($keys);

    $missing = [];
    foreach (['en', 'es', 'pt', 'nl', 'pl', 'hu', 'lv'] as $loc) {
        $data = json_decode((string) file_get_contents(base_path("lang/{$loc}.json")), true);
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                $missing[$loc][] = $key;
            }
        }
    }

    expect($missing)->toBe([], 'settings keys without a translation: '.json_encode($missing, JSON_UNESCAPED_UNICODE));
});

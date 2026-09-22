<?php

use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * The portal removed its venue model (einundzwanzig-portal 5aba6dc, 2026-08-17) and answers
 * GET /api/venues with a 301 to /api/courses. The app hydrated those course rows as venues,
 * silently. Nothing in the app may ask for a venue endpoint any more.
 */
afterEach(fn () => MockClient::destroyGlobal());

it('declares no portal request against a venue endpoint', function () {
    $endpoints = [];

    foreach (glob(app_path('Http/Integrations/Portal/Requests/*.php')) as $file) {
        preg_match_all("/function resolveEndpoint\(\): string\s*\{\s*return ([^;]+);/", (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $expression) {
            $endpoints[basename($file)] = $expression;
        }
    }

    // Calibration: the scan sees the requests at all.
    expect($endpoints)->toHaveKey('GetCitiesRequest.php')
        ->and(array_filter($endpoints, fn (string $expression): bool => str_contains(strtolower($expression), 'venue')))->toBe([]);
});

it('sends no request to a venue endpoint from the surfaces that used to', function () {
    completeOnboarding();
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global(['*' => MockResponse::make([])]);

    Livewire::test('pages::mine.places');
    Livewire::withQueryParams(['umfang' => 'alle'])->test('pages::mine.places');
    Livewire::test('pages::mine.index');
    Livewire::test('city-editor')->call('open');
    Livewire::test('course-event-editor')->call('open')->set('cityQuery', 'Regens');
    $this->get('/ich/inhalte/orte')->assertOk();

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => true);
    MockClient::global()->assertNotSent(fn (Request $request, Response $response): bool => str_contains((string) $response->getPendingRequest()->getUri(), 'venue'));
});

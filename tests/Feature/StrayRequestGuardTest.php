<?php

use App\Http\Integrations\Portal\PortalConnector;
use App\Http\Integrations\Portal\Requests\GetCountriesRequest;
use Illuminate\Http\Client\StrayRequestException as HttpStrayRequestException;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The control for the rule "no test leaves the machine" (`preventOutgoingRequests()` in
 * `tests/Pest.php`). A guard that never fires looks exactly like a clean suite, and on
 * 2026-10-24 eleven tests turned out to have been calling the production portal.
 */
afterEach(fn () => MockClient::destroyGlobal());

it('stops a Laravel HTTP client request that has no fake', function () {
    Http::get('https://portal.einundzwanzig.space/api/user');
})->throws(HttpStrayRequestException::class);

it('stops a Saloon request that has no MockClient, and names the request', function () {
    app(PortalConnector::class)->send(new GetCountriesRequest);
})->throws(RuntimeException::class, GetCountriesRequest::class);

it('lets a faked request through on both stacks', function () {
    Http::fake(['*/api/user' => Http::response(['id' => 1])]);
    MockClient::global([GetCountriesRequest::class => MockResponse::make([])]);

    expect(Http::get('https://portal.einundzwanzig.space/api/user')->json('id'))->toBe(1)
        ->and(app(PortalConnector::class)->send(new GetCountriesRequest)->status())->toBe(200);
});

it('does not carry a global MockClient from one test into the next', function () {
    // The previous case set one and its `afterEach` destroyed it, so this is the harness's
    // own reset, not the cleanup of the test above: set one here, then let the next
    // `beforeEach` prove it is gone.
    MockClient::global(['*' => MockResponse::make([])]);
    expect(MockClient::getGlobal())->not->toBeNull();

    preventOutgoingRequests();

    expect(MockClient::getGlobal())->toBeNull();
});

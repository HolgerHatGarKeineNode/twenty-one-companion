<?php

use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('shows the connect CTA for guests', function () {
    withoutPortalToken();

    Livewire::test('pages::mine.index')
        ->assertSee(__('Mit Portal verbinden'))
        ->assertSee(__('Konto verbinden'))
        ->assertDontSee(__('Meine Kurse'));
});

it('bundles the own content sections with counts for connected users', function () {
    withPortalToken();
    withCachedPortalProfile();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture()]]),
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture()]),
    ]);

    Livewire::test('pages::mine.index')
        ->assertSee(__('Meine Meetups'))
        ->assertSee(__('Meine Termine'))
        ->assertSee(__('Meine Städte'))
        ->assertSee(route('ich.inhalte.orte'))
        ->assertSee(__('Meine Kurse'))
        ->assertSee(route('ich.inhalte.meetups'))
        ->assertSee(route('ich.inhalte.termine'))
        // trans_choice-Zähler
        ->assertSee('1 Meetup')
        ->assertSee('1 Kurs');
});

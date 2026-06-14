<?php

use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetMyCourseEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyLecturersRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('shows the connect CTA for guests', function () {
    withoutPortalToken();

    Livewire::test('pages::mine.teaching')
        ->assertSee(__('Mit Portal verbinden'))
        ->assertSee(__('Konto verbinden'))
        ->assertDontSee(__('Kurs bearbeiten'));
});

it('lists own courses with an edit affordance and create CTA for lecturers', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5, 'name' => 'Bitcoin, Blockchain und Geld'])]),
    ]);

    Livewire::test('pages::mine.teaching')
        ->assertSee('Bitcoin, Blockchain und Geld')
        ->assertSee('Toni Stack')
        ->assertSee(__('Kurs anlegen'))
        ->assertSee(__('Kurs bearbeiten'));
});

it('hides the course create CTA for non-lecturers', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => false]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([]),
    ]);

    Livewire::test('pages::mine.teaching')
        ->assertSee(__('Noch keine eigenen Kurse'))
        ->assertSee(__('Nur Referenten können Kurse anlegen.'))
        ->assertDontSee(__('Kurs anlegen'));
});

it('lists own lecturers with an edit affordance on the lecturers tab', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7]);
    MockClient::global([
        GetMyLecturersRequest::class => MockResponse::make(['data' => [myLecturerFixture(['id' => 3, 'name' => 'Toni Stack'])]]),
    ]);

    Livewire::test('pages::mine.teaching', ['tab' => 'referenten'])
        ->assertSee('Toni Stack')
        ->assertSee('Bitcoin-Educator')
        ->assertSee(__('Referent anlegen'))
        ->assertSee(__('Referent bearbeiten'));
});

it('lets any connected user create a lecturer profile', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => false]);
    MockClient::global([
        GetMyLecturersRequest::class => MockResponse::make(['data' => []]),
    ]);

    Livewire::test('pages::mine.teaching', ['tab' => 'referenten'])
        ->assertSee(__('Noch keine eigenen Referenten'))
        ->assertSee(__('Referent anlegen'));
});

it('lists own course events on the termine tab', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5, 'name' => 'Bitcoin, Blockchain und Geld'])]),
        GetMyCourseEventsRequest::class => MockResponse::make([myCourseEventFixture(['id' => 9, 'course_id' => 5])]),
    ]);

    Livewire::test('pages::mine.teaching', ['tab' => 'termine'])
        ->assertSee('Bitcoin, Blockchain und Geld')
        ->assertSee(__('Kurs-Event anlegen'))
        ->assertSee(__('Kurs-Event bearbeiten'));
});

it('refreshes the lists when teaching content changes', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['name' => 'Bitcoin, Blockchain und Geld'])]),
    ]);

    Livewire::test('pages::mine.teaching')
        ->assertSee('Bitcoin, Blockchain und Geld')
        ->call('refreshLists')
        ->assertSee('Bitcoin, Blockchain und Geld');
});

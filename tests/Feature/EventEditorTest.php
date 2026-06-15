<?php

use App\Http\Integrations\Portal\Requests\CreateMeetupEventRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use App\Http\Integrations\Portal\Requests\UpdateMeetupEventRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

afterEach(fn () => MockClient::destroyGlobal());

it('creates an event for the selected meetup and sends the combined start payload', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        CreateMeetupEventRequest::class => MockResponse::make(myMeetupEventFixture(['id' => 99]), 201),
    ]);

    Livewire::test('event-editor')
        ->call('open')
        ->set('form.meetup_id', 21)
        ->set('form.date', '2026-12-31')
        ->set('form.time', '19:00')
        ->set('form.location', 'Bitcoin-Bar')
        ->set('form.description', 'Jahresabschluss **2026**')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('meetup-event-saved')
        ->assertSet('editingId', null);

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof CreateMeetupEventRequest
        && $request->body()->all()['meetup_id'] === 21
        && $request->body()->all()['start'] === '2026-12-31 19:00'
        && $request->body()->all()['location'] === 'Bitcoin-Bar');
});

it('preselects the only own meetup when creating from the FAB', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
    ]);

    // Der native Select zeigt den ersten Eintrag an — ohne Vorauswahl bliebe
    // meetup_id ungebunden (Bug, auf dem Emulator gefunden). Jetzt ist es gesetzt.
    Livewire::test('event-editor')
        ->call('open')
        ->assertSet('form.meetup_id', 21)
        ->assertSet('meetupLocked', false);
});

it('requires a meetup, date and time before sending', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
    ]);

    Livewire::test('event-editor')
        ->call('open')
        // Vorauswahl zurücknehmen, um die Pflichtfeld-Regeln zu prüfen.
        ->set('form.meetup_id', null)
        ->call('save')
        ->assertHasErrors(['form.meetup_id' => 'required', 'form.date' => 'required', 'form.time' => 'required'])
        ->assertNotDispatched('meetup-event-saved');

    MockClient::global()->assertNotSent(CreateMeetupEventRequest::class);
});

it('rejects a start in the past when creating', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
    ]);

    Livewire::test('event-editor')
        ->call('open')
        ->set('form.meetup_id', 21)
        ->set('form.date', '2020-01-01')
        ->set('form.time', '19:00')
        ->call('save')
        ->assertHasErrors('form.date')
        ->assertNotDispatched('meetup-event-saved');

    MockClient::global()->assertNotSent(CreateMeetupEventRequest::class);
});

it('pre-selects and locks the meetup when opened from a meetup detail', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
    ]);

    Livewire::test('event-editor')
        ->call('open', null, 21)
        ->assertSet('form.meetup_id', 21)
        ->assertSet('form.meetupName', 'Einundzwanzig Aschaffenburg')
        ->assertSet('meetupLocked', true);
});

it('loads an own event for editing and sends an update', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        GetMyMeetupEventsRequest::class => MockResponse::make(['data' => [myMeetupEventFixture(['id' => 55, 'meetup_id' => 21])]]),
        UpdateMeetupEventRequest::class => MockResponse::make(myMeetupEventFixture(['id' => 55]), 200),
    ]);

    Livewire::test('event-editor')
        ->call('open', 55)
        ->assertSet('editingId', 55)
        ->assertSet('form.meetup_id', 21)
        ->assertSet('form.date', '2026-12-31')
        ->assertSet('form.time', '19:00')
        ->assertSet('meetupLocked', true)
        ->set('form.location', 'Neuer Ort')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('meetup-event-saved');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof UpdateMeetupEventRequest
        && $request->resolveEndpoint() === '/meetup-events/55'
        && $request->body()->all()['location'] === 'Neuer Ort');
});

it('does not enforce the future rule when editing a past event', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        GetMyMeetupEventsRequest::class => MockResponse::make(['data' => [
            myMeetupEventFixture(['id' => 55, 'meetup_id' => 21, 'start' => '2022-01-01T19:00:00.000000Z']),
        ]]),
        UpdateMeetupEventRequest::class => MockResponse::make(myMeetupEventFixture(['id' => 55]), 200),
    ]);

    Livewire::test('event-editor')
        ->call('open', 55)
        ->set('form.location', 'Korrektur')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('meetup-event-saved');
});

it('maps a 422 start error back onto the date field', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        CreateMeetupEventRequest::class => MockResponse::make([
            'message' => 'The given data was invalid.',
            'errors' => ['start' => ['Das Startdatum ist ungültig.']],
        ], 422),
    ]);

    Livewire::test('event-editor')
        ->call('open')
        ->set('form.meetup_id', 21)
        ->set('form.date', '2026-12-31')
        ->set('form.time', '19:00')
        ->call('save')
        ->assertHasErrors('form.date')
        ->assertSee('Das Startdatum ist ungültig.')
        ->assertNotDispatched('meetup-event-saved');
});

it('keeps the editor open and reports a 403 when editing a foreign event', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        GetMyMeetupEventsRequest::class => MockResponse::make(['data' => [myMeetupEventFixture(['id' => 55, 'meetup_id' => 21])]]),
        UpdateMeetupEventRequest::class => MockResponse::make(['message' => 'This action is unauthorized.'], 403),
    ]);

    Livewire::test('event-editor')
        ->call('open', 55)
        ->set('form.location', 'Geändert')
        ->call('save')
        ->assertNotDispatched('meetup-event-saved')
        ->assertSet('editingId', 55);
});

it('hints to create a meetup first when the user has none', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => []]),
    ]);

    Livewire::test('event-editor')
        ->call('open')
        ->assertSee(__('Lege zuerst ein eigenes Meetup an'));
});

it('does not send a write without a portal token', function () {
    withoutPortalToken();

    // Ohne Token liefert myMeetups() leer (kein Request) und der PortalWriter
    // bricht vor dem Senden ab — der Termin wird nicht angelegt.
    Livewire::test('event-editor')
        ->call('open')
        ->set('form.meetup_id', 21)
        ->set('form.date', '2026-12-31')
        ->set('form.time', '19:00')
        ->call('save')
        ->assertNotDispatched('meetup-event-saved');
});

it('creates a weekly recurring series with the recurrence payload', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        CreateMeetupEventRequest::class => MockResponse::make(['data' => [myMeetupEventFixture(['id' => 99])]], 201),
    ]);

    Livewire::test('event-editor')
        ->call('open')
        ->set('form.meetup_id', 21)
        ->set('form.date', '2026-12-01')
        ->set('form.time', '19:00')
        ->set('form.repeats', true)
        ->set('form.recurrence_type', 'weekly')
        ->set('form.recurrence_day_of_week', 'tuesday')
        ->set('form.recurrence_end_date', '2026-12-31')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('meetup-event-saved');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof CreateMeetupEventRequest
        && $request->body()->all()['recurrence_type'] === 'weekly'
        && $request->body()->all()['recurrence_day_of_week'] === 'tuesday'
        && $request->body()->all()['recurrence_end_date'] === '2026-12-31');
});

it('creates a custom monthly-weekday series with day and position', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        CreateMeetupEventRequest::class => MockResponse::make(['data' => [myMeetupEventFixture(['id' => 99])]], 201),
    ]);

    Livewire::test('event-editor')
        ->call('open')
        ->set('form.meetup_id', 21)
        ->set('form.date', '2026-12-01')
        ->set('form.time', '19:00')
        ->set('form.repeats', true)
        ->set('form.recurrence_type', 'custom')
        ->set('form.recurrence_day_of_week', 'tuesday')
        ->set('form.recurrence_day_position', 'second')
        ->set('form.recurrence_end_date', '2027-06-30')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('meetup-event-saved');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof CreateMeetupEventRequest
        && $request->body()->all()['recurrence_type'] === 'custom'
        && $request->body()->all()['recurrence_day_of_week'] === 'tuesday'
        && $request->body()->all()['recurrence_day_position'] === 'second');
});

it('requires a type and end date when the series toggle is on', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
    ]);

    Livewire::test('event-editor')
        ->call('open')
        ->set('form.meetup_id', 21)
        ->set('form.date', '2026-12-01')
        ->set('form.time', '19:00')
        ->set('form.repeats', true)
        ->call('save')
        ->assertHasErrors(['form.recurrence_type', 'form.recurrence_end_date'])
        ->assertNotDispatched('meetup-event-saved');

    MockClient::global()->assertNotSent(CreateMeetupEventRequest::class);
});

it('rejects a series end date before the start date', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
    ]);

    Livewire::test('event-editor')
        ->call('open')
        ->set('form.meetup_id', 21)
        ->set('form.date', '2026-12-15')
        ->set('form.time', '19:00')
        ->set('form.repeats', true)
        ->set('form.recurrence_type', 'weekly')
        ->set('form.recurrence_end_date', '2026-12-01')
        ->call('save')
        ->assertHasErrors('form.recurrence_end_date')
        ->assertNotDispatched('meetup-event-saved');

    MockClient::global()->assertNotSent(CreateMeetupEventRequest::class);
});

it('does not send recurrence fields for a single event', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        CreateMeetupEventRequest::class => MockResponse::make(myMeetupEventFixture(['id' => 99]), 201),
    ]);

    Livewire::test('event-editor')
        ->call('open')
        ->set('form.meetup_id', 21)
        ->set('form.date', '2026-12-01')
        ->set('form.time', '19:00')
        ->call('save')
        ->assertHasNoErrors();

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof CreateMeetupEventRequest
        && ! array_key_exists('recurrence_type', $request->body()->all()));
});

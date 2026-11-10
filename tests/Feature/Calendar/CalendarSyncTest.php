<?php

use App\Enums\CalendarProvider;
use App\Enums\LocationType;
use App\Jobs\RemoveBookingFromCalendars;
use App\Jobs\SyncBookingToCalendars;
use App\Jobs\SyncCalendarBusyBlocks;
use App\Models\Booking;
use App\Models\BusyBlock;
use App\Models\CalendarAccount;
use App\Models\EventType;
use App\Models\User;
use App\Services\Calendar\CalendarProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00:00', 'UTC'));

    $this->host = User::factory()->create();
});

/**
 * Connect an account with a single conflict checking, write target calendar.
 */
function connectedAccount(User $host, CalendarProvider $provider): CalendarAccount
{
    $account = CalendarAccount::factory()->for($host)->create(['provider' => $provider]);

    $account->calendars()->create([
        'external_id' => 'primary-calendar',
        'name' => 'Primary',
        'is_primary' => true,
        'checks_conflicts' => true,
        'is_write_target' => true,
    ]);

    return $account->fresh('calendars');
}

test('google busy times are pulled into busy blocks', function () {
    $account = connectedAccount($this->host, CalendarProvider::Google);

    Http::fake([
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
            'calendars' => [
                'primary-calendar' => [
                    'busy' => [
                        ['start' => '2026-09-02T10:00:00Z', 'end' => '2026-09-02T11:00:00Z'],
                        ['start' => '2026-09-02T14:00:00Z', 'end' => '2026-09-02T15:00:00Z'],
                    ],
                ],
            ],
        ]),
    ]);

    (new SyncCalendarBusyBlocks($account))->handle(app(CalendarProviderManager::class));

    $blocks = BusyBlock::orderBy('starts_at')->get();

    expect($blocks)->toHaveCount(2)
        ->and($blocks->first()->starts_at->toIso8601String())->toBe('2026-09-02T10:00:00+00:00')
        ->and($blocks->first()->user_id)->toBe($this->host->id)
        ->and($account->fresh()->last_synced_at)->not->toBeNull();
});

test('holidays outlook adds to a calendar do not block it, so the organization decides', function () {
    $account = connectedAccount($this->host, CalendarProvider::Microsoft);

    Http::fake([
        'graph.microsoft.com/*' => Http::response([
            'value' => [
                [
                    'start' => ['dateTime' => '2026-10-12T00:00:00.0000000', 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => '2026-10-13T00:00:00.0000000', 'timeZone' => 'UTC'],
                    'showAs' => 'busy',
                    'isCancelled' => false,
                    'categories' => ['Holiday'],
                ],
                [
                    'start' => ['dateTime' => '2026-10-12T15:00:00.0000000', 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => '2026-10-12T16:00:00.0000000', 'timeZone' => 'UTC'],
                    'showAs' => 'busy',
                    'isCancelled' => false,
                    'categories' => ['Blue category'],
                ],
            ],
        ]),
    ]);

    (new SyncCalendarBusyBlocks($account))->handle(app(CalendarProviderManager::class));

    expect(BusyBlock::count())->toBe(1)
        ->and(BusyBlock::first()->starts_at->toIso8601String())->toBe('2026-10-12T15:00:00+00:00');
});

test('a subscribed holiday calendar never blocks time and its old blocks are cleared', function (CalendarProvider $provider, string $externalId, string $name) {
    $account = connectedAccount($this->host, $provider);
    $account->calendars()->update(['checks_conflicts' => false]);

    $holidays = $account->calendars()->create([
        'external_id' => $externalId,
        'name' => $name,
        'is_primary' => false,
        'checks_conflicts' => true,
        'is_write_target' => false,
    ]);

    $stale = BusyBlock::create([
        'calendar_id' => $holidays->id,
        'user_id' => $this->host->id,
        'starts_at' => CarbonImmutable::parse('2026-10-12 00:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-13 00:00:00', 'UTC'),
    ]);

    Http::fake();

    (new SyncCalendarBusyBlocks($account->fresh('calendars')))->handle(app(CalendarProviderManager::class));

    Http::assertNothingSent();

    expect(BusyBlock::find($stale->id))->toBeNull();
})->with([
    'outlook' => [CalendarProvider::Microsoft, 'AAMkHoliday', 'United States holidays'],
    'google' => [CalendarProvider::Google, 'en.usa#holiday@group.v.calendar.google.com', 'Holidays in United States'],
]);

test('microsoft busy times are pulled from the calendar view', function () {
    $account = connectedAccount($this->host, CalendarProvider::Microsoft);

    Http::fake([
        'graph.microsoft.com/*' => Http::response([
            'value' => [
                [
                    'start' => ['dateTime' => '2026-09-02T09:00:00.0000000', 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => '2026-09-02T09:30:00.0000000', 'timeZone' => 'UTC'],
                    'showAs' => 'busy',
                    'isCancelled' => false,
                ],
                [
                    'start' => ['dateTime' => '2026-09-02T12:00:00.0000000', 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => '2026-09-02T13:00:00.0000000', 'timeZone' => 'UTC'],
                    'showAs' => 'free',
                    'isCancelled' => false,
                ],
                [
                    'start' => ['dateTime' => '2026-09-02T15:00:00.0000000', 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => '2026-09-02T16:00:00.0000000', 'timeZone' => 'UTC'],
                    'showAs' => 'busy',
                    'isCancelled' => true,
                ],
            ],
        ]),
    ]);

    (new SyncCalendarBusyBlocks($account))->handle(app(CalendarProviderManager::class));

    // Free and cancelled events must not block the calendar.
    expect(BusyBlock::count())->toBe(1)
        ->and(BusyBlock::first()->starts_at->toIso8601String())->toBe('2026-09-02T09:00:00+00:00');
});

test('a resync replaces the cached window rather than duplicating it', function () {
    $account = connectedAccount($this->host, CalendarProvider::Google);

    Http::fake([
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
            'calendars' => [
                'primary-calendar' => [
                    'busy' => [['start' => '2026-09-02T10:00:00Z', 'end' => '2026-09-02T11:00:00Z']],
                ],
            ],
        ]),
    ]);

    $manager = app(CalendarProviderManager::class);

    (new SyncCalendarBusyBlocks($account))->handle($manager);
    (new SyncCalendarBusyBlocks($account))->handle($manager);

    expect(BusyBlock::count())->toBe(1);
});

test('an expired access token is refreshed before the api is called', function () {
    config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);

    $account = connectedAccount($this->host, CalendarProvider::Google);
    $account->update(['token_expires_at' => now()->subHour()]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'refreshed-token',
            'expires_in' => 3600,
        ]),
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => []]),
    ]);

    (new SyncCalendarBusyBlocks($account))->handle(app(CalendarProviderManager::class));

    expect($account->fresh()->access_token)->toBe('refreshed-token');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token')
        && $request['grant_type'] === 'refresh_token');
});

test('a booking is written to the hosts calendar with a teams link', function () {
    $account = connectedAccount($this->host, CalendarProvider::Microsoft);

    $eventType = EventType::factory()->ownedBy($this->host)->create([
        'location_type' => LocationType::MicrosoftTeams,
    ]);

    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $eventType->team_id,
        'location_type' => LocationType::MicrosoftTeams,
    ]);
    $booking->hosts()->attach($this->host);

    Http::fake([
        'graph.microsoft.com/*' => Http::response([
            'id' => 'graph-event-1',
            'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/abc'],
        ]),
    ]);

    (new SyncBookingToCalendars($booking))->handle(app(CalendarProviderManager::class));

    $booking->refresh();

    expect($booking->meeting_url)->toBe('https://teams.microsoft.com/l/meetup-join/abc')
        ->and($booking->calendarEvents)->toHaveCount(1)
        ->and($booking->calendarEvents->first()->external_id)->toBe('graph-event-1');

    Http::assertSent(fn ($request) => $request['isOnlineMeeting'] === true
        && $request['onlineMeetingProvider'] === 'teamsForBusiness');
});

test('a microsoft event lists no attendees, so outlook sends no invite of its own', function () {
    connectedAccount($this->host, CalendarProvider::Microsoft);

    $booking = Booking::factory()->create([
        'user_id' => $this->host->id,
        'location_type' => LocationType::MicrosoftTeams,
    ]);
    $booking->hosts()->attach($this->host);
    $booking->guests()->create(['email' => 'guest@example.com']);

    Http::fake(['graph.microsoft.com/*' => Http::response(['id' => 'graph-event-3'])]);

    (new SyncBookingToCalendars($booking))->handle(app(CalendarProviderManager::class));

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && ! isset($request['attendees']));
});

test('a microsoft event is written in the host timezone, so outlook shows the local time', function () {
    $this->host->update(['timezone' => 'America/Chicago']);
    connectedAccount($this->host, CalendarProvider::Microsoft);

    $booking = Booking::factory()->create([
        'user_id' => $this->host->id,
        'location_type' => LocationType::Phone,
        'starts_at' => CarbonImmutable::parse('2026-10-27 16:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-27 16:30:00', 'UTC'),
    ]);
    $booking->hosts()->attach($this->host);

    Http::fake(['graph.microsoft.com/*' => Http::response(['id' => 'graph-event-4'])]);

    (new SyncBookingToCalendars($booking))->handle(app(CalendarProviderManager::class));

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['start'] === ['dateTime' => '2026-10-27T11:00:00', 'timeZone' => 'America/Chicago']
        && $request['end'] === ['dateTime' => '2026-10-27T11:30:00', 'timeZone' => 'America/Chicago']);
});

test('a phone booking does not ask the provider for a meeting link', function () {
    connectedAccount($this->host, CalendarProvider::Microsoft);

    $eventType = EventType::factory()->ownedBy($this->host)->create([
        'location_type' => LocationType::Phone,
    ]);

    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $eventType->team_id,
        'location_type' => LocationType::Phone,
        'location_detail' => '+1 555 0100',
    ]);
    $booking->hosts()->attach($this->host);

    Http::fake(['graph.microsoft.com/*' => Http::response(['id' => 'graph-event-2'])]);

    (new SyncBookingToCalendars($booking))->handle(app(CalendarProviderManager::class));

    expect($booking->fresh()->meeting_url)->toBeNull();

    Http::assertSent(fn ($request) => ! isset($request['isOnlineMeeting'])
        && $request['location']['displayName'] === '+1 555 0100');
});

test('a failing calendar write is recorded without losing the booking', function () {
    $account = connectedAccount($this->host, CalendarProvider::Google);

    $eventType = EventType::factory()->ownedBy($this->host)->create();
    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $eventType->team_id,
    ]);
    $booking->hosts()->attach($this->host);

    Http::fake(['www.googleapis.com/*' => Http::response(['error' => 'nope'], 500)]);

    (new SyncBookingToCalendars($booking))->handle(app(CalendarProviderManager::class));

    expect($account->fresh()->sync_error)->not->toBeNull()
        ->and($booking->fresh()->calendarEvents)->toHaveCount(0)
        ->and($booking->fresh()->status->isActive())->toBeTrue();
});

test('canceling a booking removes the external event', function () {
    $account = connectedAccount($this->host, CalendarProvider::Google);

    $eventType = EventType::factory()->ownedBy($this->host)->create();
    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $eventType->team_id,
    ]);

    $booking->calendarEvents()->create([
        'calendar_account_id' => $account->id,
        'external_id' => 'google-event-1',
        'external_calendar_id' => 'primary-calendar',
    ]);

    Http::fake(['www.googleapis.com/*' => Http::response([], 204)]);

    (new RemoveBookingFromCalendars($booking))->handle(app(CalendarProviderManager::class));

    expect($booking->fresh()->calendarEvents)->toHaveCount(0);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), 'google-event-1'));
});

test('an already deleted external event is treated as success', function () {
    $account = connectedAccount($this->host, CalendarProvider::Google);

    $eventType = EventType::factory()->ownedBy($this->host)->create();
    $booking = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $eventType->team_id,
    ]);

    $booking->calendarEvents()->create([
        'calendar_account_id' => $account->id,
        'external_id' => 'missing-event',
        'external_calendar_id' => 'primary-calendar',
    ]);

    Http::fake(['www.googleapis.com/*' => Http::response([], 404)]);

    (new RemoveBookingFromCalendars($booking))->handle(app(CalendarProviderManager::class));

    expect($booking->fresh()->calendarEvents)->toHaveCount(0);
});

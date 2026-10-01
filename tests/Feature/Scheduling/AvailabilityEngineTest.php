<?php

use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\BusyBlock;
use App\Models\CalendarAccount;
use App\Models\EventType;
use App\Models\User;
use App\Services\Scheduling\AvailabilityEngine;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;

/**
 * Build an owner with weekday 09:00-17:00 hours in the given timezone.
 */
function scheduledHost(string $timezone = 'UTC'): User
{
    $user = User::factory()->create(['timezone' => $timezone]);

    AvailabilitySchedule::factory()
        ->for($user)
        ->timezone($timezone)
        ->weekdays()
        ->create();

    return $user;
}

function engine(): AvailabilityEngine
{
    return app(AvailabilityEngine::class);
}

/**
 * A window covering a single Wednesday.
 */
function wednesday(): TimeRange
{
    $day = CarbonImmutable::parse('2026-09-02', 'UTC');

    return new TimeRange($day->startOfDay(), $day->endOfDay());
}

beforeEach(function () {
    // A Monday, well before the window under test.
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));
});

test('slots are generated across the schedule working hours', function () {
    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);

    $slots = engine()->slots($eventType, wednesday());

    expect($slots)->toHaveCount(8)
        ->and($slots->first()->startsAt->toTimeString())->toBe('09:00:00')
        ->and($slots->last()->startsAt->toTimeString())->toBe('16:00:00')
        ->and($slots->last()->endsAt->toTimeString())->toBe('17:00:00');
});

test('the slot interval controls how often a meeting may start', function () {
    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create([
        'duration_minutes' => 60,
        'slot_interval_minutes' => 30,
    ]);

    $slots = engine()->slots($eventType, wednesday());

    // 09:00 through 16:00 every half hour.
    expect($slots)->toHaveCount(15)
        ->and($slots[1]->startsAt->toTimeString())->toBe('09:30:00');
});

test('working hours are interpreted in the schedule timezone', function () {
    $host = scheduledHost('America/Chicago');
    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);

    $slots = engine()->slots($eventType, wednesday());

    // 09:00 in Chicago (CDT, UTC-5) is 14:00 UTC.
    expect($slots->first()->startsAt->toTimeString())->toBe('14:00:00');
});

test('an existing booking removes the slot it occupies', function () {
    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $host->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC'),
    ]);

    $starts = engine()->slots($eventType, wednesday())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts)->not->toContain('11:00:00')
        ->and($starts)->toContain('10:00:00', '12:00:00');
});

test('a pending booking removes the slot it occupies', function () {
    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);

    Booking::factory()->pending()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $host->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC'),
    ]);

    $starts = engine()->slots($eventType, wednesday())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts)->not->toContain('11:00:00')
        ->and($starts)->toContain('10:00:00', '12:00:00');
});

test('a canceled booking frees its slot again', function () {
    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);

    Booking::factory()->canceled()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $host->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC'),
    ]);

    $starts = engine()->slots($eventType, wednesday())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts)->toContain('11:00:00');
});

test('buffers keep neighbouring slots clear', function () {
    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create([
        'duration_minutes' => 60,
        'buffer_before_minutes' => 30,
        'buffer_after_minutes' => 30,
    ]);

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $host->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 13:00:00', 'UTC'),
    ]);

    $starts = engine()->slots($eventType, wednesday())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    // The booking blocks 11:30-13:30, so 11:00 through 13:00 are gone. Start
    // times stay on the 09:00 grid, so the next one offered is 14:00.
    expect($starts)->not->toContain('11:00:00', '12:00:00', '13:00:00', '13:30:00')
        ->and($starts)->toContain('10:00:00', '14:00:00');
});

test('external busy blocks remove slots', function () {
    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);

    $account = CalendarAccount::factory()->for($host)->create();
    $calendar = $account->calendars()->create([
        'external_id' => 'primary',
        'name' => 'Primary',
        'is_primary' => true,
        'checks_conflicts' => true,
    ]);

    BusyBlock::create([
        'calendar_id' => $calendar->id,
        'user_id' => $host->id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 14:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 15:00:00', 'UTC'),
    ]);

    $starts = engine()->slots($eventType, wednesday())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts)->not->toContain('14:00:00')
        ->and($starts)->toContain('13:00:00', '15:00:00');
});

test('busy blocks on calendars that do not check conflicts are ignored', function () {
    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);

    $account = CalendarAccount::factory()->for($host)->create();
    $calendar = $account->calendars()->create([
        'external_id' => 'other',
        'name' => 'Birthdays',
        'checks_conflicts' => false,
    ]);

    BusyBlock::create([
        'calendar_id' => $calendar->id,
        'user_id' => $host->id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 14:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 15:00:00', 'UTC'),
    ]);

    $starts = engine()->slots($eventType, wednesday())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts)->toContain('14:00:00');
});

test('minimum notice hides slots that are too soon', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-02 09:00:00', 'UTC'));

    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create([
        'duration_minutes' => 60,
        'minimum_notice_minutes' => 240,
    ]);

    $starts = engine()->slots($eventType, wednesday())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts)->not->toContain('10:00:00', '12:00:00')
        ->and($starts)->toContain('13:00:00');
});

test('the rest of today is bookable straight away from the host hours', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-02 10:10:00', 'UTC'));

    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 30]);

    $starts = engine()->slots($eventType, wednesday())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts->first())->toBe('10:30:00')
        ->and($starts->last())->toBe('16:30:00');
});

test('a date override closes the day', function () {
    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);

    $host->defaultAvailabilitySchedule()->overrides()->create([
        'date' => '2026-09-02',
        'is_unavailable' => true,
    ]);

    expect(engine()->slots($eventType, wednesday()))->toHaveCount(0);
});

test('a date override can replace the hours for a day', function () {
    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);

    $host->defaultAvailabilitySchedule()->overrides()->create([
        'date' => '2026-09-02',
        'starts_at' => '13:00:00',
        'ends_at' => '15:00:00',
    ]);

    $starts = engine()->slots($eventType, wednesday())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts)->toHaveCount(2)
        ->and($starts->all())->toBe(['13:00:00', '14:00:00']);
});

test('the daily booking limit closes the whole day once reached', function () {
    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create([
        'duration_minutes' => 60,
        'daily_booking_limit' => 1,
    ]);

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $host->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
    ]);

    expect(engine()->slots($eventType, wednesday()))->toHaveCount(0);
});

test('slots stay on the schedule grid rather than starting at the notice cutoff', function () {
    // 09:26 is deliberately ragged: with four hours notice the earliest
    // bookable moment is 13:26, which must not become a slot start.
    $this->travelTo(CarbonImmutable::parse('2026-09-02 09:26:00', 'UTC'));

    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create([
        'duration_minutes' => 30,
        'minimum_notice_minutes' => 240,
    ]);

    $starts = engine()->slots($eventType, wednesday())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts->first())->toBe('13:30:00')
        ->and($starts->all())->each->toMatch('/:(00|30):00$/');
});

test('slots align to the working window start even when it is not on the hour', function () {
    $host = User::factory()->create();

    AvailabilitySchedule::factory()
        ->for($host)
        ->everyDay('09:15:00', '12:15:00')
        ->create();

    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);

    $starts = engine()->slots($eventType, wednesday())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts->all())->toBe(['09:15:00', '10:15:00', '11:15:00']);
});

test('a booking on a deleted event type still blocks its host', function () {
    $host = scheduledHost();
    $eventType = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);
    $deleted = EventType::factory()->ownedBy($host)->create(['duration_minutes' => 60]);

    Booking::factory()->create([
        'event_type_id' => $deleted->id,
        'user_id' => $host->id,
        'team_id' => $deleted->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
    ]);

    $deleted->delete();

    $starts = engine()->slots($eventType, wednesday())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts)->toHaveCount(7)
        ->and($starts)->not->toContain('10:00:00');
});

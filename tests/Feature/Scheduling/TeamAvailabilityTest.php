<?php

use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Services\Scheduling\AvailabilityEngine;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;

/**
 * Build a host whose weekly hours run between the two given wall clock times.
 */
function hostWorking(string $startsAt, string $endsAt): User
{
    $user = User::factory()->create();

    AvailabilitySchedule::factory()
        ->for($user)
        ->everyDay($startsAt, $endsAt)
        ->create();

    return $user;
}

function day(): TimeRange
{
    $date = CarbonImmutable::parse('2026-09-02', 'UTC');

    return new TimeRange($date->startOfDay(), $date->endOfDay());
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));
});

test('a round robin offers the union of its hosts hours', function () {
    $owner = hostWorking('09:00:00', '11:00:00');
    $second = hostWorking('14:00:00', '16:00:00');

    $eventType = EventType::factory()->ownedBy($owner)->roundRobin()->create(['duration_minutes' => 60]);
    $eventType->hosts()->attach([$owner->id, $second->id]);

    $starts = app(AvailabilityEngine::class)->slots($eventType->fresh(), day())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts->all())->toBe(['09:00:00', '10:00:00', '14:00:00', '15:00:00']);
});

test('a round robin slot records every host that is free for it', function () {
    $owner = hostWorking('09:00:00', '11:00:00');
    $second = hostWorking('09:00:00', '11:00:00');

    $eventType = EventType::factory()->ownedBy($owner)->roundRobin()->create(['duration_minutes' => 60]);
    $eventType->hosts()->attach([$owner->id, $second->id]);

    $slot = app(AvailabilityEngine::class)->slots($eventType->fresh(), day())->first();

    expect($slot->hostIds)->toHaveCount(2)
        ->and($slot->hostIds)->toContain($owner->id, $second->id);
});

test('a round robin still offers a slot when only one host is busy', function () {
    $owner = hostWorking('09:00:00', '11:00:00');
    $second = hostWorking('09:00:00', '11:00:00');

    $eventType = EventType::factory()->ownedBy($owner)->roundRobin()->create(['duration_minutes' => 60]);
    $eventType->hosts()->attach([$owner->id, $second->id]);

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $owner->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
    ]);

    $slot = app(AvailabilityEngine::class)->slots($eventType->fresh(), day())->first();

    expect($slot->startsAt->toTimeString())->toBe('09:00:00')
        ->and($slot->hostIds)->toBe([$second->id]);
});

test('a collective event only offers hours every host shares', function () {
    $owner = hostWorking('09:00:00', '13:00:00');
    $second = hostWorking('11:00:00', '17:00:00');

    $eventType = EventType::factory()->ownedBy($owner)->collective()->create(['duration_minutes' => 60]);
    $eventType->hosts()->attach([$owner->id, $second->id]);

    $starts = app(AvailabilityEngine::class)->slots($eventType->fresh(), day())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts->all())->toBe(['11:00:00', '12:00:00']);
});

test('a collective event drops a slot when any host is busy', function () {
    $owner = hostWorking('09:00:00', '12:00:00');
    $second = hostWorking('09:00:00', '12:00:00');

    $eventType = EventType::factory()->ownedBy($owner)->collective()->create(['duration_minutes' => 60]);
    $eventType->hosts()->attach([$owner->id, $second->id]);

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $second->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
    ]);

    $starts = app(AvailabilityEngine::class)->slots($eventType->fresh(), day())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts->all())->toBe(['09:00:00', '11:00:00']);
});

test('a group event keeps offering a slot while seats remain', function () {
    $owner = hostWorking('09:00:00', '11:00:00');

    $eventType = EventType::factory()->ownedBy($owner)->group(seats: 3)->create(['duration_minutes' => 60]);

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $owner->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
    ]);

    $slot = app(AvailabilityEngine::class)->slots($eventType->fresh(), day())->first();

    expect($slot->startsAt->toTimeString())->toBe('09:00:00')
        ->and($slot->seatsRemaining)->toBe(2);
});

test('a group event drops a slot once every seat is taken', function () {
    $owner = hostWorking('09:00:00', '11:00:00');

    $eventType = EventType::factory()->ownedBy($owner)->group(seats: 2)->create(['duration_minutes' => 60]);

    Booking::factory()->count(2)->create([
        'event_type_id' => $eventType->id,
        'user_id' => $owner->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
    ]);

    $starts = app(AvailabilityEngine::class)->slots($eventType->fresh(), day())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts->all())->toBe(['10:00:00']);
});

test('a group booking still blocks the host on other event types', function () {
    $owner = hostWorking('09:00:00', '11:00:00');

    $group = EventType::factory()->ownedBy($owner)->group(seats: 5)->create(['duration_minutes' => 60]);
    $other = EventType::factory()->ownedBy($owner)->create(['duration_minutes' => 60]);

    Booking::factory()->create([
        'event_type_id' => $group->id,
        'user_id' => $owner->id,
        'team_id' => $group->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
    ]);

    $starts = app(AvailabilityEngine::class)->slots($other->fresh(), day())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts->all())->toBe(['10:00:00']);
});

test('a group event does not offer a start that overlaps a session booked at another time', function () {
    $owner = hostWorking('09:00:00', '13:00:00');

    $eventType = EventType::factory()->ownedBy($owner)->group(seats: 5)->create([
        'duration_minutes' => 60,
        'slot_interval_minutes' => 30,
    ]);

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $owner->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
    ]);

    $starts = app(AvailabilityEngine::class)->slots($eventType->fresh(), day())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts->all())->toBe(['09:00:00', '10:00:00', '11:00:00', '11:30:00', '12:00:00']);
});

test('a group event keeps its buffers around a session booked at another time', function () {
    $owner = hostWorking('09:00:00', '13:00:00');

    $eventType = EventType::factory()->ownedBy($owner)->group(seats: 5)->create([
        'duration_minutes' => 60,
        'buffer_after_minutes' => 30,
    ]);

    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'user_id' => $owner->id,
        'team_id' => $eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
    ]);

    $starts = app(AvailabilityEngine::class)->slots($eventType->fresh(), day())
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    expect($starts->all())->toBe(['09:00:00', '10:00:00', '12:00:00']);
});

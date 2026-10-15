<?php

use App\Actions\Bookings\AssignHosts;
use App\Actions\Scheduling\SaveEventType;
use App\Exceptions\SlotUnavailableException;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Group;
use App\Models\HostZone;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));
});

/**
 * Build a host with ordinary weekly hours.
 */
function priorityHost(): User
{
    $user = User::factory()->create();

    AvailabilitySchedule::factory()
        ->for($user)
        ->everyDay('09:00:00', '17:00:00')
        ->create();

    return $user;
}

test('the highest priority host takes the booking', function () {
    $first = priorityHost();
    $second = priorityHost();
    $third = priorityHost();

    $eventType = EventType::factory()->ownedBy($first)->roundRobin()->create();

    // Priority ascends with position: 0 is offered the slot first.
    $eventType->hosts()->attach([
        $second->id => ['priority' => 0],
        $third->id => ['priority' => 1],
        $first->id => ['priority' => 2],
    ]);

    $chosen = app(AssignHosts::class)
        ->handle($eventType->fresh(), [$first->id, $second->id, $third->id]);

    expect($chosen)->toHaveCount(1)
        ->and($chosen->first()->id)->toBe($second->id);
});

test('the next priority down takes the slot when the top host is busy', function () {
    $top = priorityHost();
    $backup = priorityHost();

    $eventType = EventType::factory()->ownedBy($top)->roundRobin()->create();

    $eventType->hosts()->attach([
        $top->id => ['priority' => 0],
        $backup->id => ['priority' => 1],
    ]);

    // The top host is not among the ids available for this slot.
    $chosen = app(AssignHosts::class)->handle($eventType->fresh(), [$backup->id]);

    expect($chosen->first()->id)->toBe($backup->id);
});

test('priority beats load, so the top host keeps taking bookings', function () {
    $top = priorityHost();
    $backup = priorityHost();

    $eventType = EventType::factory()->ownedBy($top)->roundRobin()->create();

    $eventType->hosts()->attach([
        $top->id => ['priority' => 0],
        $backup->id => ['priority' => 1],
    ]);

    // Give the top host a heavier upcoming load than the backup.
    Booking::factory()->count(3)->create([
        'event_type_id' => $eventType->id,
        'team_id' => $eventType->team_id,
        'user_id' => $top->id,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addMinutes(30),
    ]);

    $chosen = app(AssignHosts::class)
        ->handle($eventType->fresh(), [$top->id, $backup->id]);

    expect($chosen->first()->id)->toBe($top->id);
});

test('hosts sharing a priority still balance on load', function () {
    $busy = priorityHost();
    $quiet = priorityHost();

    $eventType = EventType::factory()->ownedBy($busy)->roundRobin()->create();

    $eventType->hosts()->attach([
        $busy->id => ['priority' => 0],
        $quiet->id => ['priority' => 0],
    ]);

    Booking::factory()->count(2)->create([
        'event_type_id' => $eventType->id,
        'team_id' => $eventType->team_id,
        'user_id' => $busy->id,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addMinutes(30),
    ]);

    $chosen = app(AssignHosts::class)
        ->handle($eventType->fresh(), [$busy->id, $quiet->id]);

    expect($chosen->first()->id)->toBe($quiet->id);
});

test('saving an event type stores host priority in the submitted order', function () {
    $owner = priorityHost();
    $second = priorityHost();
    $third = priorityHost();

    $team = Team::factory()->create();

    foreach ([$owner, $second, $third] as $member) {
        $team->members()->attach($member, ['role' => 'member']);
    }

    $eventType = app(SaveEventType::class)->handle($owner, $team, [
        'kind' => 'round_robin',
        'name' => 'Leasing call',
        'slug' => 'leasing-call',
        'duration_minutes' => 30,
        'location_type' => 'phone',
        'host_ids' => [$third->id, $owner->id, $second->id],
    ]);

    $priorities = $eventType->hosts()
        ->get()
        ->mapWithKeys(fn (User $host) => [$host->id => $host->pivot->priority]);

    expect($priorities[$third->id])->toBe(0)
        ->and($priorities[$owner->id])->toBe(1)
        ->and($priorities[$second->id])->toBe(2);

    // The relation reads back in priority order, which is what the pool uses.
    expect($eventType->hosts()->get()->pluck('id')->all())
        ->toBe([$third->id, $owner->id, $second->id]);
});

test('a group host pool is offered in its own priority order', function () {
    $first = priorityHost();
    $second = priorityHost();

    $team = Team::factory()->create();
    $group = Group::factory()->create(['team_id' => $team->id]);

    $group->members()->attach([
        $second->id => ['priority' => 0],
        $first->id => ['priority' => 1],
    ]);

    $eventType = EventType::factory()
        ->ownedBy($first)
        ->roundRobin()
        ->create(['team_id' => $team->id, 'group_id' => $group->id]);

    $chosen = app(AssignHosts::class)
        ->handle($eventType->fresh(), [$first->id, $second->id]);

    expect($chosen->first()->id)->toBe($second->id);
});

test('a zone keeps the slot to the hosts covering it, in their priority order', function () {
    $first = priorityHost();
    $second = priorityHost();
    $third = priorityHost();

    $eventType = EventType::factory()->ownedBy($first)->roundRobin()->create();

    $eventType->hosts()->attach([
        $first->id => ['priority' => 0],
        $second->id => ['priority' => 1],
        $third->id => ['priority' => 2],
    ]);

    foreach ([$second, $third] as $host) {
        HostZone::query()->create(['team_id' => $eventType->team_id, 'user_id' => $host->id, 'zone' => 'zone-a']);
    }

    $chosen = app(AssignHosts::class)
        ->handle($eventType->fresh(), [$first->id, $second->id, $third->id], 'zone-a');

    expect($chosen->first()->id)->toBe($second->id);
});

test('a zone nobody covers changes nothing', function () {
    $first = priorityHost();
    $second = priorityHost();

    $eventType = EventType::factory()->ownedBy($first)->roundRobin()->create();

    $eventType->hosts()->attach([
        $first->id => ['priority' => 0],
        $second->id => ['priority' => 1],
    ]);

    $chosen = app(AssignHosts::class)->handle($eventType->fresh(), [$first->id, $second->id], 'zone-z');

    expect($chosen->first()->id)->toBe($first->id);
});

test('the owner is not the fallback for a zone somebody covers', function () {
    $owner = priorityHost();
    $covering = priorityHost();

    $eventType = EventType::factory()->ownedBy($owner)->roundRobin()->create();

    $eventType->hosts()->attach([
        $owner->id => ['priority' => 0],
        $covering->id => ['priority' => 1],
    ]);

    HostZone::query()->create(['team_id' => $eventType->team_id, 'user_id' => $covering->id, 'zone' => 'zone-a']);

    // Only the owner is free, and the owner does not cover the zone.
    expect(fn () => app(AssignHosts::class)->handle($eventType->fresh(), [$owner->id], 'zone-a'))
        ->toThrow(SlotUnavailableException::class);
});

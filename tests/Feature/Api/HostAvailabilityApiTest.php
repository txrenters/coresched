<?php

use App\Enums\TeamRole;
use App\Models\ApiKey;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;

/**
 * The hours each host of a shared meeting keeps, for another application to
 * show: an agent's own hours on their settings page, and who covers which
 * part of the week for an administrator.
 */
beforeEach(function () {
    // A Monday at noon UTC, so "tomorrow" is a working Tuesday.
    $this->travelTo('2026-10-05 12:00:00');

    $this->owner = User::factory()->create(['name' => 'Dana Fields', 'email' => 'dana@texra.net']);
    $this->team = $this->owner->currentTeam;

    $this->other = User::factory()->create(['name' => 'Jose Ruiz', 'email' => 'jose@texra.net']);
    $this->team->members()->attach($this->other, ['role' => TeamRole::Member->value]);

    ['token' => $this->token] = ApiKey::issue('TEXRA', null, $this->team);

    $this->schedule = AvailabilitySchedule::factory()->for($this->owner)
        ->timezone('America/Chicago')->weekdays()->create(['name' => 'Working hours']);
    AvailabilitySchedule::factory()->for($this->other)->timezone('UTC')->weekdays('10:00:00', '12:00:00')->create();

    $this->eventType = EventType::factory()->ownedBy($this->owner)->roundRobin()->create([
        'name' => 'Call with an agent',
        'slug' => 'call-with-an-agent',
        'is_active' => true,
    ]);
    $this->eventType->hosts()->sync([
        $this->owner->id => ['priority' => 0],
        $this->other->id => ['priority' => 1],
    ]);
});

/**
 * @param  array<string, mixed>  $query
 */
function hostHours(array $query = [], ?string $token = null)
{
    return test()->getJson(route('api.v1.hosts.index', [
        'page' => test()->team->slug,
        'eventType' => 'call-with-an-agent',
        'from' => '2026-10-06',
        'to' => '2026-10-06',
        ...$query,
    ]), ['Authorization' => 'Bearer '.($token ?? test()->token)]);
}

test('host hours are refused without a token', function () {
    $this->getJson(route('api.v1.hosts.index', [
        'page' => $this->team->slug, 'eventType' => 'call-with-an-agent', 'from' => '2026-10-06', 'to' => '2026-10-06',
    ]))->assertUnauthorized();
});

test('an organization key cannot read another organization hosts', function () {
    ['token' => $theirs] = ApiKey::issue('Someone else', null, Team::factory()->create());

    hostHours(token: $theirs)->assertNotFound();
});

test('an unknown event type has no host hours', function () {
    hostHours(['eventType' => 'nothing-here'])->assertNotFound();
});

test('it lists every host in the pool with the weekly hours they set', function () {
    $response = hostHours()->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.name'))->toBe('Dana Fields')
        ->and($response->json('data.0.email'))->toBe('dana@texra.net')
        ->and($response->json('data.0.schedule.name'))->toBe('Working hours')
        ->and($response->json('data.0.schedule.kind'))->toBe('personal')
        ->and($response->json('data.0.schedule.timezone'))->toBe('America/Chicago')
        // Wall clock, as the host typed it: Monday to Friday, nine to five.
        ->and($response->json('data.0.schedule.weekly'))->toHaveCount(5)
        ->and($response->json('data.0.schedule.weekly.0'))->toBe(['dayOfWeek' => 1, 'startsAt' => '09:00', 'endsAt' => '17:00'])
        ->and($response->json('data.1.email'))->toBe('jose@texra.net')
        ->and($response->json('meta.eventType'))->toBe('call-with-an-agent')
        ->and($response->json('meta.eventName'))->toBe('Call with an agent')
        ->and($response->json('meta.durationMinutes'))->toBe(30)
        ->and($response->json('meta.availabilityUrl'))->toBe(url("/{$this->team->slug}/availability"));
});

test('it reports working windows in UTC, expanded from the host own timezone', function () {
    $response = hostHours()->assertOk();

    // Nine to five in Chicago is 14:00 to 22:00 UTC in October.
    expect($response->json('data.0.windows'))->toBe([
        ['startsAt' => '2026-10-06T14:00:00+00:00', 'endsAt' => '2026-10-06T22:00:00+00:00'],
    ])->and($response->json('data.1.windows'))->toBe([
        ['startsAt' => '2026-10-06T10:00:00+00:00', 'endsAt' => '2026-10-06T12:00:00+00:00'],
    ]);
});

test('free time leaves out a booked meeting while the working window keeps it', function () {
    Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->owner->id,
        'starts_at' => '2026-10-06 15:00:00',
        'ends_at' => '2026-10-06 15:30:00',
    ]);

    $response = hostHours()->assertOk();

    expect($response->json('data.0.windows'))->toHaveCount(1)
        ->and($response->json('data.0.free'))->toBe([
            ['startsAt' => '2026-10-06T14:00:00+00:00', 'endsAt' => '2026-10-06T15:00:00+00:00'],
            ['startsAt' => '2026-10-06T15:30:00+00:00', 'endsAt' => '2026-10-06T22:00:00+00:00'],
        ]);
});

test('a day the host marked unavailable has no window and is listed as an override', function () {
    $this->schedule->overrides()->create([
        'date' => '2026-10-06',
        'is_unavailable' => true,
        'starts_at' => null,
        'ends_at' => null,
    ]);
    // Already past: not worth sending.
    $this->schedule->overrides()->create([
        'date' => '2026-09-01',
        'is_unavailable' => true,
        'starts_at' => null,
        'ends_at' => null,
    ]);

    $response = hostHours()->assertOk();

    expect($response->json('data.0.windows'))->toBe([])
        ->and($response->json('data.0.free'))->toBe([])
        ->and($response->json('data.0.schedule.overrides'))->toBe([
            ['date' => '2026-10-06', 'isUnavailable' => true, 'startsAt' => null, 'endsAt' => null],
        ]);
});

test('a host with no working hours is still listed, with nothing to offer', function () {
    AvailabilitySchedule::query()->where('user_id', $this->other->id)->delete();

    $response = hostHours()->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.1.email'))->toBe('jose@texra.net')
        ->and($response->json('data.1.schedule'))->toBeNull()
        ->and($response->json('data.1.windows'))->toBe([])
        ->and($response->json('data.1.free'))->toBe([]);
});

test('an email narrows the answer to one host, whatever its case', function () {
    expect(hostHours(['email' => 'JOSE@texra.net'])->assertOk()->json('data'))->toHaveCount(1)
        ->and(hostHours(['email' => 'JOSE@texra.net'])->json('data.0.name'))->toBe('Jose Ruiz')
        ->and(hostHours(['email' => 'nobody@texra.net'])->assertOk()->json('data'))->toBe([]);
});

test('host hours refuse a window longer than a month', function () {
    hostHours(['to' => '2026-12-06'])->assertStatus(422);
});

test('a host booked for their whole day is still on duty, with no free time', function () {
    Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->other->id,
        'starts_at' => '2026-10-06 10:00:00',
        'ends_at' => '2026-10-06 12:00:00',
    ]);

    $response = hostHours()->assertOk();

    expect($response->json('data.1.windows'))->toHaveCount(1)
        ->and($response->json('data.1.free'))->toBe([]);
});

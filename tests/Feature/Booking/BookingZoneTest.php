<?php

use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Group;
use App\Models\HostZone;
use App\Models\User;
use App\Services\Scheduling\AvailabilityEngine;
use App\Services\Webhooks\BookingWebhookPayload;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * A link from TEXRA can name the zone the renter is in. The times offered
 * and the host booked are then kept to the hosts who cover that zone; a
 * zone nobody lists changes nothing, so a new area still books.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));

    $this->dana = User::factory()->create(['name' => 'Dana Reed', 'email' => 'dana@example.com', 'timezone' => 'UTC']);
    $this->team = $this->dana->currentTeam;
    $this->jose = User::factory()->create(['name' => 'Jose Ortiz', 'email' => 'jose@example.com', 'timezone' => 'UTC']);

    // Dana works weekdays only; Jose every day.
    AvailabilitySchedule::factory()->for($this->dana)->weekdays()->create();
    AvailabilitySchedule::factory()->for($this->jose)->everyDay()->create();

    $this->group = Group::factory()->create(['team_id' => $this->team->id, 'name' => 'Agents on Duty']);

    // Jose is first in line, so only a zone can send a booking to Dana.
    $this->group->members()->attach([
        $this->jose->id => ['priority' => 0],
        $this->dana->id => ['priority' => 1],
    ]);

    $this->eventType = EventType::factory()->ownedBy($this->dana)->roundRobin()->create([
        'name' => 'Call with an agent',
        'slug' => 'call',
        'team_id' => $this->team->id,
        'group_id' => $this->group->id,
        'duration_minutes' => 60,
    ]);

    HostZone::query()->create(['team_id' => $this->team->id, 'user_id' => $this->dana->id, 'zone' => 'zone-a']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function zonedBooking(array $overrides = []): array
{
    return [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Rae Renter',
        'email' => 'rae@example.com',
        'notes' => 'Nothing to add.',
        'reference' => 'lead-token-123',
        'zone' => 'zone-a',
        ...$overrides,
    ];
}

function zoneWednesday(): TimeRange
{
    $day = CarbonImmutable::parse('2026-09-02', 'UTC');

    return new TimeRange($day->startOfDay(), $day->endOfDay());
}

test('the booking page keeps the zone the link named, and drops a malformed one', function () {
    $this->get(route('book.event-type', ['page' => $this->team->slug, 'eventType' => 'call', 'zone' => ' Zone-A ']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('prefill.zone', 'zone-a'));

    $this->get(route('book.event-type', ['page' => $this->team->slug, 'eventType' => 'call', 'zone' => 'not a zone!']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('prefill.zone', null));
});

test('a zone offers only the times of the hosts who cover it', function () {
    $engine = app(AvailabilityEngine::class);

    $everyone = $engine->slots($this->eventType, zoneWednesday());
    $zoned = $engine->slots($this->eventType, zoneWednesday(), zone: 'zone-a');

    expect($everyone->first()->hostIds)->toEqualCanonicalizing([$this->dana->id, $this->jose->id])
        ->and($zoned)->not->toBeEmpty()
        ->and($zoned->every(fn ($slot): bool => $slot->hostIds === [$this->dana->id]))->toBeTrue();
});

test('a zone nobody lists offers the whole pool', function () {
    $slots = app(AvailabilityEngine::class)->slots($this->eventType, zoneWednesday(), zone: 'zone-z');

    expect($slots->first()->hostIds)->toEqualCanonicalizing([$this->dana->id, $this->jose->id]);
});

test('a booking from a zoned link goes to a host covering the zone and remembers it', function () {
    Notification::fake();

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), zonedBooking())
        ->assertSessionHasNoErrors();

    $booking = Booking::first();

    expect($booking->user_id)->toBe($this->dana->id)
        ->and($booking->zone)->toBe('zone-a')
        ->and($booking->reference)->toBe('lead-token-123');
});

test('without a zone the first in line takes the booking', function () {
    Notification::fake();

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), zonedBooking(['zone' => null]))
        ->assertSessionHasNoErrors();

    expect(Booking::first()->user_id)->toBe($this->jose->id)
        ->and(Booking::first()->zone)->toBeNull();
});

test('a zoned booking is refused when nobody covering the zone is free, even if others are', function () {
    Notification::fake();

    // A Saturday: Jose works, Dana does not.
    $saturday = CarbonImmutable::parse('2026-09-05 10:00:00', 'UTC')->toIso8601String();

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), zonedBooking(['starts_at' => $saturday]))
        ->assertSessionHasErrors('starts_at');

    expect(Booking::count())->toBe(0);

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), zonedBooking(['starts_at' => $saturday, 'zone' => null]))
        ->assertSessionHasNoErrors();

    expect(Booking::first()->user_id)->toBe($this->jose->id);
});

test('a reschedule stays in the zone', function () {
    Notification::fake();

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), zonedBooking())
        ->assertSessionHasNoErrors();

    $booking = Booking::first();

    $this->post(route('booking.reschedule.store', ['booking' => $booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-03 10:00:00', 'UTC')->toIso8601String(),
    ])->assertSessionHasNoErrors();

    $replacement = Booking::query()->where('rescheduled_from_id', $booking->id)->first();

    expect($replacement->zone)->toBe('zone-a')
        ->and($replacement->user_id)->toBe($this->dana->id);
});

test('the webhook reports the zone with the reference', function () {
    Notification::fake();

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), zonedBooking())
        ->assertSessionHasNoErrors();

    $payload = app(BookingWebhookPayload::class)->build(Booking::first(), 'booking.confirmed', 1, 'delivery-1', CarbonImmutable::now());

    expect($payload['booking']['zone'])->toBe('zone-a')
        ->and($payload['booking']['reference'])->toBe('lead-token-123');
});

test('a malformed zone on the booking itself is refused', function () {
    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), zonedBooking(['zone' => 'Zone A!']))
        ->assertSessionHasErrors('zone');
});

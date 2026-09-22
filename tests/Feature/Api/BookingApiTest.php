<?php

use App\Models\ApiKey;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;

beforeEach(function () {
    // Keys live in the database now, issued from settings.
    ['token' => $this->token] = ApiKey::issue('Client portal');

    $this->owner = User::factory()->create(['name' => 'Texas Renters']);

    // Without working hours the engine has nothing to offer, so every slot
    // test would pass vacuously.
    AvailabilitySchedule::factory()->for($this->owner)->timezone('UTC')->weekdays()->create();

    $this->eventType = EventType::factory()->ownedBy($this->owner)->create([
        'name' => 'Owner meeting',
        'slug' => 'owner-meeting',
        'is_active' => true,
    ]);
});

function withToken(?string $token = null): array
{
    return ['Authorization' => 'Bearer '.($token ?? test()->token)];
}

test('the api refuses a request without the token', function () {
    $this->getJson(route('api.v1.event-types.index', ['page' => $this->owner->booking_slug]))
        ->assertUnauthorized();
});

test('the api refuses a wrong token', function () {
    $this->getJson(
        route('api.v1.event-types.index', ['page' => $this->owner->booking_slug]),
        withToken('not-the-token'),
    )->assertUnauthorized();
});

test('a revoked key stops working immediately', function () {
    ApiKey::query()->update(['revoked_at' => now()]);

    $this->getJson(
        route('api.v1.event-types.index', ['page' => $this->owner->booking_slug]),
        withToken(),
    )->assertUnauthorized();
});

test('using a key records when it was last used', function () {
    $this->getJson(
        route('api.v1.event-types.index', ['page' => $this->owner->booking_slug]),
        withToken(),
    )->assertOk();

    expect(ApiKey::query()->first()->last_used_at)->not->toBeNull();
});

test('the secret itself is never stored', function () {
    [, $secret] = explode('.', $this->token, 2);

    // Only a hash is kept, so a leaked database is not a leaked key.
    expect(ApiKey::query()->first()->token_hash)->not->toContain($secret);
});

test('it lists the meetings a page offers', function () {
    $this->getJson(
        route('api.v1.event-types.index', ['page' => $this->owner->booking_slug]),
        withToken(),
    )
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'owner-meeting')
        ->assertJsonPath('data.0.name', 'Owner meeting')
        ->assertJsonPath('data.0.durationMinutes', 30)
        ->assertJsonPath('meta.page', $this->owner->booking_slug);
});

test('an unknown page is not found', function () {
    $this->getJson(route('api.v1.event-types.index', ['page' => 'nobody']), withToken())
        ->assertNotFound();
});

test('it returns bookable slots for an event type', function () {
    $response = $this->getJson(route('api.v1.slots.index', [
        'page' => $this->owner->booking_slug,
        'eventType' => 'owner-meeting',
        'from' => now()->addDay()->toDateString(),
        'to' => now()->addDays(3)->toDateString(),
    ]), withToken())->assertOk();

    expect($response->json('meta.durationMinutes'))->toBe(30)
        ->and($response->json('data'))->toBeArray();
});

test('it refuses a window longer than two months', function () {
    $this->getJson(route('api.v1.slots.index', [
        'page' => $this->owner->booking_slug,
        'eventType' => 'owner-meeting',
        'from' => now()->toDateString(),
        'to' => now()->addMonths(6)->toDateString(),
    ]), withToken())->assertStatus(422);
});

test('it lists someone upcoming meetings by email', function () {
    $mine = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'email' => 'owner@example.com',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addMinutes(30),
    ]);

    Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'email' => 'someone-else@example.com',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addMinutes(30),
    ]);

    $this->getJson(route('api.v1.bookings.index', ['email' => 'owner@example.com']), withToken())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uid', $mine->uid);
});

test('a past meeting is not upcoming', function () {
    Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'email' => 'owner@example.com',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(30),
    ]);

    $this->getJson(route('api.v1.bookings.index', ['email' => 'owner@example.com']), withToken())
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('it books a meeting through the same action the public page uses', function () {
    $slots = $this->getJson(route('api.v1.slots.index', [
        'page' => $this->owner->booking_slug,
        'eventType' => 'owner-meeting',
        'from' => now()->addDay()->toDateString(),
        'to' => now()->addDays(7)->toDateString(),
    ]), withToken())->json('data');

    expect($slots)->not->toBeEmpty();

    $this->postJson(route('api.v1.bookings.store'), [
        'page' => $this->owner->booking_slug,
        'event_type' => 'owner-meeting',
        'starts_at' => $slots[0]['startsAt'],
        'name' => 'Xue Gao',
        'email' => 'owner@example.com',
    ], withToken())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Owner meeting');

    $this->assertDatabaseHas('bookings', [
        'event_type_id' => $this->eventType->id,
        'email' => 'owner@example.com',
    ]);
});

test('it refuses a time that is not actually free', function () {
    // The action re-checks under a lock, so a stale slot cannot be booked.
    $this->postJson(route('api.v1.bookings.store'), [
        'page' => $this->owner->booking_slug,
        'event_type' => 'owner-meeting',
        'starts_at' => now()->addYears(5)->toIso8601String(),
        'name' => 'Xue Gao',
        'email' => 'owner@example.com',
    ], withToken())->assertStatus(409);
});

test('booking requires a name and a valid email', function () {
    $this->postJson(route('api.v1.bookings.store'), [
        'page' => $this->owner->booking_slug,
        'event_type' => 'owner-meeting',
        'starts_at' => now()->addDay()->toIso8601String(),
        'email' => 'not-an-email',
    ], withToken())->assertJsonValidationErrors(['name', 'email']);
});

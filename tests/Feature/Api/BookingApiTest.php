<?php

use App\Actions\Bookings\CancelBooking;
use App\Enums\EventTypeKind;
use App\Enums\LocationType;
use App\Enums\TeamRole;
use App\Models\ApiKey;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
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

test('a canceled meeting drops off the list', function () {
    // The client portal shows this list as "what you have booked", so a
    // meeting called off here has to stop appearing there too.
    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'email' => 'owner@example.com',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addMinutes(30),
    ]);

    $this->getJson(route('api.v1.bookings.index', ['email' => 'owner@example.com']), withToken())
        ->assertJsonCount(1, 'data');

    app(CancelBooking::class)->handle($booking, canceledBy: 'host');

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

test('an organization key cannot read another organization pages', function () {
    $theirTeam = Team::factory()->create(['slug' => 'someone-else']);
    ['token' => $theirToken] = ApiKey::issue('Theirs', null, $theirTeam);

    // Reported as not found, not forbidden: whether our page exists is not
    // their business.
    $this->getJson(
        route('api.v1.event-types.index', ['page' => $this->owner->booking_slug]),
        withToken($theirToken),
    )->assertNotFound();
});

test('an organization key reads its own page', function () {
    $team = Team::factory()->create(['slug' => 'ours']);
    $team->members()->attach($this->owner, ['role' => TeamRole::Admin->value]);
    ['token' => $token] = ApiKey::issue('Ours', null, $team);

    // Its own organization page, and the pages of people who belong to it.
    $this->getJson(route('api.v1.event-types.index', ['page' => 'ours']), withToken($token))->assertOk();
    $this->getJson(
        route('api.v1.event-types.index', ['page' => $this->owner->booking_slug]),
        withToken($token),
    )->assertOk();
});

test('a page can be reached by organization id, which survives a rename', function () {
    $team = Team::factory()->create(['slug' => 'ours']);

    $this->getJson(route('api.v1.event-types.index', ['page' => (string) $team->id]), withToken())
        ->assertOk()
        ->assertJsonPath('meta.page', (string) $team->id);
});

test('it lists the organizations that have a booking page', function () {
    Team::factory()->create(['name' => 'Texas Renters', 'slug' => 'texas-renters']);

    $this->getJson(route('api.v1.organizations.index'), withToken())
        ->assertOk()
        ->assertJsonFragment(['name' => 'Texas Renters', 'slug' => 'texas-renters']);
});

test('a key says which organization it speaks for', function () {
    $team = Team::factory()->create(['name' => 'Texas Renters', 'slug' => 'texas-renters']);
    ['token' => $token] = ApiKey::issue('Client portal', null, $team);

    $this->getJson(route('api.v1.me'), withToken($token))
        ->assertOk()
        ->assertJsonPath('data.systemWide', false)
        ->assertJsonPath('data.organization.id', $team->id)
        ->assertJsonPath('data.organization.page', (string) $team->id);
});

test('a system wide key has no organization of its own', function () {
    // Nothing to imply, so whoever configured it has to name the page.
    $this->getJson(route('api.v1.me'), withToken())
        ->assertOk()
        ->assertJsonPath('data.systemWide', true)
        ->assertJsonPath('data.organization', null);
});

test('it lists every meeting an organization offers, across its pages', function () {
    $team = Team::factory()->create(['slug' => 'ours']);
    $team->members()->attach($this->owner, ['role' => TeamRole::Admin->value]);

    // One advertised on the organization's page, one on a person's.
    EventType::factory()->create([
        'team_id' => $team->id,
        'user_id' => $this->owner->id,
        'kind' => EventTypeKind::RoundRobin,
        'name' => 'Shared meeting',
        'slug' => 'shared-meeting',
    ]);
    EventType::factory()->create([
        'team_id' => $team->id,
        'user_id' => $this->owner->id,
        'kind' => EventTypeKind::OneOnOne,
        'name' => 'Personal meeting',
        'slug' => 'personal-meeting',
    ]);

    $response = $this->getJson(
        route('api.v1.organizations.event-types.index', ['organization' => $team->id]),
        withToken(),
    )->assertOk();

    $pages = collect($response->json('data'))->pluck('page', 'slug');

    // Each carries the page it is booked on, which is not the same for both.
    expect($pages['shared-meeting'])->toBe('ours')
        ->and($pages['personal-meeting'])->toBe($this->owner->booking_slug);
});

test('an organization key cannot list another organization meetings', function () {
    $theirs = Team::factory()->create();
    ['token' => $token] = ApiKey::issue('Theirs', null, Team::factory()->create());

    $this->getJson(
        route('api.v1.organizations.event-types.index', ['organization' => $theirs->id]),
        withToken($token),
    )->assertNotFound();
});

test('it lists every meeting booked with an organization, canceled ones included', function () {
    $team = Team::factory()->create(['slug' => 'ours']);
    ['token' => $token] = ApiKey::issue('Scoreboard', null, $team);

    $eventType = EventType::factory()->create([
        'team_id' => $team->id,
        'user_id' => $this->owner->id,
        'name' => 'Leasing call',
    ]);

    $booked = Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'name' => 'Jane Tenant',
        'email' => 'jane@example.com',
        'notes' => 'Need the 2025 statements for my taxes',
        'location_type' => LocationType::Phone,
        'location_detail' => '(512) 555-0100',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addMinutes(30),
    ]);
    $booked->answers()->create(['label' => 'Property address', 'answer' => '1 Main St']);

    Booking::factory()->canceled()->create([
        'event_type_id' => $eventType->id,
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addMinutes(30),
    ]);

    // Another organization's meeting, and one outside the window.
    Booking::factory()->create(['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(30)]);
    Booking::factory()->create([
        'event_type_id' => $eventType->id,
        'starts_at' => now()->addYear(),
        'ends_at' => now()->addYear()->addMinutes(30),
    ]);

    $this->getJson(
        route('api.v1.organizations.bookings.index', ['organization' => $team->id]),
        withToken($token),
    )
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.uid', $booked->uid)
        ->assertJsonPath('data.0.status', 'confirmed')
        ->assertJsonPath('data.0.eventName', 'Leasing call')
        ->assertJsonPath('data.0.invitee.email', 'jane@example.com')
        ->assertJsonPath('data.0.host.name', 'Texas Renters')
        ->assertJsonPath('data.0.answers.0.question', 'Property address')
        ->assertJsonPath('data.0.answers.0.answer', '1 Main St')
        ->assertJsonPath('data.0.invitee.phone', '(512) 555-0100')
        ->assertJsonPath('data.0.notes', 'Need the 2025 statements for my taxes')
        ->assertJsonPath('data.1.status', 'canceled');
});

test('an organization key cannot list another organization bookings', function () {
    $theirs = Team::factory()->create();
    ['token' => $token] = ApiKey::issue('Ours', null, Team::factory()->create());

    $this->getJson(
        route('api.v1.organizations.bookings.index', ['organization' => $theirs->id]),
        withToken($token),
    )->assertNotFound();
});

test('the organization bookings window is capped at a year', function () {
    $team = Team::factory()->create();
    ['token' => $token] = ApiKey::issue('Ours', null, $team);

    $this->getJson(route('api.v1.organizations.bookings.index', [
        'organization' => $team->id,
        'from' => now()->toDateString(),
        'to' => now()->addYears(2)->toDateString(),
    ]), withToken($token))->assertStatus(422);
});

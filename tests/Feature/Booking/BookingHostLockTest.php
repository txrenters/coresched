<?php

use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Group;
use App\Models\HostZone;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;

/**
 * A link from TEXRA can pin the booking to one host (`host=<email>`): the
 * agent who already holds the lead invited the renter to book with them.
 * Only that host's times are offered and the booking goes to them or to
 * nobody, so a lead that is one agent's never drifts to another.
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

    // Jose is first in line, so only the pin can send a booking to Dana.
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
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pinnedBooking(array $overrides = []): array
{
    return [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Rae Renter',
        'email' => 'rae@example.com',
        'reference' => 'lead-token-123',
        'host' => 'dana@example.com',
        ...$overrides,
    ];
}

function pinnedPage(array $query = []): string
{
    return route('book.event-type', ['page' => test()->team->slug, 'eventType' => 'call', ...$query]);
}

test('the booking page pins the host the link named and offers only their times', function () {
    $this->get(pinnedPage(['host' => 'Dana@Example.com']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('prefill.host', 'dana@example.com')
            ->where('lockedToHost', true)
            ->where('chosenHost.name', 'Dana Reed'));

    // A Saturday: Jose works, Dana does not, so a pinned page offers nothing that day.
    $response = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'book/EventType',
        'X-Inertia-Partial-Data' => 'slots',
    ])->get(pinnedPage(['host' => 'dana@example.com', 'month' => '2026-09']))->assertOk();

    expect($response->json('props.slots'))->toHaveKey('2026-09-02')->not->toHaveKey('2026-09-05');
});

test('a host not on the rota does not pin the page', function () {
    $this->get(pinnedPage(['host' => 'nobody@example.com']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('prefill.host', null)
            ->where('lockedToHost', false)
            ->where('chosenHost', null));
});

test('a pinned booking goes to that host even when another is first in line', function () {
    Notification::fake();

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), pinnedBooking())
        ->assertSessionHasNoErrors();

    $booking = Booking::first();

    expect($booking->user_id)->toBe($this->dana->id)
        ->and($booking->reference)->toBe('lead-token-123');
});

test('a pinned booking is refused rather than handed to someone else when the host is not free', function () {
    Notification::fake();

    // A Saturday: Jose works, Dana does not.
    $saturday = CarbonImmutable::parse('2026-09-05 10:00:00', 'UTC')->toIso8601String();

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), pinnedBooking(['starts_at' => $saturday]))
        ->assertSessionHasErrors('starts_at');

    expect(Booking::count())->toBe(0);
});

test('the pin wins over a zone the host does not cover', function () {
    Notification::fake();

    HostZone::query()->create(['team_id' => $this->team->id, 'user_id' => $this->jose->id, 'zone' => 'zone-b']);

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), pinnedBooking(['zone' => 'zone-b']))
        ->assertSessionHasNoErrors();

    expect(Booking::first()->user_id)->toBe($this->dana->id);
});

test('a pin to someone not on the rota is refused, not quietly dropped', function () {
    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), pinnedBooking(['host' => 'nobody@example.com']))
        ->assertSessionHasErrors('host');

    expect(Booking::count())->toBe(0);
});

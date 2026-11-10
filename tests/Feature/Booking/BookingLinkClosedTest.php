<?php

use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * First claim wins: once an agent holds a lead in TEXRA, the renter's link
 * books nobody else. TEXRA is asked (signed like the webhooks) before times
 * are shown and again when the renter books; a link pinned to the holder
 * keeps working, and anything but a clear "held" answer leaves it open.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));

    config([
        'services.texra.reference_url' => 'https://texra.test/api/bookings/reference',
        'services.texra.reference_secret' => 'reference-secret',
        'services.texra.webhook_url' => null,
    ]);

    $this->dana = User::factory()->create(['name' => 'Dana Reed', 'email' => 'dana@example.com', 'timezone' => 'UTC']);
    $this->team = $this->dana->currentTeam;
    $this->jose = User::factory()->create(['name' => 'Jose Ortiz', 'email' => 'jose@example.com', 'timezone' => 'UTC']);

    AvailabilitySchedule::factory()->for($this->dana)->everyDay()->create();
    AvailabilitySchedule::factory()->for($this->jose)->everyDay()->create();

    $group = Group::factory()->create(['team_id' => $this->team->id, 'name' => 'Agents on Duty']);
    $group->members()->attach([
        $this->jose->id => ['priority' => 0],
        $this->dana->id => ['priority' => 1],
    ]);

    $this->eventType = EventType::factory()->ownedBy($this->dana)->roundRobin()->create([
        'name' => 'Call with an agent',
        'slug' => 'call',
        'team_id' => $this->team->id,
        'group_id' => $group->id,
        'duration_minutes' => 60,
    ]);
});

/**
 * Have TEXRA answer the reference check with the given body and status.
 *
 * @param  array<string, mixed>  $answer
 */
function texraAnswers(array $answer, int $status = 200): void
{
    Http::fake([
        'texra.test/*' => Http::response($answer, $status),
        '*' => Http::response('', 404),
    ]);
}

function closedLinkPage(array $query = []): string
{
    return route('book.event-type', ['page' => test()->team->slug, 'eventType' => 'call', 'ref' => 'lead-token-123', ...$query]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function closedLinkBooking(array $overrides = []): array
{
    return [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Rae Renter',
        'email' => 'rae@example.com',
        'notes' => 'Nothing to add.',
        'reference' => 'lead-token-123',
        ...$overrides,
    ];
}

test('with no reference url configured TEXRA is never asked and the page offers times', function () {
    config(['services.texra.reference_url' => null]);
    Http::fake();

    $this->get(closedLinkPage())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('book/EventType'));

    Http::assertNothingSent();
});

test('a link without a reference is never checked', function () {
    Http::fake();

    $this->get(route('book.event-type', ['page' => $this->team->slug, 'eventType' => 'call']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('book/EventType'));

    Http::assertNothingSent();
});

test('an open lead offers times', function () {
    texraAnswers(['open' => true, 'host' => null]);

    $this->get(closedLinkPage())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('book/EventType'));
});

test('a lead held by nobody we can name closes the link', function () {
    texraAnswers(['open' => false, 'host' => null]);

    $this->get(closedLinkPage())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('book/LinkClosed')
            ->where('eventTypeName', 'Call with an agent')
            ->missing('slots'));
});

test('a link pinned to the agent holding the lead still offers their times', function () {
    texraAnswers(['open' => false, 'host' => 'Dana@Example.com']);

    $this->get(closedLinkPage(['host' => 'dana@example.com']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('book/EventType')
            ->where('lockedToHost', true));
});

test('a link pinned to someone else, or to nobody, is closed when another agent holds the lead', function (?string $pinned) {
    texraAnswers(['open' => false, 'host' => 'dana@example.com']);

    $this->get(closedLinkPage(array_filter(['host' => $pinned])))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('book/LinkClosed'));
})->with([
    'pinned to another agent' => 'jose@example.com',
    'not pinned' => null,
]);

test('the link stays open when TEXRA cannot give a clear answer', function (Closure $fake) {
    $fake();

    $this->get(closedLinkPage())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('book/EventType'));
})->with([
    'server error' => fn () => fn () => texraAnswers(['message' => 'Server Error'], 500),
    'timeout' => fn () => fn () => Http::fake(fn () => throw new ConnectionException('Operation timed out')),
    'malformed body' => fn () => fn () => Http::fake([
        'texra.test/*' => Http::response('<html>Bad gateway</html>', 200),
        '*' => Http::response('', 404),
    ]),
    'missing open flag' => fn () => fn () => texraAnswers(['host' => 'dana@example.com']),
]);

test('the reference check is signed like the webhooks', function () {
    texraAnswers(['open' => true, 'host' => null]);

    $this->get(closedLinkPage())->assertOk();

    $timestamp = (string) now()->getTimestamp();
    $body = '{"reference":"lead-token-123"}';

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://texra.test/api/bookings/reference'
        && $request->method() === 'POST'
        && $request->body() === $body
        && $request->hasHeader('Accept', 'application/json')
        && $request->hasHeader('X-Bookings-Timestamp', $timestamp)
        && $request->hasHeader('X-Bookings-Signature', 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'reference-secret')));
});

test('the signing secret falls back to the webhook secret', function () {
    config(['services.texra.reference_secret' => null, 'services.texra.webhook_secret' => 'webhook-secret']);
    texraAnswers(['open' => true, 'host' => null]);

    $this->get(closedLinkPage())->assertOk();

    $timestamp = (string) now()->getTimestamp();

    Http::assertSent(fn (HttpRequest $request) => $request->hasHeader(
        'X-Bookings-Signature',
        'sha256='.hash_hmac('sha256', $timestamp.'.{"reference":"lead-token-123"}', 'webhook-secret'),
    ));
});

test('booking through a link that closed after the page opened is refused', function () {
    Notification::fake();
    texraAnswers(['open' => false, 'host' => 'dana@example.com']);

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), closedLinkBooking())
        ->assertRedirect(closedLinkPage())
        ->assertSessionHasNoErrors();

    expect(Booking::count())->toBe(0);
});

test('booking through an open link books as before', function () {
    Notification::fake();
    texraAnswers(['open' => true, 'host' => null]);

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), closedLinkBooking())
        ->assertSessionHasNoErrors();

    $booking = Booking::sole();

    expect($booking->reference)->toBe('lead-token-123')
        ->and($booking->user_id)->toBe($this->jose->id);
});

test('booking through a link pinned to the holder still books them', function () {
    Notification::fake();
    texraAnswers(['open' => false, 'host' => 'dana@example.com']);

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), closedLinkBooking(['host' => 'dana@example.com']))
        ->assertSessionHasNoErrors();

    expect(Booking::sole()->user_id)->toBe($this->dana->id);
});

test('moving an existing booking is never gated', function () {
    Notification::fake();
    texraAnswers(['open' => true, 'host' => null]);

    $this->post(route('book.store', ['page' => $this->team->slug, 'eventType' => 'call']), closedLinkBooking())
        ->assertSessionHasNoErrors();

    $booking = Booking::sole();

    texraAnswers(['open' => false, 'host' => 'dana@example.com']);

    $this->get(closedLinkPage(['reschedule' => $booking->uid]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('book/EventType'));

    $this->post(route('booking.reschedule.store', ['booking' => $booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-03 10:00:00', 'UTC')->toIso8601String(),
    ])->assertSessionHasNoErrors();

    expect(Booking::query()->where('rescheduled_from_id', $booking->id)->exists())->toBeTrue();
    // Faking again starts a fresh record: nothing after the first booking asked TEXRA.
    Http::assertNothingSent();
});

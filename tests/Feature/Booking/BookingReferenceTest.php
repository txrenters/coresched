<?php

use App\Actions\Bookings\ApproveBooking;
use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\DeclineBooking;
use App\Actions\Bookings\RescheduleBooking;
use App\Enums\LocationType;
use App\Enums\WebhookScope;
use App\Jobs\SendBookingWebhook;
use App\Models\ApiKey;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * A link from TEXRA carries the renter's details and a reference. The
 * reference rides on the booking, and every change to it is reported back.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));

    $this->host = User::factory()->create([
        'name' => 'Dana Reed',
        'booking_slug' => 'dana',
        'timezone' => 'UTC',
    ]);

    AvailabilitySchedule::factory()->for($this->host)->everyDay()->create();

    $this->eventType = EventType::factory()->ownedBy($this->host)->create([
        'name' => 'Intro call',
        'slug' => 'intro',
        'duration_minutes' => 60,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function referencedBooking(array $overrides = []): array
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

test('the booking page prefills the invitee from the link and keeps the reference', function () {
    $this->get(route('book.event-type', [
        'page' => 'dana',
        'eventType' => 'intro',
        'name' => ' Rae Renter ',
        'email' => 'rae@example.com',
        'notes' => 'Nothing to add.',
        'phone' => '2815550100',
        'ref' => 'lead-token-123',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('prefill.name', 'Rae Renter')
            ->where('prefill.email', 'rae@example.com')
            // Not a phone-call event type, so a number would have nowhere to go.
            ->where('prefill.phone', null)
            ->where('prefill.reference', 'lead-token-123'));
});

test('the phone is prefilled only where the event type asks the invitee for one', function () {
    EventType::factory()->ownedBy($this->host)->create(['slug' => 'phone-call', 'location_type' => LocationType::Phone]);

    $this->get(route('book.event-type', ['page' => 'dana', 'eventType' => 'phone-call', 'phone' => '2815550100']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('prefill.phone', '2815550100'));
});

test('a malformed reference in the link is dropped rather than refused', function () {
    $this->get(route('book.event-type', ['page' => 'dana', 'eventType' => 'intro', 'ref' => 'not a token!']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('prefill.reference', null));
});

test('a booking keeps the reference it was opened with, through a reschedule', function () {
    Notification::fake();

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), referencedBooking())
        ->assertSessionHasNoErrors();

    $booking = Booking::first();

    expect($booking->reference)->toBe('lead-token-123');

    $this->post(route('booking.reschedule.store', ['booking' => $booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-03 10:00:00', 'UTC')->toIso8601String(),
    ])->assertSessionHasNoErrors();

    expect(Booking::query()->where('rescheduled_from_id', $booking->id)->value('reference'))->toBe('lead-token-123');
});

test('a malformed reference on the booking itself is refused', function () {
    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), referencedBooking(['reference' => 'not a token!']))
        ->assertSessionHasErrors('reference');
});

test('nothing is reported for a booking without a reference', function () {
    Notification::fake();
    Queue::fake();

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), referencedBooking(['reference' => null]));

    expect(Booking::query()->count())->toBe(1);
    Queue::assertNotPushed(SendBookingWebhook::class);
});

test('every change to a referenced booking is reported, in sequence', function () {
    Notification::fake();
    Queue::fake();

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), referencedBooking());

    $booking = Booking::first();

    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => $job->booking->is($booking)
        && $job->event === 'booking.confirmed'
        && $job->sequence === 1);

    $replacement = app(RescheduleBooking::class)->handle($booking, CarbonImmutable::parse('2026-09-03 10:00:00', 'UTC'));

    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => $job->booking->is($replacement)
        && $job->event === 'booking.rescheduled'
        && $job->sequence === 1);

    app(CancelBooking::class)->handle($replacement, 'Found a place');

    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => $job->booking->is($replacement)
        && $job->event === 'booking.canceled'
        && $job->sequence === 2);

    expect($replacement->fresh()->webhook_sequence)->toBe(2);
});

test('a request awaiting approval is reported as requested, then as confirmed or declined', function () {
    Notification::fake();
    Queue::fake();
    $this->eventType->update(['requires_confirmation' => true]);

    foreach (['2026-09-02 10:00:00', '2026-09-02 14:00:00'] as $time) {
        $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), referencedBooking([
            'starts_at' => CarbonImmutable::parse($time, 'UTC')->toIso8601String(),
        ]));
    }

    [$first, $second] = Booking::query()->orderBy('starts_at')->get();

    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => $job->booking->is($first)
        && $job->event === 'booking.requested'
        && $job->sequence === 1);

    app(ApproveBooking::class)->handle($first, $this->host);
    app(DeclineBooking::class)->handle($second, $this->host);

    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => $job->booking->is($first)
        && $job->event === 'booking.confirmed'
        && $job->sequence === 2);
    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => $job->booking->is($second)
        && $job->event === 'booking.declined'
        && $job->sequence === 2);
});

test('with no key naming an endpoint, the booking is reported to the configured one', function () {
    Notification::fake();
    Queue::fake();

    ApiKey::issue('No webhook', null, $this->eventType->team);

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), referencedBooking());

    Queue::assertPushed(SendBookingWebhook::class, 1);
    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => $job->receiver === null);
});

test('each of the organization keys with an endpoint hears about it instead', function () {
    Notification::fake();
    Queue::fake();

    ['key' => $crm] = ApiKey::issue('CRM', null, $this->eventType->team);
    $crm->pointWebhookAt('https://crm.example.com/hook');
    ['key' => $portal] = ApiKey::issue('Portal', null, $this->eventType->team);
    $portal->pointWebhookAt('https://portal.example.com/hook');

    // Not these: revoked, or another organization's.
    ['key' => $revoked] = ApiKey::issue('Old', null, $this->eventType->team);
    $revoked->pointWebhookAt('https://old.example.com/hook');
    $revoked->forceFill(['revoked_at' => now()])->save();
    ['key' => $theirs] = ApiKey::issue('Theirs', null, Team::factory()->create());
    $theirs->pointWebhookAt('https://theirs.example.com/hook');

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), referencedBooking());

    Queue::assertPushed(SendBookingWebhook::class, 2);
    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => (bool) $job->receiver?->is($crm) && $job->sequence === 1);
    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => (bool) $job->receiver?->is($portal) && $job->sequence === 1);
});

test('a key taking every booking hears about one with no reference, and the configured endpoint does not', function () {
    Notification::fake();
    Queue::fake();

    ['key' => $all] = ApiKey::issue('Operations', null, $this->eventType->team);
    $all->pointWebhookAt('https://ops.example.com/hook', scope: WebhookScope::All);
    ['key' => $referenced] = ApiKey::issue('CRM', null, $this->eventType->team);
    $referenced->pointWebhookAt('https://crm.example.com/hook');

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), referencedBooking(['reference' => null]));

    Queue::assertPushed(SendBookingWebhook::class, 1);
    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => (bool) $job->receiver?->is($all) && $job->sequence === 1);
});

test('a key taking every booking does not displace the configured endpoint for a referenced one', function () {
    Notification::fake();
    Queue::fake();

    ['key' => $all] = ApiKey::issue('Operations', null, $this->eventType->team);
    $all->pointWebhookAt('https://ops.example.com/hook', scope: WebhookScope::All);

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), referencedBooking());

    Queue::assertPushed(SendBookingWebhook::class, 2);
    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => (bool) $job->receiver?->is($all));
    Queue::assertPushed(SendBookingWebhook::class, fn (SendBookingWebhook $job): bool => $job->receiver === null);
});

<?php

use App\Actions\Bookings\RescheduleBooking;
use App\Enums\BookingStatus;
use App\Enums\EventTypeKind;
use App\Enums\LocationType;
use App\Enums\QuestionType;
use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\SyncBookingToCalendars;
use App\Models\ActivityLog;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Notifications\Bookings\BookingCanceled;
use App\Notifications\Bookings\BookingConfirmed;
use App\Notifications\Bookings\BookingPendingApproval;
use App\Notifications\Bookings\BookingRescheduled;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

test('the public page lists an owner bookable event types', function () {
    $this->get(route('book.page', ['page' => 'dana']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('book/Page')
            ->where('page.name', 'Dana Reed')
            ->has('eventTypes', 1)
            ->where('eventTypes.0.slug', 'intro'));
});

test('the booking pages say what kind of location the meeting is, so the right icon shows', function () {
    $this->eventType->update(['location_type' => LocationType::Phone]);

    $this->get(route('book.page', ['page' => 'dana']))
        ->assertInertia(fn ($page) => $page->where('eventTypes.0.locationType', 'phone'));

    $this->get(route('book.event-type', ['page' => 'dana', 'eventType' => 'intro']))
        ->assertInertia(fn ($page) => $page->where('eventType.locationType', 'phone'));
});

test('hidden event types are left off the public page but still resolve directly', function () {
    $this->eventType->update(['is_hidden' => true]);

    $this->get(route('book.page', ['page' => 'dana']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('eventTypes', 0));

    $this->get(route('book.event-type', ['page' => 'dana', 'eventType' => 'intro']))
        ->assertOk();
});

test('an inactive event type is not bookable', function () {
    $this->eventType->update(['is_active' => false]);

    $this->get(route('book.event-type', ['page' => 'dana', 'eventType' => 'intro']))
        ->assertNotFound();
});

test('an unknown page returns a not found response', function () {
    $this->get(route('book.page', ['page' => 'nobody']))->assertNotFound();
});

test('the event type page renders without waiting on the slot lookup', function () {
    $this->get(route('book.event-type', ['page' => 'dana', 'eventType' => 'intro', 'month' => '2026-09']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('book/EventType')
            ->where('eventType.name', 'Intro call')
            ->where('month', '2026-09')
            ->where('timezone', 'UTC')
            ->missing('slots'));
});

test('the deferred slot lookup returns the open times grouped by local date', function () {
    $response = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Data' => 'slots',
        'X-Inertia-Partial-Component' => 'book/EventType',
        'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
    ])->get(route('book.event-type', ['page' => 'dana', 'eventType' => 'intro', 'month' => '2026-09']));

    $slots = $response->assertOk()->json('props.slots');

    expect($slots)->toHaveKey('2026-09-02')
        ->and($slots['2026-09-02'][0]['label'])->toBe('9:00am')
        ->and($slots['2026-09-02'])->toHaveCount(8);
});

test('slots are shown in the organizers timezone, whatever the invitee asks for', function () {
    // The invitee used to choose; the page now states the organizer's zone.
    $this->host->update(['timezone' => 'America/Chicago']);

    $response = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Data' => 'slots',
        'X-Inertia-Partial-Component' => 'book/EventType',
        'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
    ])->get(route('book.event-type', [
        'page' => 'dana',
        'eventType' => 'intro',
        'month' => '2026-09',
        'timezone' => 'Asia/Tokyo',
    ]));

    $slots = $response->assertOk()->json('props.slots');

    // 09:00 UTC is 04:00 in Chicago, and Tokyo is not consulted.
    expect($slots['2026-09-02'][0]['label'])->toBe('4:00am');
});

test('an invitee can book a slot', function () {
    Notification::fake();

    $startsAt = CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC');

    $response = $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => $startsAt->toIso8601String(),
        'timezone' => 'America/Chicago',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Looking forward to it.',
    ]);

    $booking = Booking::first();

    expect($booking)->not->toBeNull()
        ->and($booking->starts_at->toIso8601String())->toBe($startsAt->toIso8601String())
        ->and($booking->ends_at->toIso8601String())->toBe($startsAt->addHour()->toIso8601String())
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->user_id)->toBe($this->host->id)
        ->and($booking->invitee_timezone)->toBe('America/Chicago');

    $response->assertRedirect(route('booking.show', ['booking' => $booking->uid]));

    Notification::assertSentTo($this->host, BookingConfirmed::class);
});

test('booking a slot schedules reminders', function () {
    Notification::fake();

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
    ]);

    expect(Booking::first()->reminders()->count())->toBe(2);
});

test('the same slot cannot be booked twice', function () {
    Notification::fake();

    $payload = [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
    ];

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), $payload);

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), $payload)
        ->assertSessionHasErrors('starts_at');

    expect(Booking::count())->toBe(1);
});

test('an invitee must say what we should know beforehand', function (?string $notes) {
    Notification::fake();

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => $notes,
    ])->assertSessionHasErrors('notes');

    expect(Booking::count())->toBe(0);
})->with(['missing' => null, 'blank' => '   ']);

test('a slot outside the working hours is rejected', function () {
    Notification::fake();

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 03:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
    ])->assertSessionHasErrors('starts_at');

    expect(Booking::count())->toBe(0);
});

test('a booking captures guests and answers to custom questions', function () {
    Notification::fake();

    $question = $this->eventType->questions()->create([
        'type' => 'text',
        'label' => 'What would you like to cover?',
        'is_required' => true,
        'position' => 0,
    ]);

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
        'guests' => ['colleague@example.com'],
        'answers' => [$question->id => 'Pricing'],
    ]);

    $booking = Booking::first();

    expect($booking->guests->pluck('email')->all())->toBe(['colleague@example.com'])
        ->and($booking->answers->first()->label)->toBe('What would you like to cover?')
        ->and($booking->answers->first()->answer)->toBe('Pricing');
});

test('a required question must be answered', function () {
    Notification::fake();

    $question = $this->eventType->questions()->create([
        'type' => 'text',
        'label' => 'What would you like to cover?',
        'is_required' => true,
        'position' => 0,
    ]);

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
    ])->assertSessionHasErrors("answers.{$question->id}");
});

test('an invitee can cancel their booking', function () {
    Notification::fake();

    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
    ]);

    $this->delete(route('booking.cancel.store', ['booking' => $booking->uid]), [
        'reason' => 'Something came up',
    ])->assertRedirect(route('booking.show', ['booking' => $booking->uid]));

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::Canceled)
        ->and($booking->cancellation_reason)->toBe('Something came up')
        ->and($booking->canceled_by)->toBe('invitee');

    Notification::assertSentTo($this->host, BookingCanceled::class);
});

test('an invitee can reschedule to a free slot', function () {
    Notification::fake();

    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
    ]);

    $this->post(route('booking.reschedule.store', ['booking' => $booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-03 14:00:00', 'UTC')->toIso8601String(),
        'reason' => 'Conflict',
    ]);

    $booking->refresh();
    $replacement = Booking::where('rescheduled_from_id', $booking->id)->first();

    expect($booking->status)->toBe(BookingStatus::Rescheduled)
        ->and($replacement)->not->toBeNull()
        ->and($replacement->starts_at->toTimeString())->toBe('14:00:00')
        ->and($replacement->email)->toBe('sam@example.com');

    Notification::assertSentTo($this->host, BookingRescheduled::class);
});

test('rescheduling announces the move once, without a second confirmation', function () {
    Notification::fake();

    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
    ]);

    $this->post(route('booking.reschedule.store', ['booking' => $booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-03 14:00:00', 'UTC')->toIso8601String(),
    ]);

    Notification::assertSentTo($this->host, BookingRescheduled::class);

    // The replacement is built by CreateBooking, which must stay quiet here.
    Notification::assertNotSentTo($this->host, BookingConfirmed::class);
    Notification::assertNotSentTo(new AnonymousNotifiable, BookingConfirmed::class);

    expect(ActivityLog::pluck('event')->all())->toBe(['booking.rescheduled']);
});

test('rescheduling releases the original slot', function () {
    Notification::fake();

    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
    ]);

    // Moving one hour later would overlap the original booking unless it is released.
    $this->post(route('booking.reschedule.store', ['booking' => $booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
    ]);

    expect(Booking::where('rescheduled_from_id', $booking->id)->exists())->toBeTrue();
});

test('a canceled booking cannot be canceled again', function () {
    Notification::fake();

    $booking = Booking::factory()->canceled()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
    ]);

    $this->delete(route('booking.cancel.store', ['booking' => $booking->uid]))->assertGone();
});

test('the confirmation page shows the booking in the invitee timezone', function () {
    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 16:00:00', 'UTC'),
        'invitee_timezone' => 'America/Chicago',
    ]);

    $this->get(route('booking.show', ['booking' => $booking->uid]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('book/Confirmed')
            ->where('booking.localTime', '10:00am - 11:00am')
            ->where('booking.timezone', 'America/Chicago'));
});

test('the booking page falls back to the configured default timezone', function () {
    $host = User::factory()->create(['name' => 'Casey Lane', 'booking_slug' => 'casey']);

    AvailabilitySchedule::factory()->for($host)->everyDay()->create();

    EventType::factory()->ownedBy($host)->create(['slug' => 'chat']);

    expect($host->timezone)->toBe('America/Chicago');

    $this->get(route('book.event-type', ['page' => 'casey', 'eventType' => 'chat']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('timezone', 'America/Chicago'));
});

test('the booking page tells the calendar today in the organizer timezone, not the visitor clock', function () {
    // 02:00 UTC on 2 October is still the evening of 1 October in Chicago.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 02:00:00', 'UTC'));

    $host = User::factory()->create(['booking_slug' => 'casey', 'timezone' => 'America/Chicago']);

    AvailabilitySchedule::factory()->for($host)->everyDay()->create();

    EventType::factory()->ownedBy($host)->create(['slug' => 'chat']);

    $this->get(route('book.event-type', ['page' => 'casey', 'eventType' => 'chat']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('today', '2026-10-01'));
});

test('booking a requires-confirmation event creates a pending request', function () {
    Notification::fake();
    Queue::fake();

    $this->eventType->update(['requires_confirmation' => true]);

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
    ]);

    $booking = Booking::first();

    expect($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->reminders()->count())->toBe(0);

    Notification::assertSentTo($this->host, BookingPendingApproval::class);
    Notification::assertSentTo(new AnonymousNotifiable, BookingPendingApproval::class);
    Notification::assertNotSentTo($this->host, BookingConfirmed::class);
    Notification::assertNotSentTo(new AnonymousNotifiable, BookingConfirmed::class);

    // The calendars only hear about the booking once a host approves it.
    Queue::assertNotPushed(SyncBookingToCalendars::class);

    $log = ActivityLog::where('event', 'booking.created')->first();

    expect($log->description)->toContain('requested')
        ->and($log->properties['requiresApproval'])->toBeTrue();
});

test('a pending booking blocks the slot for other invitees', function () {
    Notification::fake();

    $this->eventType->update(['requires_confirmation' => true]);

    $payload = [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
    ];

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), $payload);

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), $payload)
        ->assertSessionHasErrors('starts_at');

    expect(Booking::count())->toBe(1);
});

test('an invitee can cancel a pending request', function () {
    Notification::fake();

    $booking = Booking::factory()->pending()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
    ]);

    $this->delete(route('booking.cancel.store', ['booking' => $booking->uid]))
        ->assertRedirect(route('booking.show', ['booking' => $booking->uid]));

    expect($booking->refresh()->status)->toBe(BookingStatus::Canceled);

    Notification::assertSentTo($this->host, BookingCanceled::class);
});

test('the confirmation page shows the pending state', function () {
    $booking = Booking::factory()->pending()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
    ]);

    $this->get(route('booking.show', ['booking' => $booking->uid]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('book/Confirmed')
            ->where('booking.status', 'pending'));
});

test('rescheduling into a requires-confirmation event yields a new pending request', function () {
    Notification::fake();

    $this->eventType->update(['requires_confirmation' => true]);

    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
    ]);

    $this->post(route('booking.reschedule.store', ['booking' => $booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-03 14:00:00', 'UTC')->toIso8601String(),
    ]);

    $replacement = Booking::where('rescheduled_from_id', $booking->id)->first();

    expect($booking->refresh()->status)->toBe(BookingStatus::Rescheduled)
        ->and($replacement->status)->toBe(BookingStatus::Pending);

    // The new time is a request again, so nothing may promise it as booked.
    Notification::assertSentTo($this->host, BookingPendingApproval::class);
    Notification::assertNotSentTo($this->host, BookingRescheduled::class);
    Notification::assertNotSentTo(new AnonymousNotifiable, BookingRescheduled::class);
});

test('an invitee who picks no timezone is recorded in the configured default', function () {
    Notification::fake();

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'America/Chicago',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
    ]);

    expect(Booking::first()->invitee_timezone)->toBe('America/Chicago');
});

/*
 * An invitee is offered no self-service reschedule or cancel: changes go
 * through the organizer. The routes themselves stay reachable -- the host's
 * Meetings page uses them, and links already emailed must not start 404ing.
 */
test('the invitee confirmation email offers no reschedule or cancel link', function () {
    $booking = Booking::factory()->create();

    $mail = (new BookingConfirmed($booking))->toMail(new AnonymousNotifiable);

    $body = $mail->actionText.' '.implode(' ', array_merge($mail->introLines, $mail->outroLines));

    expect($body)->not->toContain('Reschedule')
        ->and($body)->not->toContain('Cancel this meeting');
});

test('the confirmation email shows a meeting link as a join button', function () {
    $url = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc%40thread.v2/0?context=%7b%22Tid%22%7d';

    $booking = Booking::factory()->create([
        'location_type' => LocationType::MicrosoftTeams,
        'meeting_url' => $url,
    ]);

    $html = (string) (new BookingConfirmed($booking))->toMail(new AnonymousNotifiable)->render();

    expect($html)->toContain('Where:</strong> Microsoft Teams')
        ->and($html)->toContain('href="'.e($url).'"')
        ->and($html)->toContain('Join meeting')
        ->and($html)->not->toContain('&lt;table');
});

test('the confirmation email keeps an address as text', function () {
    $booking = Booking::factory()->create([
        'location_type' => LocationType::InPerson,
        'location_detail' => '12 Main St, Austin',
    ]);

    $html = (string) (new BookingConfirmed($booking))->toMail(new AnonymousNotifiable)->render();

    expect($html)->toContain('Where:</strong> 12 Main St, Austin')
        ->and($html)->not->toContain('Join meeting');
});

test('a yes or no question is answered from its two options', function () {
    $question = $this->eventType->questions()->create([
        'type' => QuestionType::YesNo,
        'label' => 'Is this your first visit?',
        'is_required' => true,
        'position' => 0,
    ]);

    $startsAt = CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC');

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => $startsAt->toIso8601String(),
        'timezone' => 'America/Chicago',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
        'answers' => [$question->id => 'Yes'],
    ])->assertSessionHasNoErrors();

    expect(Booking::latest('id')->first()->answers()->sole()->answer)->toBe('Yes');
});

test('a pick multiple question keeps every answer', function () {
    $question = $this->eventType->questions()->create([
        'type' => QuestionType::MultiSelect,
        'label' => 'What would you like to cover?',
        'options' => ['Billing', 'Owner statements', 'Maintenance'],
        'is_required' => true,
        'position' => 0,
    ]);

    $startsAt = CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC');

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => $startsAt->toIso8601String(),
        'timezone' => 'America/Chicago',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
        'answers' => [$question->id => ['Billing', 'Maintenance']],
    ])->assertSessionHasNoErrors();

    expect(Booking::latest('id')->first()->answers()->sole()->answer)
        ->toBe('Billing, Maintenance');
});

test('an answer outside the offered options is refused', function () {
    $question = $this->eventType->questions()->create([
        'type' => QuestionType::Select,
        'label' => 'Which property?',
        'options' => ['Oak Street', 'Elm Street'],
        'is_required' => true,
        'position' => 0,
    ]);

    $startsAt = CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC');

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => $startsAt->toIso8601String(),
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
        'timezone' => 'America/Chicago',
        'answers' => [$question->id => 'Somewhere else'],
    ])->assertSessionHasErrors("answers.{$question->id}");

    expect(Booking::query()->where('email', 'sam@example.com')->exists())->toBeFalse();
});

test('a one-on-one names the person you are booking with', function () {
    $this->get(route('book.event-type', ['page' => 'dana', 'eventType' => 'intro']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('eventType.hostNames', [$this->host->name]));
});

test('a pooled event type names nobody, since it is assigned when booked', function () {
    $team = $this->host->currentTeam;
    $second = User::factory()->create();
    $team->members()->attach($second, ['role' => TeamRole::Member->value]);

    $pooled = EventType::factory()->ownedBy($this->host)->create([
        'name' => 'Team call',
        'slug' => 'team-call',
        'kind' => EventTypeKind::RoundRobin,
    ]);

    $pooled->hosts()->attach($this->host, ['priority' => 0]);
    $pooled->hosts()->attach($second, ['priority' => 1]);

    $this->get(route('book.event-type', ['page' => 'dana', 'eventType' => 'team-call']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('eventType.hostNames', []));
});

test('a phone question refuses an answer that is not a number', function () {
    $question = $this->eventType->questions()->create([
        'type' => QuestionType::Phone,
        'label' => 'Best number to reach you',
        'is_required' => true,
        'position' => 0,
    ]);

    $startsAt = CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC');

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => $startsAt->toIso8601String(),
        'timezone' => 'America/Chicago',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
        'answers' => [$question->id => 'call me whenever'],
    ])->assertSessionHasErrors("answers.{$question->id}");

    expect(Booking::query()->where('email', 'sam@example.com')->exists())->toBeFalse();
});

test('a phone question accepts the ways people write numbers', function () {
    $question = $this->eventType->questions()->create([
        'type' => QuestionType::Phone,
        'label' => 'Best number to reach you',
        'is_required' => true,
        'position' => 0,
    ]);

    $startsAt = CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC');

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => $startsAt->toIso8601String(),
        'timezone' => 'America/Chicago',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
        'answers' => [$question->id => '(346) 239-9213'],
    ])->assertSessionHasNoErrors();

    // Stored with the +1 in place, the form Twilio dials.
    expect(Booking::latest('id')->first()->answers()->sole()->answer)
        ->toBe('+13462399213');
});

test('a phone question refuses a number too short to be a us number', function () {
    $question = $this->eventType->questions()->create([
        'type' => QuestionType::Phone,
        'label' => 'Best number to reach you',
        'is_required' => true,
        'position' => 0,
    ]);

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'America/Chicago',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
        'answers' => [$question->id => '555-0100'],
    ])->assertSessionHasErrors(["answers.{$question->id}" => 'Enter a 10 digit phone number.']);
});

test('the number an invitee gives to be called on is stored with its +1', function () {
    Notification::fake();

    $this->eventType->update(['location_type' => LocationType::Phone]);

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'America/Chicago',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
        'location_detail' => '346.239.9213',
    ])->assertSessionHasNoErrors();

    expect(Booking::latest('id')->first()->location_detail)->toBe('+13462399213');

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'America/Chicago',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
        'location_detail' => '239-9213',
    ])->assertSessionHasErrors(['location_detail' => 'Enter a 10 digit phone number.']);
});

test('an invitee cannot replace the hosts own location', function () {
    Notification::fake();

    $this->eventType->update([
        'location_type' => LocationType::CustomLink,
        'location_detail' => 'https://meet.example.com/dana',
    ]);

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'America/Chicago',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
        'location_detail' => 'https://evil.example.com',
    ])->assertSessionHasNoErrors();

    expect(Booking::latest('id')->first()->location_detail)->toBe('https://meet.example.com/dana');
});

test('a booking on a deleted event type still shows its confirmation page', function () {
    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
    ]);

    $this->eventType->delete();

    $this->get(route('booking.show', ['booking' => $booking->uid]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('booking.eventTypeName', 'Intro call'));
});

test('a booking on a deleted event type cannot be rescheduled', function () {
    Notification::fake();

    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
    ]);

    $this->eventType->delete();

    $this->get(route('booking.reschedule', ['booking' => $booking->uid]))->assertGone();

    $this->post(route('booking.reschedule.store', ['booking' => $booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-03 14:00:00', 'UTC')->toIso8601String(),
    ])->assertGone();

    expect(Booking::where('rescheduled_from_id', $booking->id)->exists())->toBeFalse();
});

test('a booking canceled while a reschedule is under way is not moved', function () {
    Notification::fake();

    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
    ]);

    // The copy in hand still reads as confirmed; the stored row has moved on.
    Booking::whereKey($booking->id)->update(['status' => BookingStatus::Canceled]);

    expect(fn () => app(RescheduleBooking::class)->handle(
        $booking,
        CarbonImmutable::parse('2026-09-03 14:00:00', 'UTC'),
    ))->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(410));

    expect(Booking::where('rescheduled_from_id', $booking->id)->exists())->toBeFalse()
        ->and($booking->fresh()->status)->toBe(BookingStatus::Canceled);
});

test('a booking can move within a day that has reached its daily limit', function () {
    Notification::fake();

    $this->eventType->update(['daily_booking_limit' => 1]);

    $booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 11:00:00', 'UTC'),
    ]);

    $this->post(route('booking.reschedule.store', ['booking' => $booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 13:00:00', 'UTC')->toIso8601String(),
    ])->assertSessionHasNoErrors();

    expect(Booking::where('rescheduled_from_id', $booking->id)->sole()->starts_at->toTimeString())
        ->toBe('13:00:00');
});

test('a phone question refuses a list of answers', function () {
    $question = $this->eventType->questions()->create([
        'type' => QuestionType::Phone,
        'label' => 'Best number to reach you',
        'is_required' => true,
        'position' => 0,
    ]);

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'America/Chicago',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
        'answers' => [$question->id => ['+1 (346) 239-9213']],
    ])->assertSessionHasErrors(["answers.{$question->id}" => 'Enter a phone number.']);

    expect(Booking::query()->where('email', 'sam@example.com')->exists())->toBeFalse();
});

test('an answer nested deeper than a list is refused', function () {
    $question = $this->eventType->questions()->create([
        'type' => QuestionType::MultiSelect,
        'label' => 'Topics',
        'options' => ['Pricing', 'Onboarding'],
        'is_required' => false,
        'position' => 0,
    ]);

    $this->post(route('book.store', ['page' => 'dana', 'eventType' => 'intro']), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'America/Chicago',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
        'answers' => [$question->id => [['Pricing']]],
    ])->assertSessionHasErrors(["answers.{$question->id}.0" => 'The answers.'.$question->id.'.0 field must be a string.']);

    expect(Booking::query()->where('email', 'sam@example.com')->exists())->toBeFalse();
});

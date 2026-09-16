<?php

use App\Enums\TeamRole;
use App\Models\ActivityLog;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use App\Notifications\Bookings\BookingConfirmed;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));

    $this->host = User::factory()->create(['name' => 'Samuel Upchurch', 'timezone' => 'UTC']);
    $this->team = $this->host->currentTeam;
    $this->otherHost = User::factory()->create(['name' => 'Riley Park', 'timezone' => 'UTC']);
    $this->team->members()->attach($this->otherHost, ['role' => TeamRole::Member->value]);

    AvailabilitySchedule::factory()->for($this->host)->everyDay()->create();
    AvailabilitySchedule::factory()->for($this->otherHost)->everyDay()->create();

    $this->eventType = EventType::factory()->ownedBy($this->host)->roundRobin()->create([
        'name' => 'Leasing Team Meeting',
        'slug' => 'leasing',
        'duration_minutes' => 30,
    ]);
    $this->eventType->hosts()->attach($this->host, ['priority' => 0]);
    $this->eventType->hosts()->attach($this->otherHost, ['priority' => 1]);

    $this->bookingPage = ['page' => $this->team->slug, 'eventType' => 'leasing'];
    $this->details = [
        'starts_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Dominic Kasp',
        'email' => 'dominic@example.com',
    ];
});

test('staff can book a meeting for an invitee with a host they choose', function () {
    $this->actingAs($this->host)
        ->post(route('book.store', $this->bookingPage), [...$this->details, 'host_id' => $this->otherHost->id])
        ->assertRedirect();

    $booking = Booking::where('email', 'dominic@example.com')->firstOrFail();

    expect($booking->user_id)->toBe($this->otherHost->id)
        ->and(ActivityLog::where('event', 'booking.created')->value('user_id'))->toBe($this->host->id);

    Notification::assertSentTo(new AnonymousNotifiable, BookingConfirmed::class);
});

test('the booking page tells staff they are booking for someone and narrows times to the host', function () {
    $this->actingAs($this->host)
        ->get(route('book.event-type', [...$this->bookingPage, 'host' => $this->otherHost->id]))
        ->assertInertia(fn ($page) => $page
            ->where('bookingOnBehalf', true)
            ->where('chosenHost.name', 'Riley Park'));
});

test('a public invitee cannot choose the host', function () {
    $this->get(route('book.event-type', [...$this->bookingPage, 'host' => $this->otherHost->id]))
        ->assertInertia(fn ($page) => $page
            ->where('bookingOnBehalf', false)
            ->where('chosenHost', null));

    $this->post(route('book.store', $this->bookingPage), [...$this->details, 'host_id' => $this->otherHost->id])
        ->assertForbidden();

    expect(Booking::count())->toBe(0);
});

test('a member of another organization cannot choose the host', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(route('book.store', $this->bookingPage), [...$this->details, 'host_id' => $this->otherHost->id])
        ->assertForbidden();

    expect(Booking::count())->toBe(0);
});

test('the meetings page offers the event types the viewer can book for others', function () {
    $this->actingAs($this->otherHost)
        ->get(route('meetings.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page
            ->has('bookableEventTypes', 1)
            ->where('bookableEventTypes.0.name', 'Leasing Team Meeting')
            ->where('bookableEventTypes.0.hosts.1.name', 'Riley Park'));
});

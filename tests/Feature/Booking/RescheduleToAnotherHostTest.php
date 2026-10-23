<?php

use App\Enums\BookingStatus;
use App\Enums\TeamRole;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\HostZone;
use App\Models\User;
use App\Notifications\Bookings\BookingCanceled;
use App\Notifications\Bookings\BookingRescheduled;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));

    $this->host = User::factory()->create(['name' => 'Samuel Upchurch', 'booking_slug' => 'samuel', 'timezone' => 'UTC']);
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

    $this->booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 12:30:00', 'UTC'),
        'email' => 'dominic@example.com',
    ]);
    $this->booking->hosts()->attach($this->host);
});

test('an organizer can reschedule a meeting onto a host they choose', function () {
    Notification::fake();

    $this->actingAs($this->host)
        ->post(route('booking.reschedule.store', ['booking' => $this->booking->uid]), [
            'starts_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC')->toIso8601String(),
            'reason' => 'Samuel is out',
            'host_id' => $this->otherHost->id,
        ])
        ->assertRedirect();

    $replacement = Booking::where('rescheduled_from_id', $this->booking->id)->firstOrFail();

    expect($replacement->user_id)->toBe($this->otherHost->id)
        ->and($replacement->hosts->modelKeys())->toBe([$this->otherHost->id])
        ->and($this->booking->refresh()->status)->toBe(BookingStatus::Rescheduled)
        ->and($this->booking->cancellation_reason)->toBe('Samuel is out')
        ->and($this->booking->canceled_by)->toBe('host');

    Notification::assertSentTo($this->otherHost, BookingRescheduled::class);
    Notification::assertSentTo($this->host, BookingCanceled::class);
    Notification::assertNotSentTo($this->host, BookingRescheduled::class);
});

test('the picker only offers times the chosen host is free', function () {
    Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->otherHost->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-03 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-03 10:30:00', 'UTC'),
    ])->hosts()->attach($this->otherHost);

    $this->actingAs($this->host)
        ->get(route('book.event-type', [
            'page' => 'samuel',
            'eventType' => 'leasing',
            'reschedule' => $this->booking->uid,
            'host' => $this->otherHost->id,
            'month' => '2026-09',
        ]))
        ->assertInertia(fn ($page) => $page
            ->where('chosenHost.name', 'Riley Park')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('slots.2026-09-03', fn ($slots) => collect($slots)->pluck('label')->doesntContain('10:00am')
                    && collect($slots)->pluck('label')->contains('10:30am'))));
});

test('an invitee cannot choose the host', function () {
    $this->get(route('book.event-type', [
        'page' => 'samuel',
        'eventType' => 'leasing',
        'reschedule' => $this->booking->uid,
        'host' => $this->otherHost->id,
    ]))->assertInertia(fn ($page) => $page->where('chosenHost.name', 'Samuel Upchurch'));

    $this->post(route('booking.reschedule.store', ['booking' => $this->booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC')->toIso8601String(),
        'host_id' => $this->otherHost->id,
    ])->assertForbidden();

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

test('an invitee moving a pooled meeting keeps the host who has it', function () {
    $this->booking->hosts()->sync([$this->otherHost->id]);
    $this->booking->update(['user_id' => $this->otherHost->id]);

    $this->get(route('book.event-type', [
        'page' => 'samuel',
        'eventType' => 'leasing',
        'reschedule' => $this->booking->uid,
    ]))->assertInertia(fn ($page) => $page->where('chosenHost.name', 'Riley Park'));

    $this->post(route('booking.reschedule.store', ['booking' => $this->booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-03 12:00:00', 'UTC')->toIso8601String(),
    ])->assertRedirect();

    $replacement = Booking::where('rescheduled_from_id', $this->booking->id)->firstOrFail();

    expect($replacement->user_id)->toBe($this->otherHost->id)
        ->and($replacement->hosts->modelKeys())->toBe([$this->otherHost->id])
        ->and($this->booking->refresh()->canceled_by)->toBe('invitee');
});

test('an invitee may post the host the picker showed them', function () {
    $this->post(route('booking.reschedule.store', ['booking' => $this->booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-03 12:00:00', 'UTC')->toIso8601String(),
        'host_id' => $this->host->id,
    ])->assertRedirect();

    expect(Booking::where('rescheduled_from_id', $this->booking->id)->value('user_id'))->toBe($this->host->id);
});

test('an invitee is not moved to another host when theirs is busy', function () {
    Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-03 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-03 10:30:00', 'UTC'),
    ])->hosts()->attach($this->host);

    $this->get(route('book.event-type', [
        'page' => 'samuel',
        'eventType' => 'leasing',
        'reschedule' => $this->booking->uid,
        'month' => '2026-09',
    ]))->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('slots.2026-09-03', fn ($slots) => collect($slots)->pluck('label')->doesntContain('10:00am'))));

    $this->post(route('booking.reschedule.store', ['booking' => $this->booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-03 10:00:00', 'UTC')->toIso8601String(),
    ])->assertSessionHasErrors('starts_at');

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

test('a meeting cannot be handed to someone outside the host pool', function () {
    $outsider = User::factory()->create();
    AvailabilitySchedule::factory()->for($outsider)->everyDay()->create();

    $this->actingAs($this->host)
        ->post(route('booking.reschedule.store', ['booking' => $this->booking->uid]), [
            'starts_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC')->toIso8601String(),
            'host_id' => $outsider->id,
        ])
        ->assertForbidden();

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

test('a time the chosen host is busy for is refused even if another host is free', function () {
    Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->otherHost->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-03 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-03 10:30:00', 'UTC'),
    ])->hosts()->attach($this->otherHost);

    $this->actingAs($this->host)
        ->post(route('booking.reschedule.store', ['booking' => $this->booking->uid]), [
            'starts_at' => CarbonImmutable::parse('2026-09-03 10:00:00', 'UTC')->toIso8601String(),
            'host_id' => $this->otherHost->id,
        ])
        ->assertSessionHasErrors('starts_at');

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

test('the meetings page offers the pool as hosts to reschedule to, but not for a one-on-one', function () {
    $oneOnOne = EventType::factory()->ownedBy($this->host)->create(['name' => 'Intro call']);

    Booking::factory()->create([
        'event_type_id' => $oneOnOne->id,
        'user_id' => $this->host->id,
        'team_id' => $oneOnOne->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-04 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-04 12:30:00', 'UTC'),
    ])->hosts()->attach($this->host);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming']))
        ->assertInertia(fn ($page) => $page
            ->where('meetings.0.eventTypeName', 'Leasing Team Meeting')
            ->where('meetings.0.reassignableHosts', [
                ['id' => $this->host->id, 'name' => 'Samuel Upchurch'],
                ['id' => $this->otherHost->id, 'name' => 'Riley Park'],
            ])
            ->where('meetings.1.eventTypeName', 'Intro call')
            ->where('meetings.1.reassignableHosts', []));
});

test('a booking re-tagged with a zone its host is outside is moved within the zone, not kept', function () {
    HostZone::query()->create(['team_id' => $this->team->id, 'user_id' => $this->otherHost->id, 'zone' => 'spanish']);
    $this->booking->update(['zone' => 'spanish']);

    Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->otherHost->id,
        'team_id' => $this->eventType->team_id,
        'starts_at' => CarbonImmutable::parse('2026-09-03 10:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-03 10:30:00', 'UTC'),
    ])->hosts()->attach($this->otherHost);

    expect($this->booking->refresh()->keptHost())->toBeNull();

    $redirect = $this->get(route('booking.reschedule', ['booking' => $this->booking->uid]))->assertRedirect();

    expect($redirect->headers->get('Location'))->toContain('zone=spanish');

    // The zone's hosts are on offer: Riley is busy at 10:00, so it is gone
    // even though Samuel, who has the meeting, is free then.
    $this->get(route('book.event-type', [
        'page' => 'samuel',
        'eventType' => 'leasing',
        'reschedule' => $this->booking->uid,
        'zone' => 'spanish',
        'month' => '2026-09',
    ]))->assertInertia(fn ($page) => $page
        ->where('chosenHost', null)
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('slots.2026-09-03', fn ($slots) => collect($slots)->pluck('label')->doesntContain('10:00am')
                && collect($slots)->pluck('label')->contains('10:30am'))));

    $this->post(route('booking.reschedule.store', ['booking' => $this->booking->uid]), [
        'starts_at' => CarbonImmutable::parse('2026-09-03 12:00:00', 'UTC')->toIso8601String(),
    ])->assertRedirect();

    $replacement = Booking::where('rescheduled_from_id', $this->booking->id)->firstOrFail();

    expect($replacement->user_id)->toBe($this->otherHost->id)
        ->and($replacement->zone)->toBe('spanish');
});

test('a zone the host who has the meeting covers keeps it with them', function () {
    HostZone::query()->create(['team_id' => $this->team->id, 'user_id' => $this->host->id, 'zone' => 'spanish']);
    $this->booking->update(['zone' => 'spanish']);

    expect($this->booking->refresh()->keptHost()?->id)->toBe($this->host->id);
});

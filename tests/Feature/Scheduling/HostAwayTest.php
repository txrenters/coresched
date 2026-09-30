<?php

use App\Enums\BookingStatus;
use App\Enums\TeamRole;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\LeavePeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));

    $this->host = User::factory()->create(['name' => 'Samuel Upchurch', 'timezone' => 'UTC']);
    $this->team = $this->host->currentTeam;
    $this->cover = User::factory()->create(['name' => 'Riley Park', 'timezone' => 'UTC']);
    $this->team->members()->attach($this->cover, ['role' => TeamRole::Member->value]);

    AvailabilitySchedule::factory()->for($this->host)->everyDay()->create();
    AvailabilitySchedule::factory()->for($this->cover)->everyDay()->create();

    $this->eventType = EventType::factory()->ownedBy($this->host)->roundRobin()->create([
        'name' => 'Leasing Team Meeting',
        'duration_minutes' => 30,
    ]);
    $this->eventType->hosts()->attach($this->host, ['priority' => 0]);
    $this->eventType->hosts()->attach($this->cover, ['priority' => 1]);

    $this->booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->team->id,
        'starts_at' => CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-02 12:30:00', 'UTC'),
    ]);
    $this->booking->hosts()->attach($this->host);

    LeavePeriod::factory()->for($this->host)->create([
        'starts_at' => CarbonImmutable::parse('2026-09-02 00:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-03 00:00:00', 'UTC'),
    ]);
});

test('the meetings page flags meetings that fall during a host\'s leave', function () {
    $afterLeave = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->team->id,
        'starts_at' => CarbonImmutable::parse('2026-09-04 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-04 12:30:00', 'UTC'),
    ]);
    $afterLeave->hosts()->attach($this->host);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming']))
        ->assertInertia(fn ($page) => $page
            ->where('hostAwayCount', 1)
            ->where('meetings.0.awayHostNames', ['Samuel Upchurch'])
            ->where('meetings.1.awayHostNames', []));

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming', 'status' => 'host_away']))
        ->assertInertia(fn ($page) => $page->has('meetings', 1)->where('meetings.0.uid', $this->booking->uid));
});

test('a meeting is handed to a free host at the same time', function () {
    $this->actingAs($this->host)
        ->post(route('meetings.hand-off', ['current_team' => $this->team->slug, 'booking' => $this->booking->uid]))
        ->assertRedirect();

    $replacement = Booking::where('rescheduled_from_id', $this->booking->id)->firstOrFail();

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Rescheduled)
        ->and($replacement->user_id)->toBe($this->cover->id)
        ->and($replacement->starts_at->equalTo($this->booking->starts_at))->toBeTrue();
});

test('a meeting stays put when nobody else is free', function () {
    LeavePeriod::factory()->for($this->cover)->create([
        'starts_at' => CarbonImmutable::parse('2026-09-02 00:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-03 00:00:00', 'UTC'),
    ]);

    $this->actingAs($this->host)
        ->post(route('meetings.hand-off', ['current_team' => $this->team->slug, 'booking' => $this->booking->uid]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Nobody else who hosts this meeting is free at that time.']);

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

test('moving all meetings hands off every one that someone can take', function () {
    $this->actingAs($this->host)
        ->post(route('meetings.hand-off-all', ['current_team' => $this->team->slug]))
        ->assertRedirect();

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Rescheduled)
        ->and(Booking::active()->withHostAway()->count())->toBe(0);
});

test('a member cannot move a meeting they do not host', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->post(route('meetings.hand-off', ['current_team' => $this->team->slug, 'booking' => $this->booking->uid]))
        ->assertForbidden();

    expect($this->booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

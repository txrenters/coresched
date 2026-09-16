<?php

use App\Enums\TeamRole;
use App\Models\ActivityLog;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-03 16:00:00', 'UTC'));

    $this->host = User::factory()->create(['name' => 'Dana Reed']);
    $this->team = $this->host->currentTeam;
    $this->eventType = EventType::factory()->ownedBy($this->host)->create(['name' => 'Property tour']);

    $this->booking = Booking::factory()->create([
        'event_type_id' => $this->eventType->id,
        'user_id' => $this->host->id,
        'team_id' => $this->team->id,
        'name' => 'Sam Rivera',
        'starts_at' => CarbonImmutable::parse('2026-09-03 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-03 15:30:00', 'UTC'),
    ]);
    $this->booking->hosts()->attach($this->host);
});

test('a host can mark a meeting that has started as a no-show, which queues the no-show workflows', function () {
    $followUp = Automation::factory()->onNoShow()->toInvitee()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->host)
        ->post(route('meetings.no-show.store', ['current_team' => $this->team->slug, 'booking' => $this->booking->uid]))
        ->assertRedirect();

    expect($this->booking->refresh()->no_show_at)->not->toBeNull()
        ->and(AutomationRun::where('booking_id', $this->booking->id)->pluck('automation_id')->all())->toBe([$followUp->id])
        ->and(ActivityLog::where('event', 'booking.no_show')->exists())->toBeTrue();
});

test('a no-show drops the after-meeting follow-up, and undoing it brings it back', function () {
    $thanks = Automation::factory()->afterEnd(60)->toInvitee()->create(['team_id' => $this->team->id]);
    $sorry = Automation::factory()->onNoShow()->toInvitee()->create(['team_id' => $this->team->id]);
    $this->booking->automationRuns()->create(['automation_id' => $thanks->id, 'send_at' => now()->addMinutes(30)]);

    $route = ['current_team' => $this->team->slug, 'booking' => $this->booking->uid];

    $this->actingAs($this->host)->post(route('meetings.no-show.store', $route));

    expect($this->booking->automationRuns()->pluck('automation_id')->all())->toBe([$sorry->id]);

    $this->actingAs($this->host)->delete(route('meetings.no-show.destroy', $route));

    expect($this->booking->refresh()->no_show_at)->toBeNull()
        ->and($this->booking->automationRuns()->pluck('automation_id')->all())->toBe([$thanks->id]);
});

test('a meeting that has not started cannot be marked a no-show', function () {
    $this->booking->update([
        'starts_at' => CarbonImmutable::parse('2026-09-04 15:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-04 15:30:00', 'UTC'),
    ]);

    $this->actingAs($this->host)
        ->post(route('meetings.no-show.store', ['current_team' => $this->team->slug, 'booking' => $this->booking->uid]))
        ->assertRedirect();

    expect($this->booking->refresh()->no_show_at)->toBeNull();
});

test('a member who does not host the meeting cannot mark it a no-show', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->post(route('meetings.no-show.store', ['current_team' => $this->team->slug, 'booking' => $this->booking->uid]))
        ->assertForbidden();

    expect($this->booking->refresh()->no_show_at)->toBeNull();
});

test('the meetings page says which meetings were no-shows and which can still be marked', function () {
    $this->booking->update(['no_show_at' => now()]);

    $this->actingAs($this->host)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'past']))
        ->assertInertia(fn ($page) => $page
            ->where('meetings.0.noShow', true)
            ->where('meetings.0.canMarkNoShow', true));
});

<?php

use App\Enums\TeamRole;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\SingleUseLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));

    $this->host = User::factory()->create(['name' => 'Dana Reed', 'booking_slug' => 'dana', 'timezone' => 'UTC']);
    $this->team = $this->host->currentTeam;

    AvailabilitySchedule::factory()->for($this->host)->everyDay()->create();

    // Switched off, so it has no working public link of its own.
    $this->eventType = EventType::factory()->ownedBy($this->host)->create([
        'name' => 'Private tour',
        'slug' => 'private-tour',
        'duration_minutes' => 60,
        'is_active' => false,
    ]);

    $this->details = [
        'starts_at' => CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC')->toIso8601String(),
        'timezone' => 'UTC',
        'name' => 'Sam Rivera',
        'email' => 'sam@example.com',
        'notes' => 'Nothing to add.',
    ];
});

test('a host can make a single-use link for their event type', function () {
    $response = $this->actingAs($this->host)
        ->postJson(route('scheduling.single-use-links.store', ['current_team' => $this->team->slug, 'event_type' => 'private-tour']))
        ->assertCreated();

    $link = SingleUseLink::sole();

    expect($response->json('url'))->toBe(route('book.once', ['singleUseLink' => $link->uid]))
        ->and($link->created_by)->toBe($this->host->id);
});

test('a member who cannot host the event type cannot make a link for it', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->postJson(route('scheduling.single-use-links.store', ['current_team' => $this->team->slug, 'event_type' => 'private-tour']))
        ->assertForbidden();

    expect(SingleUseLink::count())->toBe(0);
});

test('a link books its event type once, then shows that it has been used', function () {
    $link = SingleUseLink::factory()->create(['event_type_id' => $this->eventType->id]);

    $this->get(route('book.once', ['singleUseLink' => $link->uid]))
        ->assertInertia(fn ($page) => $page
            ->component('book/EventType')
            ->where('singleUseLink', $link->uid));

    $this->post(route('book.once.store', ['singleUseLink' => $link->uid]), $this->details)->assertRedirect();

    $booking = Booking::sole();

    expect($link->refresh()->used_at)->not->toBeNull()
        ->and($link->booking_id)->toBe($booking->id);

    $this->get(route('book.once', ['singleUseLink' => $link->uid]))
        ->assertInertia(fn ($page) => $page->component('book/LinkUsed'));

    $this->post(route('book.once.store', ['singleUseLink' => $link->uid]), [
        ...$this->details,
        'starts_at' => CarbonImmutable::parse('2026-09-03 10:00:00', 'UTC')->toIso8601String(),
    ])->assertGone();

    expect(Booking::count())->toBe(1);
});

test('a link is not used up by a time that turns out to be taken', function () {
    $link = SingleUseLink::factory()->create(['event_type_id' => $this->eventType->id]);

    $this->post(route('book.once.store', ['singleUseLink' => $link->uid]), [
        ...$this->details,
        'starts_at' => CarbonImmutable::parse('2026-09-02 03:00:00', 'UTC')->toIso8601String(),
    ])->assertSessionHasErrors('starts_at');

    expect($link->refresh()->used_at)->toBeNull()
        ->and(Booking::count())->toBe(0);
});

test('a link to a deleted event type no longer works', function () {
    $link = SingleUseLink::factory()->create(['event_type_id' => $this->eventType->id]);
    $this->eventType->delete();

    $this->get(route('book.once', ['singleUseLink' => $link->uid]))->assertNotFound();
});

<?php

use App\Enums\TeamRole;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use App\Services\Activity\ActivityLogger;

/**
 * Build an organization with an owner.
 *
 * @return array{0: User, 1: Team}
 */
function activityTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return [$user, $team];
}

test('creating an event type is recorded against the actor', function () {
    [$user, $team] = activityTeam();

    $this->actingAs($user)->post(route('scheduling.store', ['current_team' => $team->slug]), [
        'name' => 'Property Tour',
        'slug' => 'property-tour',
        'kind' => 'one_on_one',
        'duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'seats_per_slot' => 1,
        'date_range_type' => 'rolling_days',
        'rolling_days' => 60,
        'location_type' => 'custom_link',
        'location_detail' => 'https://example.com/room',
        'is_active' => true,
        'is_hidden' => false,
    ])->assertRedirect();

    $entry = ActivityLog::query()->where('event', 'event_type.created')->sole();

    expect($entry->team_id)->toBe($team->id)
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->actor_name)->toBe($user->name)
        ->and($entry->description)->toContain('Property Tour');
});

test('deleting an event type is recorded with the name it had', function () {
    [$user, $team] = activityTeam();

    $eventType = EventType::factory()->ownedBy($user)->create([
        'team_id' => $team->id,
        'name' => 'Old Tour',
    ]);

    $this->actingAs($user)->delete(route('scheduling.destroy', [
        'current_team' => $team->slug,
        'event_type' => $eventType->slug,
    ]))->assertRedirect();

    expect(ActivityLog::query()->where('event', 'event_type.deleted')->sole()->description)
        ->toContain('Old Tour');
});

test('inviting and removing members is recorded', function () {
    [$user, $team] = activityTeam();
    $member = User::factory()->create();

    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($user)->post(route('teams.invitations.store', $team), [
        'email' => 'new@texasrenters.com',
        'role' => TeamRole::Member->value,
    ])->assertRedirect();

    $this->actingAs($user)
        ->delete(route('teams.members.destroy', [$team->slug, $member->id]))
        ->assertRedirect();

    expect(ActivityLog::query()->where('event', 'invitation.sent')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('event', 'member.removed')->sole()->description)
        ->toContain($member->name);
});

test('a public booking is recorded with no actor', function () {
    [$user, $team] = activityTeam();

    $logger = app(ActivityLogger::class);

    // Nobody is signed in on the public booking page.
    $entry = $logger->record($team, 'booking.created', 'Someone booked a meeting');

    expect($entry->user_id)->toBeNull()
        ->and($entry->actor_name)->toBeNull();
});

test('a failed write never breaks the surrounding action', function () {
    [$user, $team] = activityTeam();

    // A team that no longer exists violates the foreign key.
    $team->forceFill(['id' => 999999])->syncOriginal();

    $entry = app(ActivityLogger::class)->record($team, 'booking.created', 'Should not throw');

    expect($entry)->toBeNull();
});

test('the activity page lists entries newest first and filters by kind', function () {
    [$user, $team] = activityTeam();
    $logger = app(ActivityLogger::class);

    $this->actingAs($user);

    $logger->record($team, 'event_type.created', 'Created an event type');
    $logger->record($team, 'booking.created', 'Someone booked a meeting');

    $this->get(route('activity.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activity')
            ->where('entries.data.0.description', 'Someone booked a meeting')
            ->has('entries.data', 2)
        );

    $this->get(route('activity.index', ['current_team' => $team->slug, 'kind' => 'booking']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.kind', 'booking')
        );
});

test('activity from another organization is never shown', function () {
    [$user, $team] = activityTeam();
    [, $other] = activityTeam();

    app(ActivityLogger::class)->record($other, 'booking.created', 'Someone else booked');

    $this->actingAs($user)
        ->get(route('activity.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries.data', 0));
});

test('a member reads only their own part of the trail', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $member->teams()->detach();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    // Something they did.
    ActivityLog::create([
        'team_id' => $team->id,
        'user_id' => $member->id,
        'event' => 'event_type.created',
        'description' => 'Created an event type',
    ]);

    // Something done to them.
    ActivityLog::create([
        'team_id' => $team->id,
        'user_id' => $admin->id,
        'event' => 'member.role_changed',
        'description' => "Changed the member's role",
        'subject_type' => User::class,
        'subject_id' => $member->id,
    ]);

    // Somebody else's business entirely.
    ActivityLog::create([
        'team_id' => $team->id,
        'user_id' => $admin->id,
        'event' => 'event_type.created',
        'description' => 'Created something of their own',
    ]);

    $this->actingAs($member)
        ->get(route('activity.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('seesEveryone', false)
            ->has('entries.data', 2));
});

test('an admin reads the whole organizations trail', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $admin->switchTeam($team);

    foreach ([$admin, $member] as $actor) {
        ActivityLog::create([
            'team_id' => $team->id,
            'user_id' => $actor->id,
            'event' => 'event_type.created',
            'description' => 'Created an event type',
        ]);
    }

    $this->actingAs($admin)
        ->get(route('activity.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('seesEveryone', true)
            ->has('entries.data', 2));
});

test('a member sees activity about the meetings they host', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $member->teams()->detach();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $eventType = EventType::factory()->ownedBy($member)->create(['team_id' => $team->id]);
    $booking = Booking::factory()->for($eventType)->create([
        'team_id' => $team->id,
        'user_id' => $member->id,
    ]);

    // Cancelled by the admin, but it is the member's meeting.
    ActivityLog::create([
        'team_id' => $team->id,
        'user_id' => $admin->id,
        'event' => 'booking.canceled',
        'description' => 'Canceled a meeting',
        'subject_type' => Booking::class,
        'subject_id' => $booking->id,
    ]);

    $this->actingAs($member)
        ->get(route('activity.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries.data', 1));
});

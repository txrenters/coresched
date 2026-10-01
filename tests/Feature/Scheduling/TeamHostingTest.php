<?php

use App\Actions\Bookings\AssignHosts;
use App\Enums\TeamRole;
use App\Models\AvailabilitySchedule;
use App\Models\EventType;
use App\Models\Group;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));
});

/**
 * Build an organization with an owner and the given number of members.
 *
 * @return array{0: Team, 1: User, 2: array<int, User>}
 */
function organizationWithMembers(int $count = 3): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $owner->forceFill(['current_team_id' => $team->id])->save();

    $members = [];

    for ($i = 0; $i < $count; $i++) {
        $member = User::factory()->create();

        AvailabilitySchedule::factory()->for($member)->everyDay('09:00:00', '17:00:00')->create();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);

        $members[] = $member;
    }

    return [$team, $owner, $members];
}

test('a team can be created inside an organization with ordered members', function () {
    [$team, $owner, $members] = organizationWithMembers();

    $this->actingAs($owner)
        ->post(route('groups.store', ['current_team' => $team->slug]), [
            'name' => 'Leasing',
            'description' => 'Handles tours and applications',
            'member_ids' => [$members[2]->id, $members[0]->id, $members[1]->id],
        ])
        ->assertRedirect();

    $group = Group::query()->where('team_id', $team->id)->sole();

    expect($group->name)->toBe('Leasing')
        ->and($group->members()->pluck('users.id')->all())
        ->toBe([$members[2]->id, $members[0]->id, $members[1]->id]);
});

test('an event type pointed at a team hosts from every member of it', function () {
    [$team, $owner, $members] = organizationWithMembers();

    $group = Group::factory()->create(['team_id' => $team->id, 'name' => 'Maintenance']);
    $group->members()->attach([
        $members[0]->id => ['priority' => 0],
        $members[1]->id => ['priority' => 1],
    ]);

    $eventType = EventType::factory()
        ->ownedBy($owner)
        ->roundRobin()
        ->create(['team_id' => $team->id, 'group_id' => $group->id]);

    $pool = $eventType->fresh()->hostPool();

    expect($pool->pluck('id')->all())->toBe([$members[0]->id, $members[1]->id]);
});

test('bookings go to the highest priority member of the team', function () {
    [$team, $owner, $members] = organizationWithMembers();

    $group = Group::factory()->create(['team_id' => $team->id]);
    $group->members()->attach([
        $members[1]->id => ['priority' => 0],
        $members[0]->id => ['priority' => 1],
    ]);

    $eventType = EventType::factory()
        ->ownedBy($owner)
        ->roundRobin()
        ->create(['team_id' => $team->id, 'group_id' => $group->id]);

    $chosen = app(AssignHosts::class)->handle(
        $eventType->fresh(),
        [$members[0]->id, $members[1]->id],
    );

    expect($chosen->first()->id)->toBe($members[1]->id);
});

test('editing the team changes every event type pointed at it', function () {
    [$team, $owner, $members] = organizationWithMembers();

    $group = Group::factory()->create(['team_id' => $team->id, 'name' => 'Leasing']);
    $group->members()->attach([$members[0]->id => ['priority' => 0]]);

    $first = EventType::factory()->ownedBy($owner)->roundRobin()
        ->create(['team_id' => $team->id, 'group_id' => $group->id]);
    $second = EventType::factory()->ownedBy($owner)->roundRobin()
        ->create(['team_id' => $team->id, 'group_id' => $group->id]);

    // Reorder and add a member through the normal edit route.
    $this->actingAs($owner)
        ->patch(route('groups.update', ['current_team' => $team->slug, 'group' => $group->slug]), [
            'name' => 'Leasing',
            'member_ids' => [$members[2]->id, $members[0]->id],
        ])
        ->assertRedirect();

    // Both event types pick the change up without being touched themselves.
    foreach ([$first, $second] as $eventType) {
        expect($eventType->fresh()->hostPool()->pluck('id')->all())
            ->toBe([$members[2]->id, $members[0]->id]);
    }
});

test('pointing an event type at a team clears any individually pinned hosts', function () {
    [$team, $owner, $members] = organizationWithMembers();

    $group = Group::factory()->create(['team_id' => $team->id]);
    $group->members()->attach([$members[0]->id => ['priority' => 0]]);

    $eventType = EventType::factory()->ownedBy($owner)->roundRobin()
        ->create(['team_id' => $team->id]);
    $eventType->hosts()->attach([$members[1]->id => ['priority' => 0]]);

    $this->actingAs($owner)
        ->patch(route('scheduling.update', [
            'current_team' => $team->slug,
            'event_type' => $eventType->slug,
        ]), [
            'name' => $eventType->name,
            'slug' => $eventType->slug,
            'kind' => 'round_robin',
            'group_id' => $group->id,
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
        ])
        ->assertRedirect();

    $fresh = $eventType->fresh();

    // Only one answer to "who hosts this": the team.
    expect($fresh->hosts()->count())->toBe(0)
        ->and($fresh->hostPool()->pluck('id')->all())->toBe([$members[0]->id]);
});

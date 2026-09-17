<?php

use App\Enums\EventTypeKind;
use App\Enums\TeamRole;
use App\Models\AvailabilitySchedule;
use App\Models\EventType;
use App\Models\Group;
use App\Models\Team;
use App\Models\User;
use App\Services\Scheduling\AvailabilityEngine;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();

    $this->team->members()->attach($this->owner, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);
    $this->owner->switchTeam($this->team);
});

test('the groups page lists the teams groups', function () {
    $group = Group::factory()->create(['team_id' => $this->team->id, 'name' => 'Sales']);
    $group->members()->attach($this->member, ['priority' => 0]);

    $this->actingAs($this->owner)
        ->get(route('groups.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('scheduling/groups/Index')
            ->has('groups', 1)
            ->where('groups.0.name', 'Sales')
            ->has('groups.0.members', 1)
            ->where('canManage', true));
});

test('an admin can create a group', function () {
    $this->actingAs($this->owner)
        ->post(route('groups.store', ['current_team' => $this->team->slug]), [
            'name' => 'Sales',
            'description' => 'Everyone who takes demos',
            'member_ids' => [$this->member->id, $this->owner->id],
        ])
        ->assertRedirect(route('groups.index', ['current_team' => $this->team->slug]));

    $group = Group::first();

    expect($group->name)->toBe('Sales')
        ->and($group->slug)->toBe('sales')
        ->and($group->members->pluck('id')->all())->toBe([$this->member->id, $this->owner->id])
        ->and($group->members->first()->pivot->priority)->toBe(0);
});

test('a group needs at least one member', function () {
    $this->actingAs($this->owner)
        ->post(route('groups.store', ['current_team' => $this->team->slug]), [
            'name' => 'Empty',
            'member_ids' => [],
        ])
        ->assertSessionHasErrors('member_ids');
});

test('someone outside the team cannot be added to a group', function () {
    $stranger = User::factory()->create();

    $this->actingAs($this->owner)
        ->post(route('groups.store', ['current_team' => $this->team->slug]), [
            'name' => 'Sales',
            'member_ids' => [$stranger->id],
        ])
        ->assertSessionHasErrors('member_ids.0');
});

test('a plain member cannot create a group', function () {
    $this->member->switchTeam($this->team);

    $this->actingAs($this->member)
        ->post(route('groups.store', ['current_team' => $this->team->slug]), [
            'name' => 'Sales',
            'member_ids' => [$this->member->id],
        ])
        ->assertForbidden();
});

test('updating a group replaces its membership', function () {
    $group = Group::factory()->create(['team_id' => $this->team->id]);
    $group->members()->attach($this->member, ['priority' => 0]);

    $this->actingAs($this->owner)
        ->patch(route('groups.update', ['current_team' => $this->team->slug, 'group' => $group->slug]), [
            'name' => 'Sales',
            'member_ids' => [$this->owner->id],
        ])
        ->assertSessionHasNoErrors();

    expect($group->fresh()->members->pluck('id')->all())->toBe([$this->owner->id]);
});

test('a round robin event type hosts from its group', function () {
    $group = Group::factory()->create(['team_id' => $this->team->id]);
    $group->members()->attach($this->owner, ['priority' => 0]);
    $group->members()->attach($this->member, ['priority' => 1]);

    $eventType = EventType::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'group_id' => $group->id,
        'kind' => EventTypeKind::RoundRobin,
    ]);

    expect($eventType->hostPool()->pluck('id')->all())
        ->toBe([$this->owner->id, $this->member->id]);
});

test('adding someone to a group adds them to every event type using it', function () {
    $group = Group::factory()->create(['team_id' => $this->team->id]);
    $group->members()->attach($this->owner, ['priority' => 0]);

    $first = EventType::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'group_id' => $group->id,
        'kind' => EventTypeKind::RoundRobin,
    ]);
    $second = EventType::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'group_id' => $group->id,
        'kind' => EventTypeKind::Collective,
    ]);

    $this->actingAs($this->owner)
        ->patch(route('groups.update', ['current_team' => $this->team->slug, 'group' => $group->slug]), [
            'name' => $group->name,
            'member_ids' => [$this->owner->id, $this->member->id],
        ]);

    expect($first->fresh()->hostPool())->toHaveCount(2)
        ->and($second->fresh()->hostPool())->toHaveCount(2);
});

test('a group drives availability for a collective event', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 08:00:00', 'UTC'));

    AvailabilitySchedule::factory()->for($this->owner)->everyDay('09:00:00', '13:00:00')->create();
    AvailabilitySchedule::factory()->for($this->member)->everyDay('11:00:00', '17:00:00')->create();

    $group = Group::factory()->create(['team_id' => $this->team->id]);
    $group->members()->attach([$this->owner->id, $this->member->id]);

    $eventType = EventType::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'group_id' => $group->id,
        'kind' => EventTypeKind::Collective,
        'duration_minutes' => 60,
        'minimum_notice_minutes' => 0,
    ]);

    $day = CarbonImmutable::parse('2026-09-02', 'UTC');

    $starts = app(AvailabilityEngine::class)
        ->slots($eventType->fresh(), new TimeRange($day->startOfDay(), $day->endOfDay()))
        ->map(fn ($slot) => $slot->startsAt->toTimeString());

    // Only the 11:00-13:00 overlap is shared by both members.
    expect($starts->all())->toBe(['11:00:00', '12:00:00']);
});

test('an event type can use a group instead of pinned hosts', function () {
    $group = Group::factory()->create(['team_id' => $this->team->id]);
    $group->members()->attach([$this->owner->id, $this->member->id]);

    $this->actingAs($this->owner)
        ->post(route('scheduling.store', ['current_team' => $this->team->slug]), [
            'name' => 'Demo', 'slug' => 'demo', 'kind' => EventTypeKind::RoundRobin->value,
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'minimum_notice_minutes' => 240, 'seats_per_slot' => 1, 'date_range_type' => 'rolling_days',
            'rolling_days' => 60, 'location_type' => 'custom_link', 'location_detail' => 'https://example.com',
            'is_active' => true, 'is_hidden' => false, 'group_id' => $group->id,
        ])
        ->assertSessionHasNoErrors();

    $eventType = EventType::first();

    expect($eventType->group_id)->toBe($group->id)
        ->and($eventType->hosts)->toHaveCount(0)
        ->and($eventType->hostPool())->toHaveCount(2);
});

test('deleting a group leaves its event types falling back to their own hosts', function () {
    $group = Group::factory()->create(['team_id' => $this->team->id]);
    $group->members()->attach($this->member);

    $eventType = EventType::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'group_id' => $group->id,
        'kind' => EventTypeKind::RoundRobin,
    ]);

    $this->actingAs($this->owner)
        ->delete(route('groups.destroy', ['current_team' => $this->team->slug, 'group' => $group->slug]));

    $eventType->refresh();

    expect($eventType->group_id)->toBeNull()
        ->and($eventType->hostPool()->pluck('id')->all())->toBe([$this->owner->id]);
});

test('an admin can pick event types to show on a team page', function () {
    $group = Group::factory()->create(['team_id' => $this->team->id]);
    $eventType = EventType::factory()->ownedBy($this->owner)->create();

    $this->actingAs($this->owner)
        ->patch(route('groups.update', ['current_team' => $this->team->slug, 'group' => $group->slug]), [
            'name' => 'Sales',
            'member_ids' => [$this->owner->id],
            'page_event_type_ids' => [$eventType->id],
        ])
        ->assertSessionHasNoErrors();

    expect($group->fresh()->pageEventTypes->pluck('id')->all())->toBe([$eventType->id]);

    $this->get(route('groups.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('groups.0.pageEventTypeIds', [$eventType->id])
            ->where('eventTypes.0.id', $eventType->id));
});

test('saving a group without page event types keeps the ones already picked', function () {
    $group = Group::factory()->create(['team_id' => $this->team->id]);
    $eventType = EventType::factory()->ownedBy($this->owner)->create();
    $group->pageEventTypes()->attach($eventType);

    $this->actingAs($this->owner)
        ->patch(route('groups.update', ['current_team' => $this->team->slug, 'group' => $group->slug]), [
            'name' => 'Sales',
            'member_ids' => [$this->owner->id],
        ])
        ->assertSessionHasNoErrors();

    expect($group->fresh()->pageEventTypes)->toHaveCount(1);
});

test('an event type from another organization cannot be shown on a team page', function () {
    $group = Group::factory()->create(['team_id' => $this->team->id]);
    $foreign = EventType::factory()->ownedBy(User::factory()->create())->create();

    $this->actingAs($this->owner)
        ->patch(route('groups.update', ['current_team' => $this->team->slug, 'group' => $group->slug]), [
            'name' => 'Sales',
            'member_ids' => [$this->owner->id],
            'page_event_type_ids' => [$foreign->id],
        ])
        ->assertSessionHasErrors('page_event_type_ids.0');

    expect($group->fresh()->pageEventTypes)->toBeEmpty();
});

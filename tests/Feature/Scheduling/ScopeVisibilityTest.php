<?php

use App\Enums\TeamRole;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team = Team::factory()->create();

    $this->team->members()->attach($this->owner, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    $this->owner->switchTeam($this->team);
    $this->member->switchTeam($this->team);

    EventType::factory()->ownedBy($this->owner)->create([
        'team_id' => $this->team->id,
        'name' => "The owner's call",
    ]);

    EventType::factory()->ownedBy($this->member)->create([
        'team_id' => $this->team->id,
        'name' => "The member's call",
    ]);
});

function schedulingIndex(User $user, Team $team, array $query = [])
{
    return test()->actingAs($user)->get(route('scheduling.index', [
        'current_team' => $team->slug,
        ...$query,
    ]));
}

test('a member sees only the event types they host', function () {
    schedulingIndex($this->member, $this->team)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('eventTypes', 1)
            ->where('eventTypes.0.name', "The member's call"));
});

test('an owner sees the whole organization', function () {
    schedulingIndex($this->owner, $this->team)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('eventTypes', 2));
});

test('a member cannot widen the list by asking for someone elses scope', function () {
    schedulingIndex($this->member, $this->team, ['scope' => "user:{$this->owner->id}"])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('eventTypes', 1)
            ->where('eventTypes.0.name', "The member's call")
            ->where('scope', 'mine'));
});

test('a member is offered no one but themselves in the scope picker', function () {
    schedulingIndex($this->member, $this->team)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('scopeOptions.primary', 1)
            ->where('scopeOptions.primary.0.value', 'mine')
            ->has('scopeOptions.users', 0)
            ->has('scopeOptions.groups', 0));
});

test('a member sees only their own meetings', function () {
    $ownerEventType = EventType::query()->where('user_id', $this->owner->id)->sole();
    $memberEventType = EventType::query()->where('user_id', $this->member->id)->sole();

    Booking::factory()->for($ownerEventType)->create([
        'user_id' => $this->owner->id,
        'team_id' => $this->team->id,
        'name' => 'Owner invitee',
    ]);

    Booking::factory()->for($memberEventType)->create([
        'user_id' => $this->member->id,
        'team_id' => $this->team->id,
        'name' => 'Member invitee',
    ]);

    $this->actingAs($this->member)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'filter' => 'upcoming']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('meetings', 1)
            ->where('meetings.0.inviteeName', 'Member invitee'));
});

<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

test('team member roles can be updated by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $member]), [
            'role' => TeamRole::Admin->value,
        ]);

    $response->assertRedirect(route('teams.members.index', $team));

    expect($team->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(TeamRole::Admin->value);
});

test('team member roles cannot be updated by members', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Member->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($admin)
        ->patch(route('teams.members.update', [$team, $member]), [
            'role' => TeamRole::Admin->value,
        ]);

    $response->assertForbidden();
});

test('team members can be removed by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]));

    $response->assertRedirect(route('teams.members.index', $team));

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();
});

test('team members cannot be removed by members', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Member->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($admin)
        ->delete(route('teams.members.destroy', [$team, $member]));

    $response->assertForbidden();
});

test('the last administrator cannot be removed', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $owner]));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToTeam($team))->toBeTrue();
});

test('the last administrator cannot be demoted', function () {
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    // Organizations used to be anchored by an owner who could not be removed.
    // Flattened onto admins, what anchors one is that the last admin stays.
    $this->actingAs($admin)
        ->patch(route('teams.members.update', [$team, $admin]), [
            'role' => TeamRole::Member->value,
        ])
        ->assertForbidden();

    expect($admin->fresh()->teamRole($team))->toBe(TeamRole::Admin);
});

test('a removed member lands on another organization they belong to', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $ownTeam = $member->fallbackTeam();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $member->update(['current_team_id' => $team->id]);

    $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]));

    expect($member->fresh()->current_team_id)->toEqual($ownTeam->id);
});

/*
 * fallbackTeam() is nullable: an account can belong to one organization and
 * nothing else, which is what CreateTeamUser and the invitation join flow both
 * produce. Removing such a member used to pass that null to switchTeam() and
 * 500 the request.
 */
test('a member who belongs nowhere else can be removed', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $member = User::factory()->create();
    $member->teams()->detach();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $this->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]))
        ->assertRedirect(route('teams.members.index', $team));

    expect($member->fresh()->belongsToTeam($team))->toBeFalse()
        ->and($member->fresh()->current_team_id)->toBeNull();
});

test('a removed member falls back to another organization they still belong to', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $other = Team::factory()->create();

    $member = User::factory()->create();
    $member->teams()->detach();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $other->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $this->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]));

    expect($member->fresh()->current_team_id)->toBe($other->id);
});

test('a removed member lands on the organization they still belong to', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $member = User::factory()->create();
    $personal = $member->fallbackTeam();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->forceFill(['current_team_id' => $team->id])->save();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $this->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]));

    expect($member->fresh()->current_team_id)->toBe($personal->id);
});

test('a super admin is left off the organizations member roster', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $operator = User::factory()->create(['is_super_admin' => true]);

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($operator, ['role' => TeamRole::Admin->value]);

    $this->actingAs($owner)
        ->get(route('teams.members.index', ['team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('teams/Members')
            ->has('members', 1)
            ->where('members.0.email', $owner->email));
});

test('an admin promotes a member to admin', function () {
    $admin = User::factory()->create();
    $successor = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($successor, ['role' => TeamRole::Member->value]);

    $this->actingAs($admin)
        ->patch(route('teams.members.update', ['team' => $team->slug, 'user' => $successor->id]), [
            'role' => TeamRole::Admin->value,
        ])
        ->assertRedirect();

    // Admins sit alongside each other; an organization can have several.
    expect($successor->fresh()->teamRole($team))->toBe(TeamRole::Admin)
        ->and($admin->fresh()->teamRole($team))->toBe(TeamRole::Admin);
});

test('an admin can be removed while another admin remains', function () {
    $admin = User::factory()->create();
    $second = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($second, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin)
        ->delete(route('teams.members.destroy', [$team, $second]))
        ->assertRedirect();

    expect($second->fresh()->belongsToTeam($team))->toBeFalse();
});

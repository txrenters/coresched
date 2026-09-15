<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/**
 * Looking across every organization is the operator's job; an organization's
 * admins see and manage only theirs. These pin the viewOrganizations gate and
 * the per-organization TeamPolicy checks as well as the behaviour.
 */
function operator(): User
{
    return User::factory()->create(['is_super_admin' => true]);
}

test('a super admin sees every organization and who runs it', function () {
    $team = Team::factory()->create(['name' => 'TexasRenters.com']);
    $admin = User::factory()->create(['name' => 'Ada Admin']);

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs(operator())
        ->get(route('organizations.index', ['search' => 'TexasRenters.com']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organizations/Index')
            ->has('organizations', 1)
            ->where('organizations.0.name', 'TexasRenters.com')
            ->has('organizations.0.members', 1)
            ->where('organizations.0.members.0.roleLabel', 'Admin'));
});

test('somebody who administers no organization cannot open the directory', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $member->teams()->detach();

    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->get(route('organizations.index'))
        ->assertForbidden();
});

test('an organization admin sees only the organizations they administer', function () {
    $admin = User::factory()->create();
    $admin->teams()->detach();

    $runs = Team::factory()->create(['name' => 'Runs This']);
    $belongsTo = Team::factory()->create(['name' => 'Only A Member']);
    Team::factory()->create(['name' => 'Someone Else']);

    $runs->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $belongsTo->members()->attach($admin, ['role' => TeamRole::Member->value]);

    $this->actingAs($admin)
        ->get(route('organizations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canViewOrganizations', true)
            ->has('organizations', 1)
            ->where('organizations.0.name', 'Runs This'));
});

test('an organization admin manages the members of their own organization', function () {
    Notification::fake();

    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($admin)
        ->post(route('organizations.members.store', ['team' => $team->slug]), [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'role' => TeamRole::Member->value,
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->patch(route('organizations.members.update', ['team' => $team->slug, 'user' => $member->id]), [
            'role' => TeamRole::Admin->value,
        ])
        ->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'new@example.com')->sole()->teamRole($team))->toBe(TeamRole::Member)
        ->and($member->fresh()->teamRole($team))->toBe(TeamRole::Admin);

    $this->actingAs($admin)
        ->delete(route('organizations.members.destroy', ['team' => $team->slug, 'user' => $member->id]))
        ->assertSessionHasNoErrors();

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();
});

test('an organization admin invites an existing account rather than adding it outright', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $person = User::factory()->create(['email' => 'ada@example.com']);

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin)
        ->post(route('organizations.members.store', ['team' => $team->slug]), [
            'email' => 'ada@example.com',
            'role' => TeamRole::Member->value,
        ])
        ->assertSessionHasErrors('email');

    expect($person->fresh()->belongsToTeam($team))->toBeFalse();
});

test('an organization admin cannot change the members of an organization they do not administer', function () {
    $admin = User::factory()->create();
    $other = Team::factory()->create();
    $otherMember = User::factory()->create(['email' => 'existing@example.com']);

    $other->members()->attach($admin, ['role' => TeamRole::Member->value]);
    $other->members()->attach($otherMember, ['role' => TeamRole::Member->value]);

    $this->actingAs($admin)
        ->post(route('organizations.members.store', ['team' => $other->slug]), [
            'email' => 'existing@example.com',
            'role' => TeamRole::Admin->value,
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->patch(route('organizations.members.update', ['team' => $other->slug, 'user' => $admin->id]), [
            'role' => TeamRole::Admin->value,
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->delete(route('organizations.members.destroy', ['team' => $other->slug, 'user' => $otherMember->id]))
        ->assertForbidden();

    expect($admin->fresh()->teamRole($other))->toBe(TeamRole::Member)
        ->and($otherMember->fresh()->belongsToTeam($other))->toBeTrue();
});

test('an existing account is added to an organization as an admin', function () {
    Notification::fake();

    $team = Team::factory()->create();
    $person = User::factory()->create(['email' => 'ada@example.com']);

    $this->actingAs(operator())
        ->post(route('organizations.members.store', ['team' => $team->slug]), [
            'email' => 'ada@example.com',
            'role' => TeamRole::Admin->value,
        ])
        ->assertRedirect();

    expect($person->fresh()->teamRole($team))->toBe(TeamRole::Admin);

    // Nothing was created, so nothing to set a password for.
    Notification::assertNothingSent();
});

test('an unknown address gets an account and a link to set a password', function () {
    Notification::fake();

    $team = Team::factory()->create();

    $this->actingAs(operator())
        ->post(route('organizations.members.store', ['team' => $team->slug]), [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'role' => TeamRole::Member->value,
        ])
        ->assertRedirect();

    $created = User::query()->where('email', 'new@example.com')->sole();

    expect($created->teamRole($team))->toBe(TeamRole::Member);

    Notification::assertSentTo($created, ResetPassword::class);
});

test('a new account needs a name', function () {
    $team = Team::factory()->create();

    $this->actingAs(operator())
        ->post(route('organizations.members.store', ['team' => $team->slug]), [
            'email' => 'nameless@example.com',
            'role' => TeamRole::Member->value,
        ])
        ->assertSessionHasErrors('name');
});

test('somebody already in the organization is not added twice', function () {
    $team = Team::factory()->create();
    $person = User::factory()->create(['email' => 'ada@example.com']);

    $team->members()->attach($person, ['role' => TeamRole::Member->value]);

    $this->actingAs(operator())
        ->post(route('organizations.members.store', ['team' => $team->slug]), [
            'email' => 'ada@example.com',
            'role' => TeamRole::Admin->value,
        ])
        ->assertSessionHasErrors('email');

    expect($team->memberships()->where('user_id', $person->id)->count())->toBe(1);
});

test('the last administrator is protected from demotion and removal', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs(operator())
        ->patch(route('organizations.members.update', ['team' => $team->slug, 'user' => $admin->id]), [
            'role' => TeamRole::Member->value,
        ])
        ->assertSessionHasErrors('role');

    $this->actingAs(operator())
        ->delete(route('organizations.members.destroy', ['team' => $team->slug, 'user' => $admin->id]))
        ->assertSessionHasErrors('member');

    expect($admin->fresh()->teamRole($team))->toBe(TeamRole::Admin);
});

test('a member is removed from the organization without deleting the account', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs(operator())
        ->delete(route('organizations.members.destroy', ['team' => $team->slug, 'user' => $member->id]))
        ->assertRedirect();

    expect($member->fresh())->not->toBeNull()
        ->and($member->fresh()->belongsToTeam($team))->toBeFalse();
});

test('an organization admin cannot delete the organization', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin)
        ->delete(route('teams.destroy', ['team' => $team->slug]), ['name' => $team->name])
        ->assertForbidden();

    expect(Team::query()->whereKey($team->id)->exists())->toBeTrue();
});

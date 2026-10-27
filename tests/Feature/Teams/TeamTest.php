<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\AccountCreated;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Give the user standing to create organizations: ownership of a real one.
 *
 * A personal organization does not count, so the factory's own team is not
 * enough — see TeamPolicy::create.
 */
function ownerOfAnOrganization(User $user): Team
{
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    return $team;
}

test('the teams index page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('teams.index'));

    $response->assertOk();
});

test('teams can be created', function () {
    $user = User::factory()->create();
    ownerOfAnOrganization($user);

    $response = $this
        ->actingAs($user)
        ->post(route('teams.store'), [
            'name' => 'Test Team',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('teams', ['name' => 'Test Team']);
});

test('team slug uses next available suffix', function () {
    $user = User::factory()->create();
    ownerOfAnOrganization($user);

    Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Team::factory()->create(['name' => 'Acme One', 'slug' => 'acme-1']);
    Team::factory()->create(['name' => 'Acme Ten', 'slug' => 'acme-10']);

    $this
        ->actingAs($user)
        ->post(route('teams.store'), [
            'name' => 'Acme',
        ]);

    $this->assertDatabaseHas('teams', [
        'name' => 'Acme',
        'slug' => 'acme-11',
    ]);
});

test('the team edit page can be rendered', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $response = $this
        ->actingAs($user)
        ->get(route('teams.edit', $team));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/Edit')
            ->where('team.slug', $team->slug)
            ->missing('members'),
        );
});

test('the team members page can be rendered', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $this->actingAs($user)
        ->get(route('teams.members.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/Members')
            ->where('members.0.role', TeamRole::Admin->value)
            ->where('members.0.role_label', TeamRole::Admin->label()),
        );
});

test('someone outside the organization cannot see its members', function () {
    $team = Team::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('teams.members.index', $team))
        ->assertForbidden();
});

test('teams can be updated by owners', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Original Name']);

    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $response = $this
        ->actingAs($user)
        ->patch(route('teams.update', $team), [
            'name' => 'Updated Name',
        ]);

    $response->assertRedirect(route('teams.edit', $team->fresh()));

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'name' => 'Updated Name',
    ]);
});

test('teams cannot be updated by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->patch(route('teams.update', $team), [
            'name' => 'Updated Name',
        ]);

    $response->assertForbidden();
});

test('teams can be deleted by a super admin', function () {
    $user = User::factory()->create(['is_super_admin' => true]);
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);
});

test('team deletion requires name confirmation', function () {
    $user = User::factory()->create(['is_super_admin' => true]);
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => 'Wrong Name',
        ]);

    $response->assertSessionHasErrors('name');

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'deleted_at' => null,
    ]);
});

test('deleting current team switches to alphabetically first remaining team', function () {
    $user = User::factory()->create(['name' => 'Mike', 'is_super_admin' => true]);

    $zuluTeam = Team::factory()->create(['name' => 'Zulu Team']);
    $zuluTeam->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $alphaTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $alphaTeam->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $betaTeam = Team::factory()->create(['name' => 'Beta Team']);
    $betaTeam->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $user->update(['current_team_id' => $zuluTeam->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $zuluTeam), [
            'name' => $zuluTeam->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $zuluTeam->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($alphaTeam->id);
});

test('deleting current team falls back to another organization', function () {
    $user = User::factory()->create(['is_super_admin' => true]);
    $ownTeam = $user->fallbackTeam();
    $team = Team::factory()->create(['name' => 'Zulu Team']);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $user->update(['current_team_id' => $team->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($ownTeam->id);
});

test('deleting non current team leaves current team unchanged', function () {
    $user = User::factory()->create(['is_super_admin' => true]);
    $ownTeam = $user->fallbackTeam();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $user->update(['current_team_id' => $ownTeam->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($ownTeam->id);
});

test('members can leave an organization', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('teams.leave', $team));

    $response->assertRedirect(route('teams.index'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => "You left the organization \"{$team->name}\""]);

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();
});

test('leaving current team switches to alphabetically first remaining team', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Mike']);

    $zuluTeam = Team::factory()->create(['name' => 'Zulu Team']);
    $zuluTeam->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $zuluTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $alphaTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $alphaTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $betaTeam = Team::factory()->create(['name' => 'Beta Team']);
    $betaTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $member->update(['current_team_id' => $zuluTeam->id]);

    $response = $this
        ->actingAs($member)
        ->delete(route('teams.leave', $zuluTeam));

    $response->assertRedirect(route('teams.index'));

    expect($member->fresh()->belongsToTeam($zuluTeam))->toBeFalse();
    expect($member->fresh()->current_team_id)->toEqual($alphaTeam->id);
});

test('the only administrator cannot leave', function () {
    $user = User::factory()->create();
    $team = $user->fallbackTeam();

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.leave', $team));

    $response->assertForbidden();

    expect($user->fresh()->belongsToTeam($team))->toBeTrue();
});

test('the last admin cannot leave their organization', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.leave', $team));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToTeam($team))->toBeTrue();
});

test('users cannot leave teams they dont belong to', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.leave', $team));

    $response->assertForbidden();
});

test('deleting an organization moves everyone in it to another they belong to', function () {
    $owner = User::factory()->create(['is_super_admin' => true]);
    $member = User::factory()->create();

    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $owner->update(['current_team_id' => $team->id]);
    $member->update(['current_team_id' => $team->id]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    expect($member->fresh()->current_team_id)->toEqual($member->fallbackTeam()->id);
});

test('an organization is not deleted by the admin who runs it', function () {
    $user = User::factory()->create();

    $team = $user->fallbackTeam();

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertForbidden();

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'deleted_at' => null,
    ]);
});

test('teams cannot be deleted by non owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertForbidden();
});

test('users can switch teams', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($user)
        ->post(route('teams.switch', $team));

    $response->assertRedirect();

    expect($user->fresh()->current_team_id)->toEqual($team->id);
});

test('users cannot switch to team they dont belong to', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('teams.switch', $team));

    $response->assertForbidden();
});

test('guests cannot access teams', function () {
    $response = $this->get(route('teams.index'));

    $response->assertRedirect(route('login'));
});

test('a plain member cannot create another organization', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();

    // Detached from the organization the factory made them an admin of.
    $member->teams()->detach();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $this->actingAs($member)
        ->post(route('teams.store'), ['name' => 'Side Project'])
        ->assertForbidden();

    $this->assertDatabaseMissing('teams', ['name' => 'Side Project']);
});

test('an admin can create another organization', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin)
        ->post(route('teams.store'), ['name' => 'Second Org'])
        ->assertRedirect();

    $this->assertDatabaseHas('teams', ['name' => 'Second Org']);
});

test('an admin of an organization can create another', function () {
    // The factory gives every account an organization it administers.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('teams.store'), ['name' => 'Second Org'])
        ->assertRedirect();

    $this->assertDatabaseHas('teams', ['name' => 'Second Org']);
});

test('the create organization entry is hidden from a member', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();

    $member->teams()->detach();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $this->actingAs($member)
        ->get(route('teams.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canCreateTeam', false));
});

test('an organization admin cannot delete their organization', function () {
    $admin = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    // Creating and destroying organizations belongs to the super admin who
    // looks after all of them; an admin runs the one they are in.
    $this->actingAs($admin)
        ->delete(route('teams.destroy', $team), ['name' => $team->name])
        ->assertForbidden();

    $this->assertNotSoftDeleted('teams', ['id' => $team->id]);
});

test('a member of more than one organization can open the switcher', function () {
    $user = User::factory()->create();
    $team = ownerOfAnOrganization($user);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canSwitchTeams', true)
            ->has('teams', 2));
});

test('a member of a single organization cannot open the switcher', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('canSwitchTeams', false));
});

test('a super admin creates an organization with a new owner, without joining it', function () {
    Notification::fake();
    $operator = User::factory()->create(['is_super_admin' => true]);
    $previousTeamId = $operator->current_team_id;

    $this->actingAs($operator)
        ->post(route('teams.store'), [
            'name' => 'Fresh Org',
            'owner_email' => 'Owner@Fresh.test',
            'owner_name' => 'Olive Owner',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('teams.edit', ['team' => 'fresh-org']));

    $team = Team::where('name', 'Fresh Org')->firstOrFail();
    $owner = User::where('email', 'owner@fresh.test')->firstOrFail();

    expect($team->members()->pluck('users.id')->all())->toBe([$owner->id])
        ->and($owner->teamRole($team))->toBe(TeamRole::Admin)
        ->and($operator->fresh()->current_team_id)->toBe($previousTeamId);

    Notification::assertSentTo($owner, AccountCreated::class);
});

test('an existing account named as owner is added as its admin', function () {
    Notification::fake();
    $operator = User::factory()->create(['is_super_admin' => true]);
    $existing = User::factory()->create(['email' => 'dana@acme.test']);

    $this->actingAs($operator)
        ->post(route('teams.store'), ['name' => 'Acme Two', 'owner_email' => 'DANA@acme.test'])
        ->assertSessionHasNoErrors();

    $team = Team::where('name', 'Acme Two')->firstOrFail();

    expect($existing->teamRole($team))->toBe(TeamRole::Admin);
    Notification::assertNothingSentTo($existing);
});

test('a super admin must name an owner, and a new one needs a name', function () {
    $operator = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($operator)
        ->post(route('teams.store'), ['name' => 'No Owner'])
        ->assertSessionHasErrors('owner_email');

    $this->actingAs($operator)
        ->post(route('teams.store'), ['name' => 'No Owner', 'owner_email' => 'nobody@new.test'])
        ->assertSessionHasErrors('owner_name');

    $this->assertDatabaseMissing('teams', ['name' => 'No Owner']);
});

test('another super admin cannot be made an organizations owner', function () {
    $operator = User::factory()->create(['is_super_admin' => true]);
    $other = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($operator)
        ->post(route('teams.store'), ['name' => 'Bad Owner', 'owner_email' => $other->email])
        ->assertSessionHasErrors('owner_email');

    $this->assertDatabaseMissing('teams', ['name' => 'Bad Owner']);
});

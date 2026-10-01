<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;

test('team invitations can be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.invitations.store', $team), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
        ]);

    $response->assertRedirect(route('teams.members.index', $team));

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member->value,
    ]);
});

test('invitation email points at the one-click join link', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'unknown@example.com',
        'invited_by' => $owner->id,
    ]);

    $mail = (new TeamInvitationNotification($invitation))->toMail((object) []);

    expect($mail->actionUrl)->toBe(route('invitations.join', ['invitation' => $invitation->code]))
        ->and($mail->actionText)->toBe('Join Now');
});

test('joining from the email creates the account, signs the invitee in and accepts', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'newcomer@example.com',
        'role' => TeamRole::Member->value,
        'invited_by' => $owner->id,
    ]);

    $this->post(route('invitations.join.store', ['invitation' => $invitation->code]))
        ->assertRedirect(route('dashboard', ['current_team' => $team->slug]));

    $user = User::where('email', 'newcomer@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->current_team_id)->toBe($team->id)
        ->and($team->fresh()->members->contains($user))->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

test('joining gives the invitee working hours rather than an empty calendar', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'newcomer@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->post(route('invitations.join.store', ['invitation' => $invitation->code]));

    $user = User::where('email', 'newcomer@example.com')->first();

    expect($user->availabilitySchedules()->exists())->toBeTrue();
});

/*
 * Mail scanners open links in an email before the invitee does. Opening the
 * link must leave the invitation usable and nobody signed in.
 */
test('opening the join link only shows the confirmation page', function () {
    $owner = User::factory()->create(['name' => 'Dana Reed']);
    $team = Team::factory()->create(['name' => 'Leasing']);

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'newcomer@example.com',
        'role' => TeamRole::Member->value,
        'invited_by' => $owner->id,
    ]);

    $this->get(route('invitations.join', ['invitation' => $invitation->code]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/JoinInvitation')
            ->where('invitation.code', $invitation->code)
            ->where('invitation.teamName', 'Leasing')
            ->where('invitation.inviterName', 'Dana Reed'));

    $this->assertGuest();
    expect(User::where('email', 'newcomer@example.com')->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

/*
 * The link is a bearer credential, so it must stop working the moment it has
 * been used -- otherwise a forwarded email is a permanent way in.
 */
test('a join link cannot be used twice', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'newcomer@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->post(route('invitations.join.store', ['invitation' => $invitation->code]));

    $this->post(route('logout'));

    $this->post(route('invitations.join.store', ['invitation' => $invitation->code]))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect(User::where('email', 'newcomer@example.com')->count())->toBe(1);
});

test('an expired join link is refused', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'newcomer@example.com',
        'invited_by' => $owner->id,
        'expires_at' => now()->subDay(),
    ]);

    $this->get(route('invitations.join', ['invitation' => $invitation->code]))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect(User::where('email', 'newcomer@example.com')->exists())->toBeFalse();
});

/*
 * An existing account may hold a password and a second factor. A link sitting
 * in an inbox must not be enough to walk past either.
 */
test('an address that already has an account is sent to sign in, not signed in', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $existing = User::factory()->create(['email' => 'member@example.com']);

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $existing->email,
        'invited_by' => $owner->id,
    ]);

    $this->get(route('invitations.join', ['invitation' => $invitation->code]))
        ->assertRedirect(route('login', ['invitation' => $invitation->code]));

    $this->assertGuest();
    expect($invitation->fresh()->accepted_at)->toBeNull();
});

test('team invitations can be created by admins', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $response = $this
        ->actingAs($admin)
        ->post(route('teams.invitations.store', $team), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
        ]);

    $response->assertRedirect(route('teams.members.index', $team));
});

test('existing team members cannot be invited', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.invitations.store', $team), [
            'email' => 'member@example.com',
            'role' => TeamRole::Member->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('duplicate invitations cannot be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.invitations.store', $team), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('team invitations cannot be created by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->post(route('teams.invitations.store', $team), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
        ]);

    $response->assertForbidden();
});

test('team invitations can be cancelled by owners', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.invitations.destroy', [$team, $invitation]));

    $response->assertRedirect(route('teams.members.index', $team));

    $this->assertDatabaseMissing('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations can be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertRedirect(route('dashboard'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation accepted.']);

    expect($invitedUser->fresh()->belongsToTeam($team))->toBeTrue();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('switching your email to an invitee\'s address does not let you accept their invitation', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $intruder = User::factory()->create(['email' => 'intruder@example.com']);

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'new-admin@example.com',
        'role' => TeamRole::Admin,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($intruder)->patch(route('profile.update'), [
        'name' => $intruder->name,
        'email' => 'new-admin@example.com',
    ]);

    $this->actingAs($intruder->fresh())
        ->post(route('invitations.accept', $invitation))
        ->assertSessionHasErrors('invitation');

    expect($intruder->fresh()->belongsToTeam($team))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('team invitations can be declined by the invited user', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations cannot be declined by uninvited user', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('accepted team invitations cannot be declined', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations cannot be accepted by uninvited user', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($uninvitedUser->fresh()->belongsToTeam($team))->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($invitedUser->fresh()->belongsToTeam($team))->toBeFalse();
});

test('a plain member is not shown pending invitations or their join codes', function () {
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'new-admin@example.com',
        'role' => TeamRole::Admin,
        'invited_by' => $admin->id,
    ]);

    $this->actingAs($member)
        ->get(route('teams.members.index', $team))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('invitations', 0));
});

test('an admin still sees pending invitations on the members page', function () {
    $admin = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'new-member@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->get(route('teams.members.index', $team))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('invitations', 1)
            ->where('invitations.0.code', $invitation->code));
});

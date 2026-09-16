<?php

use App\Enums\TeamRole;
use App\Models\AvailabilitySchedule;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\AccountCreated;
use Illuminate\Support\Facades\Notification;

test('a super admin creates a user directly inside an organization', function () {
    Notification::fake();

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $team = Team::factory()->create();

    $response = $this
        ->actingAs($superAdmin)
        ->post(route('teams.members.store', $team), [
            'name' => 'New Hire',
            'email' => 'new.hire@example.com',
            'role' => TeamRole::Member->value,
        ]);

    $response->assertRedirect(route('teams.edit', $team));

    $user = User::where('email', 'new.hire@example.com')->firstOrFail();

    expect($team->members()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and($team->members()->where('user_id', $user->id)->first()->pivot->role)->toEqual(TeamRole::Member)
        ->and($user->current_team_id)->toEqual($team->id);
});

test('the created user gets default availability rather than an empty calendar', function () {
    Notification::fake();

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $team = Team::factory()->create();

    $this->actingAs($superAdmin)->post(route('teams.members.store', $team), [
        'name' => 'New Hire',
        'email' => 'new.hire@example.com',
        'role' => TeamRole::Member->value,
    ]);

    $user = User::where('email', 'new.hire@example.com')->firstOrFail();

    expect(AvailabilitySchedule::where('user_id', $user->id)->exists())->toBeTrue();
});

test('the created user is emailed a link to set their own password', function () {
    Notification::fake();

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $team = Team::factory()->create();

    $this->actingAs($superAdmin)->post(route('teams.members.store', $team), [
        'name' => 'New Hire',
        'email' => 'new.hire@example.com',
        'role' => TeamRole::Member->value,
    ]);

    $user = User::where('email', 'new.hire@example.com')->firstOrFail();

    Notification::assertSentTo($user, AccountCreated::class);
});

/**
 * The account is placed in an existing organization, so a personal one would
 * only clutter the switcher. This is the difference from self-registration.
 */
test('creating a user in an organization puts them in that one alone', function () {
    Notification::fake();

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $team = Team::factory()->create();

    $this->actingAs($superAdmin)->post(route('teams.members.store', $team), [
        'name' => 'New Hire',
        'email' => 'new.hire@example.com',
        'role' => TeamRole::Member->value,
    ]);

    $user = User::where('email', 'new.hire@example.com')->firstOrFail();

    expect($user->teams()->count())->toBe(1)
        ->and($user->teams()->first()->is($team))->toBeTrue();
});

test('a member cannot create a user directly', function () {
    Notification::fake();

    // Running the organization is what allows it; being in one is not.
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->post(route('teams.members.store', $team), [
            'name' => 'New Hire',
            'email' => 'new.hire@example.com',
            'role' => TeamRole::Member->value,
        ])
        ->assertForbidden();

    expect(User::where('email', 'new.hire@example.com')->exists())->toBeFalse();
});

test('an organization admin creates a user directly', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin)
        ->post(route('teams.members.store', $team), [
            'name' => 'New Hire',
            'email' => 'new.hire@example.com',
            'role' => TeamRole::Member->value,
        ])
        ->assertRedirect();

    expect(User::where('email', 'new.hire@example.com')->sole()->teamRole($team))
        ->toBe(TeamRole::Member);
});

test('a user cannot be created with an address that already has an account', function () {
    Notification::fake();

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $team = Team::factory()->create();
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($superAdmin)
        ->post(route('teams.members.store', $team), [
            'name' => 'Duplicate',
            'email' => 'taken@example.com',
            'role' => TeamRole::Member->value,
        ])
        ->assertSessionHasErrors('email');
});

test('a user cannot be created with a role that does not exist', function () {
    Notification::fake();

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $team = Team::factory()->create();

    // 'owner' was a role until organizations were flattened onto admins.
    $this->actingAs($superAdmin)
        ->post(route('teams.members.store', $team), [
            'name' => 'Usurper',
            'email' => 'usurper@example.com',
            'role' => 'owner',
        ])
        ->assertSessionHasErrors('role');
});

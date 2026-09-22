<?php

use App\Enums\TeamRole;
use App\Models\ApiKey;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    // Every user is an admin of their own organization, so this one starts
    // out able to issue keys for it.
    $this->admin = User::factory()->create();
    $this->org = $this->admin->currentTeam;

    $this->superAdmin = User::factory()->create(['is_super_admin' => true]);
});

/** A user who belongs to an organization without running it. */
function member(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    return $user;
}

test('an organization admin can issue a key for their own organization', function () {
    $this->actingAs($this->admin)
        ->post(route('api-keys.store'), ['name' => 'Client portal'])
        ->assertRedirect(route('api-keys.index'));

    expect(ApiKey::query()->firstOrFail()->team_id)->toBe($this->org->id);
});

test('a member who does not run the organization cannot', function () {
    $member = member($this->org);

    $this->actingAs($member)->get(route('api-keys.index'))->assertForbidden();
    $this->actingAs($member)->post(route('api-keys.store'), ['name' => 'Mine'])->assertForbidden();
});

test('an admin sees only their own organization keys', function () {
    ApiKey::issue('Mine', $this->admin, $this->org);
    ApiKey::issue('Someone else', null, Team::factory()->create());
    ApiKey::issue('System wide', $this->superAdmin, null);

    $this->actingAs($this->admin)
        ->get(route('api-keys.index'))
        ->assertInertia(fn ($page) => $page->has('keys', 1)->where('keys.0.name', 'Mine'));
});

test('a super admin sees every key', function () {
    ApiKey::issue('Mine', $this->admin, $this->org);
    ApiKey::issue('Someone else', null, Team::factory()->create());

    $this->actingAs($this->superAdmin)
        ->get(route('api-keys.index'))
        ->assertInertia(fn ($page) => $page->has('keys', 2));
});

test('an admin cannot revoke another organization key', function () {
    ['key' => $theirs] = ApiKey::issue('Theirs', null, Team::factory()->create());

    $this->actingAs($this->admin)
        ->delete(route('api-keys.destroy', $theirs))
        ->assertForbidden();

    expect($theirs->fresh()->isRevoked())->toBeFalse();
});

test('only a super admin can issue a key that speaks for every organization', function () {
    $this->actingAs($this->admin)
        ->post(route('api-keys.store'), ['name' => 'Sneaky', 'system_wide' => true]);

    // Asking for one as an ordinary admin still scopes it to their own.
    expect(ApiKey::query()->firstOrFail()->team_id)->toBe($this->org->id);

    $this->actingAs($this->superAdmin)
        ->post(route('api-keys.store'), ['name' => 'Everything', 'system_wide' => true]);

    expect(ApiKey::query()->where('name', 'Everything')->firstOrFail()->team_id)->toBeNull();
});

test('issuing a key shows the secret exactly once', function () {
    $this->actingAs($this->admin)->post(route('api-keys.store'), ['name' => 'Client portal']);

    $this->actingAs($this->admin)
        ->get(route('api-keys.index'))
        ->assertInertia(fn ($page) => $page->has('issued.token')->where('issued.name', 'Client portal'));

    $this->actingAs($this->admin)
        ->get(route('api-keys.index'))
        ->assertInertia(fn ($page) => $page->where('issued', null));
});

test('the key list never carries the hash', function () {
    ApiKey::issue('Client portal', $this->admin, $this->org);

    $this->actingAs($this->admin)
        ->get(route('api-keys.index'))
        ->assertInertia(fn ($page) => $page
            ->has('keys.0.prefix')
            ->missing('keys.0.token_hash')
            ->missing('keys.0.token'));
});

test('a key can be revoked', function () {
    ['key' => $key] = ApiKey::issue('Client portal', $this->admin, $this->org);

    $this->actingAs($this->admin)->delete(route('api-keys.destroy', $key))->assertRedirect();

    expect($key->fresh()->isRevoked())->toBeTrue();
});

test('a name is required', function () {
    $this->actingAs($this->admin)
        ->post(route('api-keys.store'), ['name' => ''])
        ->assertSessionHasErrors('name');
});

test('two keys never share a prefix', function () {
    ['token' => $first] = ApiKey::issue('One');
    ['token' => $second] = ApiKey::issue('Two');

    expect(explode('.', $first)[0])->not->toBe(explode('.', $second)[0]);
});

test('a token with the right prefix but the wrong secret is refused', function () {
    ['token' => $token] = ApiKey::issue('Client portal');
    $prefix = explode('.', $token)[0];

    expect(ApiKey::findByToken($prefix.'.wrong-secret'))->toBeNull()
        ->and(ApiKey::findByToken($token))->not->toBeNull();
});

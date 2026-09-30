<?php

use App\Enums\TeamRole;
use App\Enums\WebhookScope;
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

test('a super admin issues for whichever organization they are looking after', function () {
    $other = Team::factory()->create(['name' => 'Texas Renters']);
    $other->members()->attach($this->superAdmin, ['role' => TeamRole::Admin->value]);
    $this->superAdmin->switchTeam($other);

    // Switching organization is how you act on another one here, the same as
    // everything else about it.
    $this->actingAs($this->superAdmin)->post(route('api-keys.store'), ['name' => 'Client portal']);

    expect(ApiKey::query()->firstOrFail()->team_id)->toBe($other->id);
});

test('a revoked key can be deleted', function () {
    ['key' => $key] = ApiKey::issue('Client portal', $this->admin, $this->org);
    $this->actingAs($this->admin)->delete(route('api-keys.destroy', $key));

    $this->actingAs($this->admin)
        ->delete(route('api-keys.forget', $key))
        ->assertRedirect();

    expect(ApiKey::query()->whereKey($key->id)->exists())->toBeFalse();
});

test('a key still in use cannot be deleted without revoking it first', function () {
    ['key' => $key] = ApiKey::issue('Client portal', $this->admin, $this->org);

    // One stray click must not sweep away a key something is relying on.
    $this->actingAs($this->admin)
        ->delete(route('api-keys.forget', $key))
        ->assertStatus(422);

    expect(ApiKey::query()->whereKey($key->id)->exists())->toBeTrue();
});

test('an admin cannot delete another organization revoked key', function () {
    ['key' => $theirs] = ApiKey::issue('Theirs', null, Team::factory()->create());
    $theirs->forceFill(['revoked_at' => now()])->save();

    $this->actingAs($this->admin)
        ->delete(route('api-keys.forget', $theirs))
        ->assertForbidden();

    expect(ApiKey::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

test('an admin can point a key at a webhook endpoint, and sees its secret once', function () {
    ['key' => $key] = ApiKey::issue('TEXRA', $this->admin, $this->org);

    $this->actingAs($this->admin)
        ->patch(route('api-keys.update', $key), ['name' => 'TEXRA CRM', 'webhook_url' => 'https://crm.example.com/api/bookings/webhook'])
        ->assertRedirect(route('api-keys.index'))
        ->assertSessionHas('issued_webhook_secret.name', 'TEXRA CRM');

    $secret = session('issued_webhook_secret')['secret'];
    $key->refresh();

    expect($key->name)->toBe('TEXRA CRM')
        ->and($key->webhook_url)->toBe('https://crm.example.com/api/bookings/webhook')
        ->and($key->webhook_secret)->toBe($secret)
        // Encrypted at rest: the column never holds the secret itself.
        ->and($key->getRawOriginal('webhook_secret'))->not->toBe($secret);

    $this->actingAs($this->admin)
        ->get(route('api-keys.index'))
        ->assertInertia(fn ($page) => $page
            ->where('keys.0.webhookUrl', 'https://crm.example.com/api/bookings/webhook')
            ->missing('keys.0.webhookSecret'));
});

test('moving the endpoint keeps the secret, and only rotating replaces it', function () {
    ['key' => $key] = ApiKey::issue('TEXRA', $this->admin, $this->org);
    $first = $key->pointWebhookAt('https://old.example.com/hook');

    $this->actingAs($this->admin)
        ->patch(route('api-keys.update', $key), ['name' => 'TEXRA', 'webhook_url' => 'https://new.example.com/hook'])
        ->assertSessionMissing('issued_webhook_secret');

    expect($key->fresh()->webhook_secret)->toBe($first);

    $this->actingAs($this->admin)
        ->patch(route('api-keys.update', $key), ['name' => 'TEXRA', 'webhook_url' => 'https://new.example.com/hook', 'rotate_secret' => true])
        ->assertSessionHas('issued_webhook_secret');

    expect($key->fresh()->webhook_secret)->not->toBe($first);
});

test('clearing the endpoint stops the webhooks and drops the secret', function () {
    ['key' => $key] = ApiKey::issue('TEXRA', $this->admin, $this->org);
    $key->pointWebhookAt('https://crm.example.com/hook');

    $this->actingAs($this->admin)
        ->patch(route('api-keys.update', $key), ['name' => 'TEXRA', 'webhook_url' => null]);

    expect($key->fresh()->only('webhook_url', 'webhook_secret'))->toBe(['webhook_url' => null, 'webhook_secret' => null]);
});

test('an endpoint must be a web address', function () {
    ['key' => $key] = ApiKey::issue('TEXRA', $this->admin, $this->org);

    $this->actingAs($this->admin)
        ->patch(route('api-keys.update', $key), ['name' => 'TEXRA', 'webhook_url' => 'ftp://crm.example.com/hook'])
        ->assertSessionHasErrors('webhook_url');
});

test('an admin cannot edit another organization key, or a revoked one', function () {
    ['key' => $theirs] = ApiKey::issue('Theirs', null, Team::factory()->create());

    $this->actingAs($this->admin)
        ->patch(route('api-keys.update', $theirs), ['name' => 'Mine now', 'webhook_url' => 'https://evil.example.com'])
        ->assertForbidden();

    ['key' => $revoked] = ApiKey::issue('Old', $this->admin, $this->org);
    $revoked->forceFill(['revoked_at' => now()])->save();

    $this->actingAs($this->admin)
        ->patch(route('api-keys.update', $revoked), ['name' => 'Old', 'webhook_url' => 'https://crm.example.com/hook'])
        ->assertStatus(422);

    expect($theirs->fresh()->webhook_url)->toBeNull()
        ->and($revoked->fresh()->webhook_url)->toBeNull();
});

test('a member who does not run the organization cannot edit its keys', function () {
    ['key' => $key] = ApiKey::issue('TEXRA', $this->admin, $this->org);

    $this->actingAs(member($this->org))
        ->patch(route('api-keys.update', $key), ['name' => 'TEXRA', 'webhook_url' => 'https://crm.example.com/hook'])
        ->assertForbidden();
});

test('an admin can choose that a key hears about every booking', function () {
    ['key' => $key] = ApiKey::issue('Operations', $this->admin, $this->org);

    $this->actingAs($this->admin)
        ->patch(route('api-keys.update', $key), ['name' => 'Operations', 'webhook_url' => 'https://ops.example.com/hook', 'webhook_scope' => 'all']);

    expect($key->fresh()->webhook_scope)->toBe(WebhookScope::All);

    $this->actingAs($this->admin)
        ->patch(route('api-keys.update', $key), ['name' => 'Operations', 'webhook_url' => 'https://ops.example.com/hook', 'webhook_scope' => 'everything'])
        ->assertSessionHasErrors('webhook_scope');
});

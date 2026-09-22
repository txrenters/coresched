<?php

use App\Models\ApiKey;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_super_admin' => true]);
    $this->member = User::factory()->create(['is_super_admin' => false]);
});

test('a super admin can see the keys page', function () {
    $this->actingAs($this->admin)
        ->get(route('api-keys.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/ApiKeys'));
});

test('an ordinary user cannot', function () {
    // A key reaches every booking page in the system, not just their own.
    $this->actingAs($this->member)->get(route('api-keys.index'))->assertForbidden();
    $this->actingAs($this->member)->post(route('api-keys.store'), ['name' => 'Mine'])->assertForbidden();
});

test('issuing a key shows the secret exactly once', function () {
    $this->actingAs($this->admin)
        ->post(route('api-keys.store'), ['name' => 'Client portal'])
        ->assertRedirect(route('api-keys.index'));

    $key = ApiKey::query()->firstOrFail();

    // Shown on the page it redirects to, and nowhere afterwards.
    $this->actingAs($this->admin)
        ->get(route('api-keys.index'))
        ->assertInertia(fn ($page) => $page->has('issued.token')->where('issued.name', 'Client portal'));

    $this->actingAs($this->admin)
        ->get(route('api-keys.index'))
        ->assertInertia(fn ($page) => $page->where('issued', null));

    expect($key->name)->toBe('Client portal');
});

test('the key list never carries the hash', function () {
    ApiKey::issue('Client portal', $this->admin);

    $this->actingAs($this->admin)
        ->get(route('api-keys.index'))
        ->assertInertia(fn ($page) => $page
            ->has('keys.0.prefix')
            ->missing('keys.0.token_hash')
            ->missing('keys.0.token'));
});

test('a key can be revoked', function () {
    ['key' => $key] = ApiKey::issue('Client portal', $this->admin);

    $this->actingAs($this->admin)
        ->delete(route('api-keys.destroy', $key))
        ->assertRedirect();

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

<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.cloudflare_analytics.token' => 'cf-token-123']);
});

test('signed-out visitors to the public pages get the analytics token', function () {
    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('analytics', ['token' => 'cf-token-123']));
});

test('signed-in people are never counted', function () {
    $this->actingAs(User::factory()->create())
        ->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('analytics', null));
});

test('analytics stay off without a token', function () {
    config(['services.cloudflare_analytics.token' => null]);

    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('analytics', null));
});

<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'services.umami.script_url' => 'https://analytics.coresched.com/script.js',
        'services.umami.website_id' => 'site-123',
    ]);
});

test('signed-out visitors to the public pages get the analytics settings', function () {
    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('analytics', [
            'scriptUrl' => 'https://analytics.coresched.com/script.js',
            'websiteId' => 'site-123',
        ]));
});

test('signed-in people are never counted', function () {
    $this->actingAs(User::factory()->create())
        ->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('analytics', null));
});

test('analytics stay off until both settings are filled', function (string $blankKey) {
    config(["services.umami.{$blankKey}" => null]);

    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('analytics', null));
})->with(['script_url', 'website_id']);

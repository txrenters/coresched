<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the legal pages are public at their own urls', function (string $path, string $component) {
    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'terms of service' => ['/termsofservice', 'Terms'],
    'privacy policy' => ['/privacy', 'Privacy'],
]);

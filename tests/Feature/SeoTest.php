<?php

use App\Models\User;

test('the marketing pages are indexable and carry their search and share tags', function (string $routeName) {
    $response = $this->get(route($routeName));

    $response->assertOk()
        ->assertHeaderMissing('X-Robots-Tag')
        ->assertSee('<meta name="description"', false)
        ->assertSee('<link rel="canonical" href="'.route($routeName).'">', false)
        ->assertSee('<meta property="og:image" content="'.asset('og-image.png').'">', false);
})->with(['home', 'pricing', 'terms', 'privacy']);

test('the home page describes the product as software', function () {
    $this->get(route('home'))->assertSee('"@type":"SoftwareApplication"', false);
});

test('every other page is kept out of search indexes', function (string $path) {
    $this->get($path)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
})->with([
    'login' => '/login',
    'missing page' => '/no-such-page',
    'sitemap' => '/sitemap.xml',
]);

test('signed-in pages are kept out of search indexes and carry no share tags', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('appearance.edit'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertDontSee('og:title', false);
});

test('the sitemap lists only the marketing pages', function () {
    $response = $this->get(route('sitemap'));

    $response->assertOk()->assertHeader('Content-Type', 'application/xml');

    $xml = simplexml_load_string($response->getContent());

    $urls = array_map(fn ($url) => (string) $url->loc, iterator_to_array($xml->url, false));

    expect($urls)->toBe([route('home'), route('pricing'), route('terms'), route('privacy')]);
});

/*
 * Under PHP-FPM with short_open_tag on, a "<?" in the template is parsed as
 * PHP and the sitemap 500s. The CLI runs with it off, so this cannot be
 * reproduced in a request test; keep the declaration in the route instead.
 */
test('the sitemap starts with its XML declaration and its template holds no PHP open tag', function () {
    expect($this->get(route('sitemap'))->getContent())->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
        ->and(file_get_contents(resource_path('views/sitemap.blade.php')))->not->toContain('<?');
});

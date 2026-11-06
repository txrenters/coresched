<?php

use App\Models\PageVisit;
use App\Models\User;
use App\Services\Admin\PlatformAnalytics;
use Carbon\CarbonImmutable;

const BROWSER_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15';
const PHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

beforeEach(function () {
    $this->withoutDefer();
});

test('a signed-out visit to a marketing page is counted without storing who it was', function () {
    $this->withHeaders(['User-Agent' => PHONE_UA, 'Referer' => 'https://www.google.com/search?q=coresched'])
        ->get('/pricing')
        ->assertOk();

    $visit = PageVisit::query()->sole();

    expect($visit->path)->toBe('/pricing')
        ->and($visit->referrer_host)->toBe('google.com')
        ->and($visit->device)->toBe('mobile')
        ->and($visit->visitor_hash)->toHaveLength(64)
        ->and($visit->visitor_hash)->not->toContain('127.0.0.1');
});

test('a click from another page of this site has no referrer', function () {
    $this->withHeaders(['User-Agent' => BROWSER_UA, 'Referer' => url('/')])
        ->get('/privacy')
        ->assertOk();

    expect(PageVisit::query()->sole()->referrer_host)->toBeNull();
});

test('signed-in people, bots and prefetches are not counted', function (array $headers, bool $signedIn) {
    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }

    $this->withHeaders(['User-Agent' => BROWSER_UA, ...$headers])->get('/pricing')->assertOk();

    expect(PageVisit::query()->count())->toBe(0);
})->with([
    'signed in' => [[], true],
    'crawler' => [['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'], false],
    'no user agent' => [['User-Agent' => ''], false],
    'inertia prefetch' => [['Purpose' => 'prefetch'], false],
    'browser prefetch' => [['Sec-Purpose' => 'prefetch'], false],
]);

test('pages outside marketing are never counted', function () {
    $this->withHeaders(['User-Agent' => BROWSER_UA])->get(route('login'))->assertOk();

    expect(PageVisit::query()->count())->toBe(0);
});

test('the same visitor counts once a day and cannot be followed to the next day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'UTC'));
    $this->withHeaders(['User-Agent' => BROWSER_UA])->get('/');
    $this->withHeaders(['User-Agent' => BROWSER_UA])->get('/pricing');

    $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00', 'UTC'));
    $this->withHeaders(['User-Agent' => BROWSER_UA])->get('/');

    $hashes = PageVisit::query()->orderBy('id')->pluck('visitor_hash');

    expect($hashes[0])->toBe($hashes[1])
        ->and($hashes[2])->not->toBe($hashes[0]);
});

test('the dashboard sums visitors, top pages, referrers and devices for the last 30 days', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00', 'UTC'));

    PageVisit::factory()->createMany([
        ['path' => '/', 'visitor_hash' => 'a', 'referrer_host' => 'google.com', 'device' => 'mobile', 'created_at' => now()],
        ['path' => '/pricing', 'visitor_hash' => 'a', 'referrer_host' => null, 'device' => 'mobile', 'created_at' => now()],
        ['path' => '/', 'visitor_hash' => 'b', 'referrer_host' => null, 'device' => 'desktop', 'created_at' => now()->subDay()],
        ['path' => '/', 'visitor_hash' => 'c', 'referrer_host' => null, 'device' => 'desktop', 'created_at' => now()->subDays(40)],
    ]);

    $traffic = app(PlatformAnalytics::class)->traffic();

    expect($traffic['visitorsLast30Days'])->toBe(2)
        ->and($traffic['visitorsPrevious30Days'])->toBe(1)
        ->and($traffic['pageViewsLast30Days'])->toBe(3)
        ->and($traffic['visitorsByDay'])->toHaveCount(30)
        ->and(end($traffic['visitorsByDay']))->toBe(['label' => 'Nov 6', 'value' => 1])
        ->and($traffic['topPages'])->toBe([
            ['label' => 'Home', 'value' => 2],
            ['label' => 'Pricing', 'value' => 1],
        ])
        ->and($traffic['topReferrers'])->toBe([['label' => 'google.com', 'value' => 1]])
        ->and($traffic['devices'])->toBe([
            ['label' => 'Mobile', 'value' => 2],
            ['label' => 'Desktop', 'value' => 1],
        ]);
});

test('the admin dashboard shows the website traffic', function () {
    PageVisit::factory()->create();

    $this->actingAs(User::factory()->create(['is_super_admin' => true]))
        ->get(route('admin.organizations.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/Dashboard')
            ->where('analytics.traffic.pageViewsLast30Days', 1));
});

test('traffic counts older than 13 months are pruned', function () {
    $old = PageVisit::factory()->create(['created_at' => now()->subMonths(14)]);
    $recent = PageVisit::factory()->create(['created_at' => now()->subMonths(12)]);

    $this->artisan('model:prune', ['--model' => [PageVisit::class]])->assertSuccessful();

    expect(PageVisit::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(PageVisit::query()->find($old->id))->toBeNull();
});

<?php

use App\Actions\Teams\ResetDemoOrganization;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\PlatformAnnouncement;
use App\Support\Demo;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

function announcer(): User
{
    return User::factory()->create(['is_super_admin' => true]);
}

test('a super admin sends an announcement to every real user and nobody else', function () {
    Notification::fake();
    $people = User::factory()->count(2)->create();
    app(ResetDemoOrganization::class)->handle();
    $admin = announcer();

    $this->actingAs($admin)
        ->post(route('admin.announcements.store'), [
            'title' => 'We are moving to coresched.com',
            'body' => 'Sign in once on the new address tonight.',
            'banner_days' => 3,
        ])
        ->assertRedirect();

    Notification::assertSentTo($people, PlatformAnnouncement::class, fn (PlatformAnnouncement $notification) => $notification->announcement->title === 'We are moving to coresched.com');
    Notification::assertNotSentTo([$admin, Demo::admin()], PlatformAnnouncement::class);
    Notification::assertCount(2);

    $announcement = Announcement::sole();
    expect($announcement->recipients_count)->toBe(2)
        ->and($announcement->hasLiveBanner())->toBeTrue()
        ->and($announcement->created_by)->toBe($admin->id);
});

test('the announcement lands in the bell as an announcement, without email', function () {
    $person = User::factory()->create();

    $this->actingAs(announcer())->post(route('admin.announcements.store'), [
        'title' => 'Heads up',
        'body' => 'Planned maintenance tonight.',
    ]);

    $this->actingAs($person)
        ->getJson(route('notifications.index'))
        ->assertJsonPath('notifications.0.type', 'announcement')
        ->assertJsonPath('notifications.0.title', 'Heads up')
        ->assertJsonPath('notifications.0.body', 'Planned maintenance tonight.');

    expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
});

test('a live banner is shown to signed-in people until it runs out or is ended', function () {
    $person = User::factory()->create();
    $announcement = Announcement::factory()->withBanner(1)->create(['title' => 'Moving tonight']);

    $this->actingAs($person)->get(route('admin.announcements.index'))->assertForbidden();
    $this->actingAs($person)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('announcement.title', 'Moving tonight'));

    $this->actingAs(announcer())
        ->patch(route('admin.announcements.end-banner', $announcement))
        ->assertRedirect();

    $this->actingAs($person)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('announcement', null));

    Announcement::factory()->withBanner(1)->create();
    $this->travel(2)->days();

    $this->actingAs($person)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('announcement', null));
});

test('only super admins can send or end announcements', function () {
    Notification::fake();
    $announcement = Announcement::factory()->withBanner()->create();
    $person = User::factory()->create();

    $this->actingAs($person)
        ->post(route('admin.announcements.store'), ['title' => 'Hi', 'body' => 'There'])
        ->assertForbidden();
    $this->actingAs($person)
        ->patch(route('admin.announcements.end-banner', $announcement))
        ->assertForbidden();

    Notification::assertNothingSent();
    expect($announcement->fresh()->hasLiveBanner())->toBeTrue();
});

test('an announcement needs a title and message, and a banner length from the list', function () {
    $this->actingAs(announcer())
        ->post(route('admin.announcements.store'), ['title' => '', 'body' => '', 'banner_days' => 5])
        ->assertSessionHasErrors(['title', 'body', 'banner_days']);

    expect(Announcement::count())->toBe(0);
});

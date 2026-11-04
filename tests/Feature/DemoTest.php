<?php

use App\Actions\Bookings\NotifyBookingParties;
use App\Actions\Teams\ResetDemoOrganization;
use App\Models\Booking;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Support\Facades\Http;

function demoTeam(): Team
{
    return Team::query()->where('is_demo', true)->sole();
}

function sentMailCount(): int
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count();
}

test('demo:reset builds the demo organization and rebuilds it without touching anyone real', function () {
    $realUser = User::factory()->create();

    $this->artisan('demo:reset')->assertSuccessful();
    $firstTeamId = demoTeam()->id;

    $this->artisan('demo:reset')->assertSuccessful();
    $team = demoTeam();

    expect($team->id)->not->toBe($firstTeamId)
        ->and($team->slug)->toBe(Demo::TEAM_SLUG)
        ->and($team->members()->count())->toBe(5)
        ->and(Booking::query()->where('team_id', $team->id)->count())->toBe(20)
        ->and(Team::query()->whereKey($firstTeamId)->exists())->toBeFalse()
        ->and(User::query()->where('email', Demo::ADMIN_EMAIL)->count())->toBe(1)
        ->and($realUser->fresh())->not->toBeNull();
});

test('the reset refuses to take over a real organization using the demo slug', function () {
    Team::factory()->create(['slug' => Demo::TEAM_SLUG]);

    app(ResetDemoOrganization::class)->handle();
})->throws(RuntimeException::class);

test('try the demo signs a visitor in as the demo admin', function () {
    app(ResetDemoOrganization::class)->handle();

    $this->post(route('demo.store'))
        ->assertRedirect(route('dashboard', ['current_team' => Demo::TEAM_SLUG]));

    $this->assertAuthenticatedAs(Demo::admin());

    $this->get(route('dashboard', ['current_team' => Demo::TEAM_SLUG]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('isDemo', true));
});

test('try the demo does nothing when the demo has not been built', function () {
    $this->from(route('home'))->post(route('demo.store'))->assertRedirect(route('home'));

    $this->assertGuest();
});

test('the demo admin cannot change the shared account or reach outside the demo', function (string $method, string $routeName, array $parameters) {
    app(ResetDemoOrganization::class)->handle();
    $admin = Demo::admin();
    $parameters = array_map(fn ($value) => $value === ':team' ? demoTeam()->slug : $value, $parameters);

    $this->actingAs($admin)
        ->from(route('dashboard', ['current_team' => Demo::TEAM_SLUG]))
        ->{$method}(route($routeName, $parameters), ['name' => 'Hijacked', 'email' => 'someone@real.test', 'role' => 'member'])
        ->assertRedirect(route('dashboard', ['current_team' => Demo::TEAM_SLUG]));

    expect($admin->fresh()->name)->toBe('Alex Morgan')
        ->and(demoTeam()->name)->toBe('Sunrise Property Group')
        ->and(TeamInvitation::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'someone@real.test')->exists())->toBeFalse();
})->with([
    'profile' => ['patch', 'profile.update', []],
    'organization settings' => ['patch', 'teams.update', ['team' => ':team']],
    'invitations' => ['post', 'teams.invitations.store', ['team' => ':team']],
    'new users' => ['post', 'users.store', []],
    'api keys' => ['post', 'api-keys.store', []],
    'calendar connections' => ['get', 'integrations.connect', ['provider' => 'google']],
]);

test('nothing is emailed or sent by webhook for a demo booking', function () {
    config(['services.texra.webhook_url' => 'https://texra.example.com/api/bookings/webhook']);
    Http::fake(['*' => Http::response()]);
    app(ResetDemoOrganization::class)->handle();

    $booking = Booking::query()->where('team_id', demoTeam()->id)->firstOrFail();
    $booking->forceFill(['reference' => 'abc123', 'email' => 'visitor@real.test'])->save();

    app(NotifyBookingParties::class)->confirmed($booking->fresh());

    expect(sentMailCount())->toBe(0);
    Http::assertNothingSent();
});

test('a real organization still gets its booking email', function () {
    $booking = Booking::factory()->create();

    app(NotifyBookingParties::class)->confirmed($booking);

    expect(sentMailCount())->toBeGreaterThan(0);
});

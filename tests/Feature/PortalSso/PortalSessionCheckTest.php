<?php

namespace Tests\Feature\PortalSso;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalSessionCheckTest extends TestCase
{
    use InteractsWithPortal, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
        $this->user = $this->staff(['portal_subject' => 'uuid-1']);
    }

    private function home(): string
    {
        // A page outside any organization, so every account can open it.
        return route('profile.edit');
    }

    public function test_a_recent_check_does_not_call_the_portal(): void
    {
        Http::fake();

        $this->actingAs($this->user)->withSession($this->portalSession(1))->get($this->home())->assertOk();

        Http::assertNothingSent();
    }

    public function test_an_old_check_refreshes_and_keeps_the_new_refresh_token(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'a', 'refresh_token' => 'ref-2', 'expires_in' => 600])]);

        $this->actingAs($this->user)->withSession($this->portalSession(6))->get($this->home())->assertOk();

        $this->assertSame('ref-2', session('portal.refresh_token'));
        $this->assertGreaterThan(now()->subMinute()->getTimestamp(), session('portal.checked_at'));
    }

    public function test_revoked_access_signs_the_person_out_and_sends_them_through_the_portal(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->actingAs($this->user)->withSession($this->portalSession(6))->get($this->home())
            ->assertRedirect(route('portal.redirect'));

        $this->assertGuest();
    }

    public function test_a_portal_outage_does_not_sign_anyone_out(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $this->actingAs($this->user)->withSession($this->portalSession(6))->get($this->home())->assertOk();

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_staff_password_session_is_ended_once_the_portal_is_on(): void
    {
        $this->actingAs($this->user)->get($this->home())->assertRedirect(route('portal.redirect'));

        $this->assertGuest();
    }

    public function test_staff_password_sessions_are_allowed_while_the_emergency_switch_is_on(): void
    {
        config(['portal.allow_password_login' => true]);

        $this->actingAs($this->user)->get($this->home())->assertOk();
    }

    public function test_nothing_is_checked_while_the_portal_is_disabled(): void
    {
        config(['portal.enabled' => false]);
        Http::fake();

        $this->actingAs($this->user)->get($this->home())->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_customer_organization_session_is_never_checked_or_ended(): void
    {
        Http::fake();
        $member = $this->customer();

        $location = $this->actingAs($member)->get($this->home())->headers->get('Location');

        $this->assertStringNotContainsString('auth/portal', (string) $location);
        $this->assertAuthenticatedAs($member);
        Http::assertNothingSent();
    }

    public function test_two_requests_at_the_five_minute_mark_do_not_sign_the_person_out(): void
    {
        // The portal rotates refresh tokens: a second request holding the old one must
        // follow the rotation, not present a revoked token.
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;

            return $request['refresh_token'] === 'ref-1' && $calls === 1
                ? Http::response(['access_token' => 'a', 'refresh_token' => 'ref-2', 'expires_in' => 600])
                : Http::response(['error' => 'invalid_grant'], 400);
        });

        $this->actingAs($this->user)->withSession($this->portalSession(6))->get($this->home())->assertOk();
        $this->actingAs($this->user)->withSession($this->portalSession(6))->get($this->home())->assertOk();

        $this->assertAuthenticatedAs($this->user);
        $this->assertSame(1, $calls);
        $this->assertSame('ref-2', session('portal.refresh_token'));
    }

    public function test_a_session_never_borrows_another_sessions_portal_token(): void
    {
        Cache::put(
            'portal-sso:'.config('portal.app_slug').':'.hash('sha256', 'uuid-1'),
            ['token' => 'ref-other-browser', 'at' => now()->getTimestamp()],
            now()->addHour(),
        );
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->actingAs($this->user)->withSession($this->portalSession(6))->get($this->home())
            ->assertRedirect(route('portal.redirect'));

        $this->assertGuest();
    }

    public function test_a_page_request_whose_session_ended_asks_inertia_to_reload_through_the_portal(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->actingAs($this->user)->withSession($this->portalSession(6))
            ->get($this->home(), [
                'X-Inertia' => 'true',
                'X-Requested-With' => 'XMLHttpRequest',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            ])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('portal.redirect'));
    }

    public function test_a_background_json_request_whose_session_ended_gets_401(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->actingAs($this->user)->withSession($this->portalSession(6))->getJson($this->home())->assertUnauthorized();
    }
}

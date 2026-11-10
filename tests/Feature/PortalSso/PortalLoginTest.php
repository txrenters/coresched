<?php

namespace Tests\Feature\PortalSso;

use App\Enums\TeamRole;
use App\Http\Middleware\EnsureActiveOrganization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortalLoginTest extends TestCase
{
    use InteractsWithPortal, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
    }

    public function test_redirect_sends_the_browser_to_the_portal_with_pkce(): void
    {
        $response = $this->get(route('portal.redirect'));

        $response->assertRedirectContains('https://login.texasrenters.com/oauth/authorize?');
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $q);
        $expected = rtrim(strtr(base64_encode(hash('sha256', session('portal.verifier'), true)), '+/', '-_'), '=');
        $this->assertSame($expected, $q['code_challenge']);
    }

    public function test_a_staff_member_is_linked_by_email_and_signed_in(): void
    {
        $woc = $this->staff(['email' => 'maria@texasrenters.com']);
        $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'Maria@TexasRenters.com', 'name' => 'Maria']);

        $this->finishPortalLogin()->assertRedirect('/'.$woc->currentTeam->slug.'/dashboard');

        $this->assertAuthenticatedAs($woc);
        $this->assertSame('uuid-maria', $woc->fresh()->portal_subject);
        $this->assertSame('ref', session('portal.refresh_token'));
    }

    public function test_a_linked_staff_member_is_found_by_portal_subject_even_if_their_email_changed(): void
    {
        $woc = $this->staff(['email' => 'old@texasrenters.com', 'portal_subject' => 'uuid-maria']);
        $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'maria@texasrenters.com', 'name' => 'Maria']);

        $this->finishPortalLogin();

        $this->assertAuthenticatedAs($woc);
    }

    public function test_someone_without_a_coresched_account_is_refused_and_nothing_is_created(): void
    {
        // Owner decision: no automatic accounts here; an admin invites the person first.
        $this->portalReturns(['sub' => 'uuid-new', 'email' => 'new@texasrenters.com', 'name' => 'New']);

        $this->finishPortalLogin()->assertRedirect(route('login'))->assertSessionHasErrors(['email' => "You don't have a CoreSched account yet. Ask an admin to invite you, then try again."]);

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_linked_account_whose_email_left_the_company_domains_is_refused(): void
    {
        $former = $this->customer(['email' => 'gone@example.com', 'portal_subject' => 'uuid-gone']);
        $this->portalReturns(['sub' => 'uuid-gone', 'email' => 'gone@texasrenters.com', 'name' => 'Gone']);

        $this->finishPortalLogin()->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_existing_organizations_roles_and_details_are_never_changed(): void
    {
        $admin = $this->staff(['email' => 'boss@texasrenters.com', 'name' => 'The Boss']);
        $team = $admin->currentTeam;
        $this->portalReturns(['sub' => 'uuid-boss', 'email' => 'boss@texasrenters.com', 'name' => 'Someone Else']);

        $this->finishPortalLogin();

        $admin = $admin->fresh();
        $this->assertSame('The Boss', $admin->name);
        $this->assertSame($team->id, $admin->current_team_id);
        $this->assertSame(1, $admin->teams()->count());
        $this->assertSame(TeamRole::Admin->value, $admin->teams()->first()->pivot->role);
    }

    public function test_a_super_admin_lands_on_the_admin_console_as_with_the_password_login(): void
    {
        $this->staff(['email' => 'root@texasrenters.com', 'is_super_admin' => true]);
        $this->portalReturns(['sub' => 'uuid-root', 'email' => 'root@texasrenters.com', 'name' => 'Root']);

        $this->finishPortalLogin()->assertRedirect(route('admin.organizations.index'));
    }

    public function test_staff_whose_organizations_are_all_disabled_are_refused(): void
    {
        $woc = $this->staff(['email' => 'maria@texasrenters.com']);
        $woc->currentTeam->forceFill(['disabled_at' => now()])->save();
        $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'maria@texasrenters.com', 'name' => 'Maria']);

        $this->finishPortalLogin()->assertRedirect(route('login'))->assertSessionHasErrors(['email' => EnsureActiveOrganization::MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_wrong_state_is_refused_before_any_token_request(): void
    {
        Http::fake();
        $this->startPortalLogin();

        $this->get(route('portal.callback', ['code' => 'c', 'state' => 'forged']))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_state_cannot_be_replayed(): void
    {
        $this->staff(['email' => 'maria@texasrenters.com']);
        $this->portalReturns(['sub' => 'uuid-maria', 'email' => 'maria@texasrenters.com', 'name' => 'M']);
        $state = $this->startPortalLogin();
        $this->get(route('portal.callback', ['code' => 'c', 'state' => $state]));
        auth()->logout();

        $this->get(route('portal.callback', ['code' => 'c', 'state' => $state]))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_someone_the_portal_has_not_given_this_app_is_refused(): void
    {
        $this->staff(['email' => 'x@texasrenters.com']);
        $this->portalReturns(['sub' => 'uuid-x', 'email' => 'x@texasrenters.com', 'name' => 'X', 'apps' => ['cma']]);

        $this->finishPortalLogin()->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_email_already_linked_to_another_portal_person_is_refused(): void
    {
        $this->staff(['email' => 'maria@texasrenters.com', 'portal_subject' => 'uuid-original']);
        $this->portalReturns(['sub' => 'uuid-other', 'email' => 'maria@texasrenters.com', 'name' => 'M']);

        $this->finishPortalLogin()->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_email_that_only_matches_under_the_database_collation_is_never_linked(): void
    {
        $lookalike = $this->staff(['email' => 'josé@texasrenters.com']);
        $this->portalReturns(['sub' => 'uuid-jose', 'email' => 'jose@texasrenters.com', 'name' => 'J']);

        $this->finishPortalLogin()->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull($lookalike->fresh()->portal_subject);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function untrustedEmails(): array
    {
        return [
            'unverified' => [['email' => 'maria@texasrenters.com', 'email_verified' => false]],
            'empty email' => [['email' => '', 'email_verified' => true]],
            'outside company domains' => [['email' => 'maria@gmail.com', 'email_verified' => true]],
        ];
    }

    #[DataProvider('untrustedEmails')]
    public function test_an_email_the_portal_has_not_verified_is_never_used_to_link(array $claims): void
    {
        $woc = $this->staff(['email' => 'maria@texasrenters.com']);
        $this->portalReturns(array_merge(['sub' => 'uuid-x', 'name' => 'X'], $claims));

        $this->finishPortalLogin()->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull($woc->fresh()->portal_subject);
    }

    public function test_staff_password_login_is_refused_while_the_portal_is_on(): void
    {
        $woc = $this->staff();

        $this->post('/login', ['email' => $woc->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_password_form_never_reveals_a_staff_address_or_password(): void
    {
        $woc = $this->staff();
        $member = $this->customer();

        $error = function (string $email, string $password): ?string {
            $errors = $this->post('/login', ['email' => $email, 'password' => $password])->getSession()->get('errors');
            // The session store serialises the error bag to an array here.
            $message = data_get($errors, 'default.messages.email.0');
            auth()->logout();

            return $message;
        };

        $staffRight = $error($woc->email, 'password');
        $this->assertNotNull($staffRight);
        $this->assertSame($staffRight, $error($woc->email, 'not-it'));
        $this->assertSame($staffRight, $error('nobody@texasrenters.com', 'password'));
        $this->assertSame($staffRight, $error($member->email, 'not-it'));
    }

    public function test_customer_organization_password_login_is_unchanged_while_the_portal_is_on(): void
    {
        $member = $this->customer();

        $this->post('/login', ['email' => $member->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($member);
    }

    public function test_the_emergency_switch_brings_staff_password_login_back(): void
    {
        config(['portal.allow_password_login' => true]);
        $woc = $this->staff();

        $this->post('/login', ['email' => $woc->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($woc);
    }

    public function test_with_the_portal_disabled_nothing_changes(): void
    {
        config(['portal.enabled' => false]);
        $woc = $this->staff();

        $this->get(route('portal.redirect'))->assertNotFound();
        $this->post('/login', ['email' => $woc->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($woc);
    }

    public function test_the_login_page_offers_the_portal_button_and_keeps_the_password_form(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/Login')->where('portalEnabled', true));
    }

    public function test_staff_who_came_through_the_portal_sign_out_back_to_the_portal(): void
    {
        $woc = $this->staff();

        $this->actingAs($woc)
            ->withSession(['portal.sub' => 'uuid-1', 'portal.refresh_token' => 'r', 'portal.checked_at' => now()->getTimestamp()])
            ->post('/logout')
            ->assertRedirect('https://login.texasrenters.com/dashboard');
        $this->assertGuest();
    }

    public function test_staff_passkey_sessions_are_ended_while_the_portal_is_on(): void
    {
        // Passkey sign-in skips the password check; the session check still keeps staff out.
        $woc = $this->staff();

        $this->actingAs($woc)->get('/'.$woc->currentTeam->slug.'/dashboard')->assertRedirect(route('portal.redirect'));

        $this->assertGuest();
    }

    public function test_customer_organizations_signing_out_are_unchanged(): void
    {
        $this->actingAs($this->customer())->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}

<?php

namespace Tests\Feature\PortalSso;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackchannelLogoutTest extends TestCase
{
    use InteractsWithPortal, RefreshDatabase;

    private User $maria;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
        config(['session.driver' => 'database']);
        Cache::flush();
        $this->fakeJwks();

        $this->maria = $this->staff(['portal_subject' => 'uuid-maria', 'remember_token' => 'old-token']);
        $this->other = $this->staff(['portal_subject' => 'uuid-other']);
        foreach ([[$this->maria, 's1'], [$this->maria, 's2'], [$this->other, 's3']] as [$user, $id]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        }
    }

    private function postLogout(string $token): TestResponse
    {
        return $this->post(route('portal.backchannel-logout'), ['logout_token' => $token]);
    }

    public function test_a_valid_logout_token_ends_only_that_persons_sessions(): void
    {
        $this->postLogout($this->logoutToken())->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->maria->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $this->other->id)->count());
        $this->assertNotSame('old-token', $this->maria->fresh()->remember_token);
    }

    public function test_an_audience_array_containing_the_client_is_accepted(): void
    {
        $this->postLogout($this->logoutToken(['aud' => ['client-123', 'other']]))->assertOk();
    }

    public function test_an_unknown_person_is_fine_and_changes_nothing(): void
    {
        $this->postLogout($this->logoutToken(['sub' => 'uuid-nobody']))->assertOk();

        $this->assertSame(3, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    /** @return array<string, array{Closure}> */
    public static function forgedTokens(): array
    {
        return [
            'signed by another key' => [fn (self $t) => $t->logoutToken(key: openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]))],
            'wrong issuer' => [fn (self $t) => $t->logoutToken(['iss' => 'https://evil.example.com'])],
            'wrong audience' => [fn (self $t) => $t->logoutToken(['aud' => 'another-app'])],
            'missing events' => [fn (self $t) => $t->logoutToken(['events' => null])],
            'has a nonce' => [fn (self $t) => $t->logoutToken(['nonce' => 'abc'])],
            'too old' => [fn (self $t) => $t->logoutToken(['iat' => now()->subMinutes(30)->getTimestamp(), 'exp' => null])],
            'expired' => [fn (self $t) => $t->logoutToken(['exp' => now()->subMinutes(5)->getTimestamp()])],
            'alg none' => [function (self $t) {
                $parts = explode('.', $t->logoutToken(header: ['alg' => 'none']));

                return $parts[0].'.'.$parts[1].'.';
            }],
            'garbage' => [fn () => 'not-a-jwt'],
            'missing subject' => [fn (self $t) => $t->logoutToken(['sub' => null])],
        ];
    }

    #[DataProvider('forgedTokens')]
    public function test_forged_or_wrong_tokens_are_rejected_and_change_nothing(Closure $token): void
    {
        $this->postLogout($token($this))->assertStatus(400);

        $this->assertSame(3, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_a_missing_logout_token_is_rejected(): void
    {
        $this->post(route('portal.backchannel-logout'))->assertStatus(400);
    }

    public function test_the_jwks_is_fetched_once_and_cached(): void
    {
        $this->postLogout($this->logoutToken())->assertOk();
        $this->postLogout($this->logoutToken(['sub' => 'uuid-other']))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_unknown_key_ids_cannot_make_the_app_refetch_the_keys_over_and_over(): void
    {
        $this->postLogout($this->logoutToken())->assertOk();

        foreach (range(1, 5) as $i) {
            $this->postLogout($this->logoutToken(header: ['kid' => "made-up-{$i}"]))->assertStatus(400);
        }

        // First fetch plus at most one forced refresh per minute.
        Http::assertSentCount(2);
    }

    public function test_a_failed_key_download_is_not_cached(): void
    {
        config(['portal.issuer' => 'https://login2.texasrenters.com']);
        $pair = self::portalKeyPair();
        Http::fake(['login2.texasrenters.com/oauth/jwks' => Http::sequence()
            ->push('down', 503)
            ->push(['keys' => [['kty' => 'RSA', 'kid' => 'portal-key-1', 'n' => $pair['n'], 'e' => $pair['e']]]])]);
        Cache::flush();

        $this->postLogout($this->logoutToken(['iss' => 'https://login2.texasrenters.com']))->assertStatus(400);
        $this->travel(2)->minutes();
        $this->postLogout($this->logoutToken(['iss' => 'https://login2.texasrenters.com']))->assertOk();
    }

    public function test_while_the_portal_is_down_repeated_logout_calls_do_not_each_hit_the_portal(): void
    {
        config(['portal.issuer' => 'https://login3.texasrenters.com']);
        Http::fake(['login3.texasrenters.com/oauth/jwks' => Http::response('down', 503)]);

        foreach (range(1, 5) as $i) {
            $this->postLogout($this->logoutToken(['iss' => 'https://login3.texasrenters.com']))->assertStatus(400);
        }

        Http::assertSentCount(1);
    }
}

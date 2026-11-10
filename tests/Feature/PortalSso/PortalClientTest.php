<?php

namespace Tests\Feature\PortalSso;

use App\PortalSso\PortalAuthException;
use App\PortalSso\PortalClient;
use App\PortalSso\PortalRevokedException;
use App\PortalSso\PortalUnavailableException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'portal.enabled' => true,
            'portal.issuer' => 'https://login.texasrenters.com',
            'portal.client_id' => 'client-123',
            'portal.client_secret' => 'secret-xyz',
            'portal.app_slug' => 'coresched',
            'app.url' => 'https://scheduling.texasrenters.com',
        ]);
    }

    public function test_authorization_url_carries_client_pkce_s256_state_and_the_callback(): void
    {
        $url = app(PortalClient::class)->authorizationUrl('state-1', 'challenge-1');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://login.texasrenters.com/oauth/authorize?', $url);
        $this->assertSame([
            'client_id' => 'client-123',
            'response_type' => 'code',
            'redirect_uri' => 'https://scheduling.texasrenters.com/auth/portal/callback',
            'scope' => 'openid profile email apps',
            'state' => 'state-1',
            'code_challenge' => 'challenge-1',
            'code_challenge_method' => 'S256',
        ], array_intersect_key($q, array_flip(['client_id', 'response_type', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method'])));
    }

    public function test_exchanging_a_code_posts_the_verifier_and_client_credentials(): void
    {
        Http::fake(['login.texasrenters.com/oauth/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 600])]);

        $tokens = app(PortalClient::class)->exchangeCode('code-1', 'verifier-1');

        $this->assertSame('a', $tokens['access_token']);
        $this->assertSame('r', $tokens['refresh_token']);
        Http::assertSent(fn ($r) => $r['grant_type'] === 'authorization_code' && $r['code'] === 'code-1'
            && $r['code_verifier'] === 'verifier-1' && $r['client_id'] === 'client-123');
    }

    public function test_a_refused_code_exchange_throws_an_auth_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(PortalAuthException::class);
        app(PortalClient::class)->exchangeCode('bad', 'v');
    }

    public function test_a_refused_refresh_means_revoked(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(PortalRevokedException::class);
        app(PortalClient::class)->refresh('r');
    }

    public function test_a_portal_outage_during_refresh_means_unavailable_not_revoked(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $this->expectException(PortalUnavailableException::class);
        app(PortalClient::class)->refresh('r');
    }

    public function test_refresh_returns_new_tokens(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 600])]);

        $this->assertSame('r2', app(PortalClient::class)->refresh('r1')['refresh_token']);
        Http::assertSent(fn ($r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'r1');
    }

    public function test_userinfo_sends_the_bearer_token_and_returns_claims(): void
    {
        Http::fake(['login.texasrenters.com/oauth/userinfo' => Http::response(['sub' => 'uuid-1', 'email' => 'maria@texasrenters.com', 'apps' => ['coresched']])]);

        $this->assertSame('uuid-1', app(PortalClient::class)->userinfo('tok')['sub']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok'));
    }

    public function test_userinfo_401_means_revoked(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_token'], 401)]);

        $this->expectException(PortalRevokedException::class);
        app(PortalClient::class)->userinfo('tok');
    }
}

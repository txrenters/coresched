<?php

namespace Tests\Feature\PortalSso;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use stdClass;

/**
 * Shared set-up for the TR Staff Portal sign-in tests. CoreSched is mixed: TexasRenters
 * staff (company emails) use the portal; members of customer organizations never do.
 */
trait InteractsWithPortal
{
    protected function setUpPortal(): void
    {
        config([
            'portal.enabled' => true,
            'portal.issuer' => 'https://login.texasrenters.com',
            'portal.client_id' => 'client-123',
            'portal.client_secret' => 'secret-xyz',
            'portal.app_slug' => 'coresched',
            'portal.allow_password_login' => false,
            'portal.recheck_minutes' => 5,
            'app.url' => 'http://localhost',
        ]);
    }

    /** A TexasRenters staff account: company email, in its own organization (see UserFactory). */
    protected function staff(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['email' => 'staff-'.Str::lower(Str::random(8)).'@texasrenters.com'], $attributes));
    }

    /** A member of a customer organization: any other email domain. */
    protected function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['email' => 'member-'.Str::lower(Str::random(8)).'@example.com'], $attributes));
    }

    protected function portalReturns(array $claims): void
    {
        Http::fake([
            'login.texasrenters.com/oauth/token' => Http::response(['access_token' => 'acc', 'refresh_token' => 'ref', 'expires_in' => 600]),
            'login.texasrenters.com/oauth/userinfo' => Http::response(array_merge(['email_verified' => true, 'apps' => ['coresched']], $claims)),
        ]);
    }

    protected function startPortalLogin(): string
    {
        $location = $this->get(route('portal.redirect'))->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    protected function finishPortalLogin(): TestResponse
    {
        return $this->get(route('portal.callback', ['code' => 'c', 'state' => $this->startPortalLogin()]));
    }

    /** @return array<string, mixed> */
    protected function portalSession(int $checkedMinutesAgo, string $sub = 'uuid-1'): array
    {
        return ['portal.sub' => $sub, 'portal.refresh_token' => 'ref-1', 'portal.checked_at' => now()->subMinutes($checkedMinutesAgo)->getTimestamp()];
    }

    protected static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** @return array{private: mixed, n: string, e: string} */
    protected static function portalKeyPair(): array
    {
        static $pair;
        if ($pair) {
            return $pair;
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);

        return $pair = ['private' => $key, 'n' => self::b64url($details['rsa']['n']), 'e' => self::b64url($details['rsa']['e'])];
    }

    protected function fakeJwks(): void
    {
        $pair = self::portalKeyPair();
        Http::fake(['login.texasrenters.com/oauth/jwks' => Http::response(['keys' => [
            ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'portal-key-1', 'n' => $pair['n'], 'e' => $pair['e']],
        ]])]);
    }

    protected function logoutToken(array $claims = [], array $header = [], $key = null): string
    {
        $header = array_merge(['alg' => 'RS256', 'typ' => 'logout+jwt', 'kid' => 'portal-key-1'], $header);
        $claims = array_merge([
            'iss' => 'https://login.texasrenters.com',
            'aud' => 'client-123',
            'sub' => 'uuid-maria',
            'iat' => now()->getTimestamp(),
            'exp' => now()->addMinutes(2)->getTimestamp(),
            'jti' => (string) Str::uuid(),
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => new stdClass],
        ], $claims);
        $claims = array_filter($claims, fn ($v) => $v !== null);
        $signingInput = self::b64url(json_encode($header)).'.'.self::b64url(json_encode($claims));
        openssl_sign($signingInput, $signature, $key ?? self::portalKeyPair()['private'], OPENSSL_ALGO_SHA256);

        return $signingInput.'.'.self::b64url($signature);
    }
}

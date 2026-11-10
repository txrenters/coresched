<?php

namespace App\PortalSso;

/**
 * Verifies an OpenID Connect Back-Channel Logout token from the portal (RS256).
 */
class LogoutTokenVerifier
{
    private const EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public function __construct(private Jwks $jwks) {}

    /**
     * @return string the portal subject (`sub`) to sign out
     *
     * @throws PortalAuthException when the token is not valid
     */
    public function verify(string $jwt): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new PortalAuthException('Malformed logout token.');
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
        $header = json_decode(Jwks::b64url($encodedHeader), true);
        $claims = json_decode(Jwks::b64url($encodedClaims), true);

        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256') {
            throw new PortalAuthException('Unsupported logout token.');
        }

        $publicKey = $this->jwks->publicKey((string) ($header['kid'] ?? ''));
        if ($publicKey === null
            || openssl_verify($encodedHeader.'.'.$encodedClaims, Jwks::b64url($encodedSignature), $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new PortalAuthException('Logout token signature is not valid.');
        }

        $now = now()->getTimestamp();
        $audience = (array) ($claims['aud'] ?? []);

        $valid = ($claims['iss'] ?? null) === config('portal.issuer')
            && in_array(config('portal.client_id'), $audience, true)
            && is_int($claims['iat'] ?? null) && $claims['iat'] <= $now + 60 && $claims['iat'] >= $now - 600
            && (! isset($claims['exp']) || (is_int($claims['exp']) && $claims['exp'] >= $now - 60))
            && is_array($claims['events'] ?? null) && array_key_exists(self::EVENT, $claims['events'])
            && ! array_key_exists('nonce', $claims)
            && is_string($claims['sub'] ?? null) && $claims['sub'] !== '';

        if (! $valid) {
            throw new PortalAuthException('Logout token claims are not valid.');
        }

        return $claims['sub'];
    }
}

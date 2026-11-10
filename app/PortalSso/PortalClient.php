<?php

namespace App\PortalSso;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the TR Staff Portal's OIDC endpoints. All calls go straight to the
 * portal over TLS, so their responses are trusted without parsing JWTs.
 */
class PortalClient
{
    public function issuer(): string
    {
        return (string) config('portal.issuer');
    }

    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/auth/portal/callback';
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return $this->issuer().'/oauth/authorize?'.http_build_query([
            'client_id' => config('portal.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'openid profile email apps',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in?: int}
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $this->redirectUri(),
        ]);

        if (! $response->successful()) {
            throw new PortalAuthException('The portal refused the sign-in code ('.$response->status().').');
        }

        return $this->tokens($response);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in?: int}
     */
    public function refresh(string $refreshToken): array
    {
        $response = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);

        if (in_array($response->status(), [400, 401], true)) {
            throw new PortalRevokedException('The portal no longer allows this session.');
        }

        if (! $response->successful()) {
            throw new PortalUnavailableException('The portal answered '.$response->status().'.');
        }

        return $this->tokens($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function userinfo(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->timeout(10)->get($this->issuer().'/oauth/userinfo');
        } catch (ConnectionException $e) {
            throw new PortalUnavailableException($e->getMessage(), previous: $e);
        }

        if ($response->status() === 401) {
            throw new PortalRevokedException('The portal no longer allows this session.');
        }

        if (! $response->successful() || ! is_string($response->json('sub'))) {
            throw new PortalUnavailableException('The portal answered '.$response->status().'.');
        }

        return $response->json();
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function tokenRequest(array $fields): Response
    {
        try {
            return Http::asForm()->acceptJson()->timeout(10)->post($this->issuer().'/oauth/token', [
                ...$fields,
                'client_id' => (string) config('portal.client_id'),
                'client_secret' => (string) config('portal.client_secret'),
            ]);
        } catch (ConnectionException $e) {
            throw new PortalUnavailableException($e->getMessage(), previous: $e);
        }
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in?: int}
     */
    private function tokens(Response $response): array
    {
        $tokens = $response->json();

        if (! is_string($tokens['access_token'] ?? null) || ! is_string($tokens['refresh_token'] ?? null)) {
            throw new PortalAuthException('The portal did not return tokens.');
        }

        return $tokens;
    }
}

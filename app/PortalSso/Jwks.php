<?php

namespace App\PortalSso;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The portal's public signing keys (JWKS), cached, converted to PEM for OpenSSL.
 */
class Jwks
{
    /**
     * PEM public key for this key id, or null if the portal doesn't publish it.
     */
    public function publicKey(string $kid): ?string
    {
        $key = $this->keys()[$kid] ?? null;

        // The portal may have rotated keys: refresh, but at most once a minute so
        // made-up key ids can't make us hammer the portal.
        if ($key === null && Cache::add($this->cacheKey().':refreshed', true, now()->addMinute())) {
            Cache::forget($this->cacheKey());
            $key = $this->keys()[$kid] ?? null;
        }

        return $key;
    }

    /**
     * @return array<string, string> kid => PEM
     */
    private function keys(): array
    {
        if (is_array($cached = Cache::get($this->cacheKey()))) {
            return $cached;
        }

        // After a failed download, wait 30 seconds before trying again so a portal
        // outage (or a flood of requests) doesn't turn into a flood of outbound calls.
        if (Cache::has($this->cacheKey().':failed')) {
            return [];
        }

        try {
            $response = Http::acceptJson()->timeout(5)->get(config('portal.issuer').'/oauth/jwks');
            $keys = $response->successful() ? $this->parse($response->json('keys', [])) : [];
        } catch (ConnectionException) {
            $keys = [];
        }

        if ($keys === []) {
            Cache::put($this->cacheKey().':failed', true, now()->addSeconds(30));

            return [];
        }

        Cache::put($this->cacheKey(), $keys, now()->addHour());

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    private function parse(mixed $jwks): array
    {
        $keys = [];
        foreach ((array) $jwks as $jwk) {
            if (is_array($jwk) && ($jwk['kty'] ?? null) === 'RSA' && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
                $keys[(string) ($jwk['kid'] ?? '')] = self::rsaPem($jwk['n'], $jwk['e']);
            }
        }

        return $keys;
    }

    private function cacheKey(): string
    {
        return 'portal_jwks:'.md5((string) config('portal.issuer'));
    }

    /**
     * Encode an RSA public key (base64url modulus and exponent) as a PEM SubjectPublicKeyInfo.
     */
    public static function rsaPem(string $n, string $e): string
    {
        $integer = function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || ord($bytes[0]) > 0x7F) {
                $bytes = "\x00".$bytes;
            }

            return "\x02".self::length(strlen($bytes)).$bytes;
        };

        $rsaKey = $integer(self::b64url($n)).$integer(self::b64url($e));
        $rsaKey = "\x30".self::length(strlen($rsaKey)).$rsaKey;

        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) with NULL parameters.
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitString = "\x03".self::length(strlen($rsaKey) + 1)."\x00".$rsaKey;
        $spki = "\x30".self::length(strlen($algorithm.$bitString)).$algorithm.$bitString;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return pack('C', $length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return pack('C', 0x80 | strlen($bytes)).$bytes;
    }

    public static function b64url(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}

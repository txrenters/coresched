<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the machine-to-machine API with a key issued in settings.
 *
 * The callers are our own applications rather than people, so there is no
 * session and no user: a request either carries a live key or it does not.
 * Keys are kept as hashes, can be revoked without a deploy, and record when
 * they were last used so a forgotten one is visible.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = ApiKey::findByToken((string) $request->bearerToken());

        abort_if($key === null, 401, 'Unauthorized.');

        // A key belongs to its organization: disabling the one disables the other.
        abort_if($key->team?->isDisabled(), 403, 'This organization has been disabled.');

        // Written without touching updated_at: this is a usage note, not a
        // change to the key itself.
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        // The controllers need it to decide which pages this key may read.
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}

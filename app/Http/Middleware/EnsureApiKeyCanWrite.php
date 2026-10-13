<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the few API routes that change something.
 *
 * Runs after AuthenticateApiToken, so the key is already known to be live;
 * this only asks whether it was given write permission. A key that was not
 * is refused outright rather than treated as not found: the caller holds a
 * real key and should learn that it is the wrong kind.
 */
class EnsureApiKeyCanWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->attributes->get('api_key');

        abort_unless($key instanceof ApiKey && $key->canWrite(), 403, 'This key cannot write.');

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictWhileImpersonating
{
    /**
     * Routes that belong to the member's own account rather than to their
     * work, which a super admin signed in as them must never use: changing
     * their password, email or 2FA, registering a passkey, minting or changing
     * an API key in their name, deleting their account or leaving the
     * organization. Viewing the keys is fine: the list never holds a secret.
     *
     * Matched by route name, including Fortify's and the passkey package's.
     *
     * @var list<string>
     */
    public const BLOCKED_ROUTES = [
        'user-password.update',
        'profile.update',
        'profile.destroy',
        'password.confirm.store',
        'two-factor.*',
        'passkey.*',
        'api-keys.store',
        'api-keys.update',
        'api-keys.destroy',
        'api-keys.forget',
        'teams.leave',
    ];

    /**
     * Refuse account-security routes during an impersonation.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Impersonation::active($request) && $request->routeIs(...self::BLOCKED_ROUTES)) {
            abort(403, __('Not available while signed in as someone else.'));
        }

        return $next($request);
    }
}

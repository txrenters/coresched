<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class RestrictInDemo
{
    /**
     * Routes the shared demo admin may not use. Everything else (event types,
     * availability, meetings, workflows, groups, department pages) stays open
     * so a visitor can try the product for real.
     *
     * Locked: the shared account's own security (one visitor must not lock
     * the next out), anything that reaches a real system or person (API keys
     * and their webhooks, calendar connections, invitations, new users and
     * their welcome or reset emails), and the organization itself (its name,
     * slug, Twilio account and sender, members, or creating new ones outside
     * the demo).
     *
     * Accepting an invitation and switching organizations are locked too:
     * the shared account must never end up inside a real organization, where
     * every visitor would follow it.
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
        'integrations.connect',
        'integrations.callback',
        'integrations.sync',
        'integrations.destroy',
        'integrations.calendars.update',
        'teams.store',
        'teams.update',
        'teams.destroy',
        'teams.leave',
        'teams.members.*',
        'teams.invitations.*',
        'teams.switch',
        'invitations.*',
        'organizations.members.*',
        'users.store',
        'users.destroy',
        'users.organizations.destroy',
        'users.password-reset',
        'users.role',
        'users.phone',
    ];

    /**
     * Refuse the locked routes to the shared demo admin.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Demo::isDemoUser($request->user()) || ! $request->routeIs(...self::BLOCKED_ROUTES)) {
            return $next($request);
        }

        $message = __('Not available in the demo. Sign up to use this.');

        if ($request->expectsJson()) {
            abort(403, $message);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}

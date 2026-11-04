<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveOrganization
{
    /**
     * Shown on the sign-in page; also used by the password sign-in itself.
     */
    public const MESSAGE = 'Your organization\'s access has been suspended. Contact your administrator.';

    /**
     * Keep people out of organizations a super admin has disabled.
     *
     * Someone whose every organization is disabled is signed out, whichever
     * way they signed in and even mid-session. Someone who still has an active
     * one is moved onto it when their current one is disabled, and the org
     * switcher no longer offers the disabled one (HasTeams::toUserTeams).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isSuperAdmin()) {
            return $next($request);
        }

        if ($user->hasOnlyDisabledOrganizations()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => self::MESSAGE]);
        }

        if ($user->currentTeam?->isDisabled() && ($team = $user->fallbackTeam())) {
            $user->switchTeam($team);

            // Asked for a page inside the disabled organization: land in the
            // active one instead of on a refusal.
            if ($request->isMethod('GET') && $request->route('current_team') !== null) {
                return redirect()->route('dashboard', ['current_team' => $team->slug]);
            }
        }

        return $next($request);
    }
}

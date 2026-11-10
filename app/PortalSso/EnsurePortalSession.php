<?php

namespace App\PortalSso;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every few minutes, ask the portal (by refreshing the token) whether this person
 * may still use CoreSched (company-email staff only). The portal refuses once they are deactivated, unassigned,
 * signed out everywhere, or past the 12-hour sign-in window.
 */
class EnsurePortalSession
{
    public function __construct(private PortalClient $portal) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Customer organizations never sign in through the portal, so only staff sessions are checked,
        // plus any session that came from a portal sign-in (e.g. a super admin impersonating a member).
        $user = Auth::guard('web')->user();
        $fromPortal = $request->session()->has('portal.refresh_token');

        // Lets the logout response send portal staff back to the portal's app list.
        if (config('portal.enabled') && $fromPortal) {
            $request->attributes->set('portal_session', true);
        }

        if (! config('portal.enabled') || $user === null || (! StaffAccounts::isStaff($user) && ! $fromPortal)) {
            return $next($request);
        }

        $refreshToken = $request->session()->get('portal.refresh_token');

        if (! is_string($refreshToken)) {
            // A session from before the switch-over (password login). Only allowed in an emergency.
            return config('portal.allow_password_login') ? $next($request) : $this->signOut($request);
        }

        $checkedAt = (int) $request->session()->get('portal.checked_at', 0);
        if (now()->getTimestamp() - $checkedAt < config('portal.recheck_minutes') * 60) {
            return $next($request);
        }

        try {
            [$refreshToken, $checkedAt] = $this->refreshOnce($request, $refreshToken);
        } catch (PortalRevokedException) {
            return $this->signOut($request);
        } catch (PortalUnavailableException $e) {
            // An outage is not a revocation; try again on the next request.
            Log::warning('Portal re-check skipped: portal unavailable', ['error' => $e->getMessage()]);

            return $next($request);
        }

        $request->session()->put([
            'portal.refresh_token' => $refreshToken,
            'portal.checked_at' => $checkedAt,
        ]);

        return $next($request);
    }

    /**
     * Refresh at most once per token, however many requests arrive together. The portal
     * rotates refresh tokens (using one revokes it), so each rotation is recorded against
     * the token it replaced. A request that waited on the lock, or that holds a stale copy
     * of its session, follows its OWN token's rotation instead of presenting a revoked
     * token; it never uses a token from another session, so the portal's per-session
     * limits (such as the 12-hour sign-in window) still apply.
     *
     * @return array{0: string, 1: int} the current refresh token and when it was confirmed
     *
     * @throws PortalRevokedException
     * @throws PortalUnavailableException
     */
    private function refreshOnce(Request $request, string $sessionToken): array
    {
        // Serialise per person; the shared state below is keyed per token, never per person.
        $lock = 'portal-sso:'.config('portal.app_slug').':lock:'.hash('sha256', (string) $request->session()->get('portal.sub', $request->session()->getId()));

        try {
            return Cache::lock($lock, 20)->block(10, function () use ($sessionToken): array {
                $token = $sessionToken;
                $replaced = null;
                $confirmedAt = null;

                // Follow this token's own rotations made by a concurrent or earlier request.
                for ($i = 0; $i < 3 && is_array($rotation = Cache::get(self::rotationKey($token))); $i++) {
                    [$replaced, $token, $confirmedAt] = [$token, $rotation['next'], $rotation['at']];
                }

                if ($confirmedAt !== null && now()->getTimestamp() - $confirmedAt < config('portal.recheck_minutes') * 60) {
                    return [$token, $confirmedAt];
                }

                $tokens = $this->portal->refresh($token);
                $now = now()->getTimestamp();

                // Kept as long as a session can live, so even an idle stale copy can follow it.
                Cache::put(self::rotationKey($token), ['next' => $tokens['refresh_token'], 'at' => $now], now()->addMinutes(max((int) config('session.lifetime'), 60)));

                // Anyone still holding the token before that is two rotations behind; drop it.
                if ($replaced !== null) {
                    Cache::forget(self::rotationKey($replaced));
                }

                return [$tokens['refresh_token'], $now];
            });
        } catch (LockTimeoutException $e) {
            throw new PortalUnavailableException('Another request is still checking with the portal.', previous: $e);
        }
    }

    private static function rotationKey(string $refreshToken): string
    {
        return 'portal-sso:'.config('portal.app_slug').':rotated:'.hash('sha256', $refreshToken);
    }

    private function signOut(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Background requests can't follow a redirect to another site: Inertia reloads the
        // page through the portal, plain JSON/axios calls get a 401.
        if ($request->header('X-Inertia')) {
            return Inertia::location(route('portal.redirect'));
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Your session ended. Sign in again through the TR Staff Portal.'], 401);
        }

        return redirect()->guest(route('portal.redirect'));
    }
}

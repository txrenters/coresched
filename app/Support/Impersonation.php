<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Reads the session state a super admin leaves behind when they sign in as
 * someone else (Admin\ImpersonationController).
 *
 * The super admin's id is kept in the session, never in a cookie or the URL,
 * so returning to their own account needs the very session that started it.
 */
class Impersonation
{
    public const IMPERSONATOR_KEY = 'impersonation.impersonator_id';

    public const TEAM_KEY = 'impersonation.team_id';

    /**
     * Determine whether the current session is an impersonation.
     *
     * A marker naming the signed-in user themselves is a leftover, not an
     * impersonation: nobody is signed in as someone else.
     */
    public static function active(Request $request): bool
    {
        if (! $request->hasSession() || ! $request->session()->has(self::IMPERSONATOR_KEY)) {
            return false;
        }

        return (int) $request->session()->get(self::IMPERSONATOR_KEY) !== $request->user()?->id;
    }

    /**
     * The super admin behind the current session, if it is an impersonation.
     *
     * Re-checked on every read: someone who has stopped being a super admin
     * cannot be returned to with super admin rights they no longer hold, and
     * the stop route refuses them.
     */
    public static function impersonator(Request $request): ?User
    {
        if (! self::active($request)) {
            return null;
        }

        $user = User::query()->whereKey($request->session()->get(self::IMPERSONATOR_KEY))->first();

        return $user?->isSuperAdmin() ? $user : null;
    }
}

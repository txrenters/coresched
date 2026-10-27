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
     */
    public static function active(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::IMPERSONATOR_KEY);
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

        $user = User::find($request->session()->get(self::IMPERSONATOR_KEY));

        return $user?->isSuperAdmin() ? $user : null;
    }
}

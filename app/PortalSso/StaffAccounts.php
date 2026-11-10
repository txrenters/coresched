<?php

namespace App\PortalSso;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Who counts as TexasRenters staff in CoreSched: an account whose email is on a company
 * domain (@texasrenters.com / @texra.net, owner decision). Only staff use the TR Staff
 * Portal; members of customer organizations keep their own passwords and invitations.
 */
class StaffAccounts
{
    public static function isStaff(?User $user): bool
    {
        return $user !== null && self::isCompanyEmail($user->email);
    }

    public static function isCompanyEmail(?string $email): bool
    {
        $email = Str::lower(trim((string) $email));

        return str_contains($email, '@') && in_array(Str::afterLast($email, '@'), config('portal.allowed_domains'), true);
    }

    /** While the portal is on, staff may only sign in through it (unless the emergency switch is on). */
    public static function mustUsePortal(?User $user): bool
    {
        return config('portal.enabled') && ! config('portal.allow_password_login') && self::isStaff($user);
    }
}

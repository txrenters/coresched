<?php

namespace App\PortalSso;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Maps a portal identity onto an existing CoreSched account with a company email, keeping
 * its id, organizations, roles and data. Accounts are never created here (owner decision):
 * an admin invites the person into their organization first. Accounts of customer
 * organizations (other email domains) are never linked.
 */
class PortalUserLinker
{
    /**
     * @param  array<string, mixed>  $claims  userinfo claims (sub, email, email_verified, name)
     *
     * @throws PortalLinkConflict
     * @throws PortalAccessRefused
     */
    public function link(array $claims): User
    {
        $sub = (string) $claims['sub'];
        $email = Str::lower(trim((string) ($claims['email'] ?? '')));

        if ($user = User::where('portal_subject', $sub)->first()) {
            if (! StaffAccounts::isStaff($user)) {
                Log::warning('Portal sign-in refused: linked account is no longer staff', ['user_id' => $user->id]);

                throw new PortalLinkConflict('Your CoreSched account no longer has a company email. Ask an admin.');
            }

            return $user;
        }

        // Only a verified company email may be used to match an account.
        $domain = Str::after($email, '@');
        if ($email === '' || ($claims['email_verified'] ?? false) !== true || ! in_array($domain, config('portal.allowed_domains'), true)) {
            Log::warning('Portal sign-in refused: email not verified or not a company address', ['email' => $email]);

            throw new PortalLinkConflict('Your portal account has no verified company email. Ask IT.');
        }

        $matches = User::whereRaw('lower(email) = ?', [$email])->get();

        // The database collation may treat different addresses as equal (MySQL: "josé" = "jose").
        if ($matches->contains(fn (User $user) => Str::lower($user->email) !== $email)) {
            Log::warning('Portal sign-in refused: email only matches a lookalike address', ['email' => $email]);

            throw new PortalLinkConflict('Another account here has an address that looks like yours. Ask an admin.');
        }

        $existing = $matches->first();

        if ($existing === null) {
            Log::info('Portal sign-in refused: no CoreSched account for this person yet', ['email' => $email]);

            throw new PortalAccessRefused("You don't have a CoreSched account yet. Ask an admin to invite you, then try again.");
        }

        if (! StaffAccounts::isStaff($existing)) {
            Log::warning('Portal sign-in refused: this address belongs to a non-staff account', ['email' => $email]);

            throw new PortalAccessRefused('This address is not a company account. Ask an admin.');
        }

        if ($existing->portal_subject !== null) {
            Log::warning('Portal sign-in refused: email already linked to another portal person', ['email' => $email]);

            throw new PortalLinkConflict('This CoreSched account is linked to a different portal account. Ask IT.');
        }

        $existing->forceFill(['portal_subject' => $sub])->save();

        return $existing;
    }
}

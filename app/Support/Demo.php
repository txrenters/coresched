<?php

namespace App\Support;

use App\Models\Team;
use App\Models\User;

/**
 * The public demo: one shared organization (teams.is_demo) that anyone can
 * open from the marketing site's "Try the demo" button, signed in as its
 * admin, and that demo:reset rebuilds every night.
 *
 * Visitors may click everything, but nothing may leave the app on the demo's
 * behalf: CancelDemoNotifications drops its mail, NotifyBookingParties sends
 * it no webhooks, RunAutomations skips its workflows, and RestrictInDemo locks
 * the routes that reach outside the organization or change who is in it.
 */
class Demo
{
    /**
     * The address the shared demo admin signs in as. example.com is reserved,
     * so even a send that slipped through could never reach a real inbox.
     */
    public const ADMIN_EMAIL = 'demo@example.com';

    /**
     * The slug of the demo organization's booking pages.
     */
    public const TEAM_SLUG = 'coresched-demo';

    /**
     * Get the shared demo admin, if the demo has been built.
     */
    public static function admin(): ?User
    {
        return User::query()->where('email', self::ADMIN_EMAIL)->first();
    }

    /**
     * Determine whether the user is the shared demo admin.
     */
    public static function isDemoUser(?User $user): bool
    {
        return $user !== null && $user->email === self::ADMIN_EMAIL;
    }

    /**
     * Determine whether the given organization is a demo organization.
     */
    public static function isDemoTeam(Team|int|null $team): bool
    {
        if ($team === null) {
            return false;
        }

        return in_array($team instanceof Team ? $team->id : $team, self::teamIds(), true);
    }

    /**
     * Get the ids of every demo organization.
     *
     * Not cached: a queue worker lives across nightly rebuilds, and a stale
     * id would let the new demo's mail through.
     *
     * @return array<int, int>
     */
    public static function teamIds(): array
    {
        return Team::query()
            ->where('is_demo', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}

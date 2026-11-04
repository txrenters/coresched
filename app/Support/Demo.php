<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\SingleUseLink;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

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

    /**
     * Determine whether the request is for one of the demo's public pages: a
     * booking page, or a booking made on one.
     *
     * Visitors can write whatever they like into the demo's event types, so
     * these pages carry a notice saying so, and warning anyone booking that
     * every other demo visitor can see what they enter.
     */
    public static function isDemoBookingPage(Request $request): bool
    {
        if (! $request->routeIs('book.*', 'booking.*')) {
            return false;
        }

        $teamIds = self::teamIds();

        if ($teamIds === []) {
            return false;
        }

        $booking = $request->route('booking');
        $link = $request->route('singleUseLink');
        $page = $request->route('page');

        return match (true) {
            $booking instanceof Booking => in_array($booking->team_id, $teamIds, true),
            is_string($booking) => Booking::query()->where('uid', $booking)->whereIn('team_id', $teamIds)->exists(),
            $link instanceof SingleUseLink => in_array($link->team_id, $teamIds, true),
            is_string($link) => SingleUseLink::query()->where('uid', $link)->whereIn('team_id', $teamIds)->exists(),
            is_string($page) => Team::query()->whereKey($teamIds)->where('slug', $page)->exists()
                || User::query()->where('booking_slug', $page)
                    ->whereHas('teams', fn ($teams) => $teams->whereKey($teamIds))
                    ->exists(),
            default => false,
        };
    }
}

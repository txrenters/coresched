<?php

namespace App\Listeners;

use App\Models\Booking;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * Keeps the public demo from emailing anyone.
 *
 * The demo lets visitors book, cancel and reschedule for real, and its sample
 * people sit in real host pools, so every notification path can fire for it:
 * confirmations, reminders, workflows, invitations, password resets. One
 * listener on NotificationSending covers them all, on every channel, rather
 * than a check at each of the places that send. Returning false cancels the
 * send.
 */
class CancelDemoNotifications
{
    /**
     * Cancel the notification when it concerns the demo.
     */
    public function handle(NotificationSending $event): ?bool
    {
        return $this->concernsDemo($event->notifiable, $event->notification) ? false : null;
    }

    /**
     * Determine whether the notification belongs to the demo organization or
     * is addressed to one of its people.
     */
    protected function concernsDemo(mixed $notifiable, object $notification): bool
    {
        $booking = $notification->booking ?? null;

        if ($booking instanceof Booking && Demo::isDemoTeam($booking->team_id)) {
            return true;
        }

        $invitation = $notification->invitation ?? null;

        if ($invitation instanceof TeamInvitation && Demo::isDemoTeam($invitation->team_id)) {
            return true;
        }

        if (! $notifiable instanceof User) {
            return false;
        }

        if (Demo::isDemoUser($notifiable)) {
            return true;
        }

        // The demo's sample people belong to the demo and nothing else.
        $teamIds = $notifiable->teams()->pluck('teams.id')->all();

        return $teamIds !== [] && array_diff($teamIds, Demo::teamIds()) === [];
    }
}

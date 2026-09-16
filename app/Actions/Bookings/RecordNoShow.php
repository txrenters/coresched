<?php

namespace App\Actions\Bookings;

use App\Models\Booking;
use App\Services\Activity\ActivityLogger;

class RecordNoShow
{
    public function __construct(
        protected ScheduleAutomations $scheduleAutomations,
        protected ActivityLogger $activity,
    ) {
        //
    }

    /**
     * Record whether the invitee turned up.
     *
     * Workflows are rebuilt either way: marking queues the no-show follow-ups
     * and drops the "after it ends" ones, and undoing a mistaken mark puts
     * them back. Anything already sent stays sent.
     */
    public function handle(Booking $booking, bool $noShow): void
    {
        if ($noShow === ($booking->no_show_at !== null)) {
            return;
        }

        $booking->update(['no_show_at' => $noShow ? now() : null]);

        $this->scheduleAutomations->handle($booking);

        $eventTypeName = $booking->eventType->name ?? 'a meeting';

        $this->activity->record(
            $booking->team,
            $noShow ? 'booking.no_show' : 'booking.no_show_undone',
            $noShow
                ? $booking->name.' did not show up to '.$eventTypeName
                : $booking->name.' is no longer marked a no-show for '.$eventTypeName,
            $booking,
        );
    }
}

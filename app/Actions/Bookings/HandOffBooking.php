<?php

namespace App\Actions\Bookings;

use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Services\Scheduling\AvailabilityEngine;
use Carbon\CarbonImmutable;

class HandOffBooking
{
    public function __construct(
        protected AvailabilityEngine $availability,
        protected AssignHosts $assignHosts,
        protected RescheduleBooking $rescheduleBooking,
    ) {
        //
    }

    /**
     * Move a meeting to another host in its pool, keeping the same time.
     *
     * The host who is away is already busy for their leave, so they drop out
     * of the slot's free hosts on their own, and the usual priority order
     * picks who takes it from whoever is left.
     *
     * @throws SlotUnavailableException When nobody else in the pool is free then.
     */
    public function handle(Booking $booking, string $reason = 'The host is away'): Booking
    {
        abort_unless($booking->isChangeable(), 410);

        $eventType = $booking->eventType;
        $startsAt = CarbonImmutable::parse($booking->starts_at)->utc();
        $currentHostIds = $booking->attendingHosts()->modelKeys();

        if ($booking->reassignableHosts()->isEmpty()) {
            throw new SlotUnavailableException('Only a round robin meeting can be handed to another host.');
        }

        $slot = $this->availability->findSlot($eventType, $startsAt, $booking->id, $booking->zone);

        $freeHostIds = array_values(array_diff($slot->hostIds ?? [], $currentHostIds));

        if ($freeHostIds === []) {
            throw new SlotUnavailableException('Nobody else who hosts this meeting is free at that time.');
        }

        $host = $this->assignHosts->handle($eventType, $freeHostIds, $booking->zone)->first();

        return $this->rescheduleBooking->handle($booking, $startsAt, $reason, $host, 'host');
    }
}

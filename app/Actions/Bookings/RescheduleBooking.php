<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Exceptions\SlotUnavailableException;
use App\Jobs\RemoveBookingFromCalendars;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\Bookings\BookingCanceled;
use App\Services\Activity\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class RescheduleBooking
{
    public function __construct(
        protected CreateBooking $createBooking,
        protected NotifyBookingParties $notify,
        protected ActivityLogger $activity,
    ) {
        //
    }

    /**
     * Move a booking to a new time by replacing it with a fresh one.
     *
     * The original is kept, marked as rescheduled, so the history of the
     * meeting survives and the invitee's old links resolve to something.
     *
     * The original is locked and checked again inside the transaction, so a
     * double submit or a cancel landing at the same moment cannot leave two
     * replacements, or bring a canceled meeting back.
     *
     * A host moving the meeting may also hand it to someone else in the pool.
     * The host who is dropped is told it was canceled for them, since the
     * move announcement only reaches the hosts of the replacement.
     *
     * @throws SlotUnavailableException
     */
    public function handle(Booking $booking, CarbonImmutable $startsAt, ?string $reason = null, ?User $host = null, string $rescheduledBy = 'invitee'): Booking
    {
        /*
         * Everything read from the original is loaded before the transaction:
         * CreateBooking's host locks must be its first reads, see handle().
         */
        $eventType = $booking->eventType;
        $eventType->schedulingHosts();
        $booking->loadMissing(['guests', 'answers', 'host', 'hosts']);
        $previousHosts = $booking->attendingHosts();

        $attributes = [
            'name' => $booking->name,
            'email' => $booking->email,
            'notes' => $booking->notes,
            'timezone' => $booking->invitee_timezone,
            'location_detail' => $booking->location_detail,
            'guests' => $booking->guests->pluck('email')->all(),
        ];

        $replacement = DB::transaction(function () use ($booking, $eventType, $startsAt, $reason, $attributes, $host, $rescheduledBy) {
            $original = Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            abort_unless($original !== null && $original->isChangeable(), 410);

            $replacement = $this->createBooking->handle($eventType, $startsAt, $attributes, replacing: $booking, host: $host);

            $booking->update([
                'status' => BookingStatus::Rescheduled,
                'cancellation_reason' => $reason,
                'canceled_by' => $rescheduledBy,
                'canceled_at' => now(),
            ]);

            $booking->reminders()->whereNull('sent_at')->delete();
            $booking->automationRuns()->whereNull('sent_at')->delete();

            foreach ($booking->answers as $answer) {
                $replacement->answers()->create([
                    'event_type_question_id' => $answer->event_type_question_id,
                    'label' => $answer->label,
                    'answer' => $answer->answer,
                ]);
            }

            return $replacement;
        });

        RemoveBookingFromCalendars::dispatch($booking);

        /*
         * A replacement on an approval-required event type is pending again,
         * so announcing it as rescheduled would promise a time no host has
         * agreed to (and attach a cancellation ICS, since the ICS method
         * follows the booking's non-confirmed status).
         */
        $replacement = $replacement->fresh(['eventType', 'host', 'hosts', 'guests']);

        $replacement->status->isConfirmed()
            ? $this->notify->rescheduled($replacement, $booking)
            : $this->notify->pending($replacement);

        $droppedHosts = $previousHosts->diff($replacement->attendingHosts());

        if ($droppedHosts->isNotEmpty()) {
            Notification::send($droppedHosts, new BookingCanceled($booking->fresh(['eventType', 'host', 'guests'])));
        }

        $this->activity->record(
            $replacement->team,
            'booking.rescheduled',
            ($replacement->eventType->name ?? 'A meeting').' with '.$replacement->name.' was moved',
            $replacement,
            [
                'from' => $booking->starts_at->toIso8601String(),
                'to' => $replacement->starts_at->toIso8601String(),
            ],
        );

        return $replacement;
    }
}

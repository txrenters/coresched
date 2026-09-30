<?php

namespace App\Actions\Bookings;

use App\Jobs\SendBookingWebhook;
use App\Models\Booking;
use App\Notifications\Bookings\BookingCanceled;
use App\Notifications\Bookings\BookingConfirmed;
use App\Notifications\Bookings\BookingDeclined;
use App\Notifications\Bookings\BookingPendingApproval;
use App\Notifications\Bookings\BookingRescheduled;
use Illuminate\Support\Facades\Notification;

class NotifyBookingParties
{
    /**
     * Tell everyone a booking has been made.
     */
    public function confirmed(Booking $booking): void
    {
        $this->send($booking, new BookingConfirmed($booking));
    }

    /**
     * Tell everyone a booking request awaits a host's approval.
     */
    public function pending(Booking $booking): void
    {
        $this->send($booking, new BookingPendingApproval($booking));
    }

    /**
     * Tell everyone a booking request was turned down.
     */
    public function declined(Booking $booking): void
    {
        $this->send($booking, new BookingDeclined($booking));
    }

    /**
     * Tell everyone a booking has been called off.
     */
    public function canceled(Booking $booking): void
    {
        $this->send($booking, new BookingCanceled($booking));
    }

    /**
     * Tell everyone a booking has moved.
     */
    public function rescheduled(Booking $booking, Booking $previous): void
    {
        $this->send($booking, new BookingRescheduled($booking, $previous));
    }

    /**
     * The webhook event each notification stands for.
     *
     * One dispatch point, here: a booking change that tells the people
     * involved also tells the system that sent the invitee, and one that
     * deliberately tells nobody (the Calendly importer writes around this
     * class) tells neither.
     *
     * @var array<class-string, string>
     */
    private const WEBHOOK_EVENTS = [
        BookingConfirmed::class => 'booking.confirmed',
        BookingPendingApproval::class => 'booking.requested',
        BookingDeclined::class => 'booking.declined',
        BookingCanceled::class => 'booking.canceled',
        BookingRescheduled::class => 'booking.rescheduled',
    ];

    /**
     * Deliver a notification to the invitee, their guests, and every host.
     */
    protected function send(Booking $booking, mixed $notification): void
    {
        Notification::send($booking->attendingHosts(), $notification);

        foreach ($booking->inviteeEmails() as $email) {
            Notification::route('mail', $email)->notify($notification);
        }

        $this->report($booking, $notification);
    }

    /**
     * Columns whose change is already reported by a lifecycle event above, or
     * that are bookkeeping nobody outside needs to hear about.
     *
     * @var list<string>
     */
    private const UNREPORTED_CHANGES = [
        'status', 'canceled_at', 'canceled_by', 'cancellation_reason', 'rescheduled_from_id',
        'webhook_sequence', 'created_at', 'updated_at',
    ];

    /**
     * Tell the system that sent the invitee here about any other change to
     * the booking: a no-show, the host's notes, the meeting link, the
     * invitee's details. Called from the model on every update, so a writer
     * added later is reported without having to remember to.
     *
     * @param  list<string>  $columns
     */
    public function changed(Booking $booking, array $columns): void
    {
        $changes = array_values(array_diff($columns, self::UNREPORTED_CHANGES));

        if ($booking->reference === null || $changes === []) {
            return;
        }

        Booking::withoutEvents(fn () => $booking->increment('webhook_sequence'));

        SendBookingWebhook::dispatch($booking, 'booking.updated', (int) $booking->webhook_sequence, changes: $changes)
            ->afterCommit();
    }

    /**
     * Tell the system that sent the invitee here, when one did.
     *
     * The booking's own sequence is bumped first and travels with the job,
     * so the receiver can tell a late retry of an older event from the
     * newest one however the deliveries are ordered on the wire.
     */
    private function report(Booking $booking, mixed $notification): void
    {
        if ($booking->reference === null || ! is_object($notification)) {
            return;
        }

        $event = self::WEBHOOK_EVENTS[$notification::class] ?? null;

        if ($event === null) {
            return;
        }

        Booking::withoutEvents(fn () => $booking->increment('webhook_sequence'));

        SendBookingWebhook::dispatch($booking, $event, (int) $booking->webhook_sequence);
    }
}

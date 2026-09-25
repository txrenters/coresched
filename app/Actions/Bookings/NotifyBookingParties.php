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

        $booking->increment('webhook_sequence');

        SendBookingWebhook::dispatch($booking, $event, (int) $booking->webhook_sequence);
    }
}

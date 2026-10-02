<?php

namespace App\Notifications\Bookings;

use App\Models\Booking;
use App\Notifications\Bookings\Concerns\NotifiesHostsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingDeclined extends Notification implements ShouldQueue
{
    use NotifiesHostsInApp, Queueable;

    public function __construct(public Booking $booking)
    {
        //
    }

    /**
     * Build the mail representation of the notification.
     *
     * No cancellation ICS is attached: a declined request was never synced to
     * any calendar, so there is nothing to cancel.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing(['eventType', 'host', 'guests']);
        $presenter = new BookingMailPresenter($booking, $notifiable);

        $message = (new MailMessage)
            ->subject('Declined: '.$booking->eventType->name.' on '.$presenter->when())
            ->greeting('Hi '.$presenter->recipientName().',')
            ->line($presenter->isHost()
                ? $booking->eventType->name.' on '.$presenter->when().' was declined.'
                : "The host can't make this time, so your request for ".$booking->eventType->name.' on '.$presenter->when().' was declined.');

        if (filled($booking->cancellation_reason)) {
            $message->line('**Reason:** '.$booking->cancellation_reason);
        }

        return $presenter->withSender($message);
    }

    /**
     * Get the machine-readable kind of this notification.
     */
    protected function inAppType(): string
    {
        return 'booking.declined';
    }

    /**
     * Get the short heading shown in the panel.
     */
    protected function inAppTitle(): string
    {
        return 'Request declined';
    }

    /**
     * Get the one-line summary shown under the heading.
     */
    protected function inAppBody(Booking $booking): string
    {
        return ($booking->eventType->name ?? 'A meeting').' with '.$booking->name.' was declined.';
    }
}

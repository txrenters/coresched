<?php

namespace App\Notifications\Bookings;

use App\Models\Booking;
use App\Notifications\Bookings\Concerns\NotifiesHostsInApp;
use App\Services\Ics\IcsGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingCanceled extends Notification implements ShouldQueue
{
    use NotifiesHostsInApp, Queueable;

    public function __construct(public Booking $booking)
    {
        //
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing(['eventType', 'host', 'guests']);
        $presenter = new BookingMailPresenter($booking, $notifiable);

        $message = (new MailMessage)
            ->subject('Canceled: '.$booking->eventType->name.' on '.$presenter->when())
            ->greeting('Hi '.$presenter->recipientName().',')
            ->line($booking->eventType->name.' on '.$presenter->when().' has been canceled.');

        if (filled($booking->cancellation_reason)) {
            $message->line('**Reason:** '.$booking->cancellation_reason);
        }

        return $presenter->withSender($message)->attachData(
            app(IcsGenerator::class)->forBooking($booking),
            'invite.ics',
            ['mime' => 'text/calendar; charset=UTF-8; method=CANCEL'],
        );
    }

    /**
     * Get the machine-readable kind of this notification.
     */
    protected function inAppType(): string
    {
        return 'booking.canceled';
    }

    /**
     * Get the short heading shown in the panel.
     */
    protected function inAppTitle(): string
    {
        return 'Booking canceled';
    }

    /**
     * Get the one-line summary shown under the heading.
     */
    protected function inAppBody(Booking $booking): string
    {
        return $booking->name.' canceled '.($booking->eventType->name ?? 'a meeting').'.';
    }
}

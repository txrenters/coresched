<?php

namespace App\Notifications\Bookings;

use App\Models\Booking;
use App\Notifications\Bookings\Concerns\NotifiesHostsInApp;
use App\Services\Ics\IcsGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRescheduled extends Notification implements ShouldQueue
{
    use NotifiesHostsInApp, Queueable;

    public function __construct(public Booking $booking, public Booking $previous)
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
        $previous = new BookingMailPresenter($this->previous, $notifiable);

        $message = (new MailMessage)
            ->subject('Rescheduled: '.$booking->eventType->name.' is now '.$presenter->when())
            ->greeting('Hi '.$presenter->recipientName().',')
            ->line($booking->eventType->name.' has moved.')
            ->line('**Was:** '.$previous->when())
            ->line('**Now:** '.$presenter->when());

        foreach ($presenter->locationLines() as $line) {
            $message->line($line);
        }

        return $presenter
            ->withFooter($message)
            ->attachData(
                app(IcsGenerator::class)->forBooking($booking),
                'invite.ics',
                ['mime' => 'text/calendar; charset=UTF-8; method=REQUEST'],
            );
    }

    /**
     * Get the machine-readable kind of this notification.
     */
    protected function inAppType(): string
    {
        return 'booking.rescheduled';
    }

    /**
     * Get the short heading shown in the panel.
     */
    protected function inAppTitle(): string
    {
        return 'Booking moved';
    }

    /**
     * Get the one-line summary shown under the heading.
     */
    protected function inAppBody(Booking $booking): string
    {
        return $booking->name.' moved '.($booking->eventType->name ?? 'a meeting').'.';
    }
}

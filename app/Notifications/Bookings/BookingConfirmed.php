<?php

namespace App\Notifications\Bookings;

use App\Models\Booking;
use App\Notifications\Bookings\Concerns\NotifiesHostsInApp;
use App\Services\Ics\IcsGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingConfirmed extends Notification implements ShouldQueue
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
        $booking = $this->booking->loadMissing(['eventType', 'host', 'guests', 'answers']);
        $presenter = new BookingMailPresenter($booking, $notifiable);

        $message = (new MailMessage)
            ->subject('Confirmed: '.$booking->eventType->name.' on '.$presenter->when())
            ->greeting('Hi '.$presenter->recipientName().',')
            ->line($presenter->confirmationLine())
            ->line('**When:** '.$presenter->when())
            ->line('**Who:** '.$presenter->participants());

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
        return 'booking.confirmed';
    }

    /**
     * Get the short heading shown in the panel.
     */
    protected function inAppTitle(): string
    {
        return 'New booking';
    }

    /**
     * Get the one-line summary shown under the heading.
     */
    protected function inAppBody(Booking $booking): string
    {
        return $booking->name.' booked '.($booking->eventType->name ?? 'a meeting').'.';
    }
}

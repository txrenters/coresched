<?php

namespace App\Notifications\Bookings;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Booking $booking, public int $minutesBefore)
    {
        //
    }

    /**
     * Get the delivery channels for the notification.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing(['eventType', 'host', 'guests', 'answers']);
        $presenter = new BookingMailPresenter($booking, $notifiable);
        $lead = $this->minutesBefore >= 60
            ? (int) round($this->minutesBefore / 60).' hour'.($this->minutesBefore >= 120 ? 's' : '')
            : $this->minutesBefore.' minutes';

        $message = (new MailMessage)
            ->subject('Reminder: '.$booking->eventType->name.' in '.$lead)
            ->greeting('Hi '.$presenter->recipientName().',')
            ->line('This is a reminder that '.$booking->eventType->name.' starts in '.$lead.'.')
            ->line('**When:** '.$presenter->when())
            ->line('**Who:** '.$presenter->participants());

        foreach ($presenter->locationLines() as $line) {
            $message->line($line);
        }

        if (filled($description = $presenter->eventDescription())) {
            $message->line($description);
        }

        foreach ([...$presenter->inviteeLines(), ...$presenter->answerLines()] as $line) {
            $message->line($line);
        }

        return $presenter->withFooter($message);
    }
}

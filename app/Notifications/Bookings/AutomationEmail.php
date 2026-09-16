<?php

namespace App\Notifications\Bookings;

use App\Enums\AutomationRecipient;
use App\Models\Automation;
use App\Models\Booking;
use App\Models\User;
use App\Services\Automations\AutomationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email an automation sends. Its wording is entirely the organizer's --
 * subject and body come from the rule, with the booking's details filled in.
 */
class AutomationEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Automation $automation, public Booking $booking)
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
        $message = new AutomationMessage(
            $this->automation,
            $this->booking->loadMissing(['eventType', 'host', 'hosts', 'answers.question']),
            $this->timezoneFor($notifiable),
        );

        /*
         * A markdown view rather than ->line() calls: MailMessage flattens the
         * newlines out of a line, which would collapse the writer's paragraphs
         * and lists into one run-on sentence.
         */
        $mail = (new MailMessage)
            ->subject($message->subject())
            ->markdown('mail.automation', ['body' => $message->body()]);

        /*
         * A reply has to reach a person: the scheduling mailbox it sends as is
         * shared and unread. The same rule the booking emails follow -- a host
         * hears back from the invitee, everyone else from the hosts.
         */
        foreach ((new BookingMailPresenter($this->booking, $notifiable))->replyToAddresses() as $replyTo) {
            $mail->replyTo($replyTo['address'], $replyTo['name']);
        }

        return $mail;
    }

    /**
     * Get the timezone the recipient reads times in.
     *
     * A host is a real account with a timezone of their own. Everyone else is
     * only an address: the invitee's own timezone is on the booking, and a
     * bystander gets the organization's.
     */
    protected function timezoneFor(object $notifiable): string
    {
        if ($notifiable instanceof User) {
            $timezone = $notifiable->timezone;
        } elseif ($this->automation->recipient === AutomationRecipient::Invitee) {
            $timezone = $this->booking->invitee_timezone;
        } else {
            $timezone = $this->booking->team->timezone;
        }

        return $timezone ?: config('scheduling.default_timezone');
    }
}

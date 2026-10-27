<?php

namespace App\Notifications\Marketing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A "Contact sales" request from the public pricing page, sent to the sales
 * inbox with Reply-To set to the visitor so sales can answer straight from it.
 */
class SalesInquiryReceived extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  array{name: string, email: string, company: string, team_size: string, plan?: ?string, message?: ?string}  $inquiry
     */
    public function __construct(public array $inquiry) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $inquiry = $this->inquiry;

        $mail = (new MailMessage)
            ->subject(__('Sales inquiry from :company', ['company' => $inquiry['company']]))
            ->replyTo($inquiry['email'], $inquiry['name'])
            ->greeting(__('New sales inquiry'))
            ->line(__('Name: :name', ['name' => $inquiry['name']]))
            ->line(__('Email: :email', ['email' => $inquiry['email']]))
            ->line(__('Company: :company', ['company' => $inquiry['company']]))
            ->line(__('Team size: :size', ['size' => $inquiry['team_size']]));

        if (filled($inquiry['plan'] ?? null)) {
            $mail->line(__('Interested in: :plan', ['plan' => ucfirst($inquiry['plan'])]));
        }

        if (filled($inquiry['message'] ?? null)) {
            $mail->line(__('Message:'))->line($inquiry['message']);
        }

        return $mail->line(__('Reply to this email to answer them directly.'));
    }
}

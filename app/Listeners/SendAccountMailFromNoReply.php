<?php

namespace App\Listeners;

use App\Notifications\Teams\TeamInvitation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Sends mail about someone's account from the no-reply address.
 *
 * Booking mail comes from the bookings mailbox, where a reply reaches
 * somebody. A password reset, an email verification or an invitation has
 * nothing to reply to, so it comes from mail.account_from instead.
 *
 * Done here rather than in each notification because two of them are the
 * framework's own: overriding them would mean copying their content, and
 * subclassing would change the class every test asserts was sent.
 */
class SendAccountMailFromNoReply
{
    /**
     * The notifications that are about an account rather than a booking.
     *
     * @var array<int, class-string>
     */
    public const ACCOUNT_NOTIFICATIONS = [
        ResetPassword::class,
        VerifyEmail::class,
        TeamInvitation::class,
    ];

    /**
     * Handle the event.
     */
    public function handle(MessageSending $event): void
    {
        $notification = $event->data['__laravel_notification'] ?? null;

        if (! in_array($notification, self::ACCOUNT_NOTIFICATIONS, true)) {
            return;
        }

        $event->message->from(new Address(
            (string) config('mail.account_from.address'),
            (string) config('mail.account_from.name'),
        ));
    }
}

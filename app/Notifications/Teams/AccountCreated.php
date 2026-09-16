<?php

namespace App\Notifications\Teams;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone an account was made for them and lets them choose a password.
 *
 * Sent instead of the framework's password reset email, which says the
 * recipient asked for a reset and to ignore it otherwise -- the opposite of
 * what a person who never had an account should do.
 */
class AccountCreated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $token,
        public ?string $teamName = null,
    ) {
        //
    }

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
     *
     * Like the invitation, it does not name whoever created the account: the
     * recipient only needs to know where they have been added.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $appName = (string) config('app.name');
        $days = intdiv((int) config('auth.passwords.welcome.expire'), 1440);

        return (new MailMessage)
            ->subject(__('Welcome to :app', ['app' => $appName]))
            ->greeting(__('Hi :name,', ['name' => $notifiable->name]))
            ->line($this->teamName !== null
                ? __('An account has been created for you on :app, in the :team organization.', ['app' => $appName, 'team' => $this->teamName])
                : __('An account has been created for you on :app.', ['app' => $appName]))
            ->line(__('Choose a password to start using it.'))
            ->action(__('Set your password'), route('account-setup.show', [
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]))
            ->line(trans_choice('{1} This link works once and expires in 1 day.|[2,*] This link works once and expires in :count days.', $days, ['count' => $days]))
            ->line(__('If it has expired, use "Forgot password" on the sign-in page to get a new one.'));
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\AutomationChannel;
use App\Enums\AutomationRecipient;
use App\Enums\BookingStatus;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\Bookings\AutomationEmail;
use App\Services\Automations\AutomationMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

class RunAutomations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'automations:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send the workflow emails and text messages that have come due';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sent = 0;
        $failed = 0;

        AutomationRun::query()
            ->due()
            ->with(['automation', 'booking.eventType', 'booking.team', 'booking.host', 'booking.hosts', 'booking.guests', 'booking.answers.question'])
            ->chunkById(100, function ($runs) use (&$sent, &$failed) {
                foreach ($runs as $run) {
                    $booking = $run->booking;

                    /*
                     * Cancelling clears pending runs, so a non-confirmed
                     * booking here is one that changed after the row was
                     * written. Unlike reminders, a past start is no reason to
                     * drop it: "after the meeting ends" is due precisely then.
                     */
                    if ($booking->status !== BookingStatus::Confirmed) {
                        $run->delete();

                        continue;
                    }

                    try {
                        $this->send($run);
                        $run->update(['sent_at' => now()]);
                        $sent++;
                    } catch (Throwable $exception) {
                        /*
                         * Marked sent even though it failed: the scheduler runs
                         * every minute, and a bad address would otherwise
                         * be retried forever. The reason is kept on the row.
                         */
                        $run->update(['sent_at' => now(), 'failure' => str($exception->getMessage())->limit(250)->toString()]);
                        $failed++;

                        report($exception);
                    }
                }
            });

        $this->components->info("Sent {$sent} workflow message(s), {$failed} failed.");

        return self::SUCCESS;
    }

    /**
     * Send one queued run over each channel the workflow uses.
     */
    protected function send(AutomationRun $run): void
    {
        $automation = $run->automation;

        if ($automation->sends(AutomationChannel::Email)) {
            $this->sendEmail($automation, $run->booking);
        }

        if ($automation->sends(AutomationChannel::Sms)) {
            $this->sendTextMessage($automation, $run->booking);
        }
    }

    /**
     * Mail the workflow to whoever the rule names.
     */
    protected function sendEmail(Automation $automation, Booking $booking): void
    {
        $notification = new AutomationEmail($automation, $booking);

        match ($automation->recipient) {
            AutomationRecipient::Host => Notification::send($booking->attendingHosts(), $notification),
            AutomationRecipient::Invitee => $booking->inviteeEmails()->each(
                fn (string $email) => Notification::route('mail', $email)->notify($notification),
            ),
            /*
             * One send per address rather than a single email addressed to
             * them all: nobody on the list has agreed to have their address
             * shown to the rest of it.
             */
            AutomationRecipient::Someone => collect($automation->recipient_emails ?? [])->each(
                fn (string $email) => Notification::route('mail', $email)->notify($notification),
            ),
        };
    }

    /**
     * Text the workflow to whoever has a number on file.
     */
    protected function sendTextMessage(Automation $automation, Booking $booking): void
    {
        $team = $booking->team;
        $from = $team->sms_from_number;

        if (! $team->hasTwilio()) {
            throw new RuntimeException('This organization has not connected a Twilio account.');
        }

        if (blank($from)) {
            throw new RuntimeException('This organization has not chosen a number to send text messages from.');
        }

        $twilio = $team->twilio();

        foreach ($this->textRecipients($automation, $booking) as $recipient) {
            $message = new AutomationMessage($automation, $booking, $recipient['timezone'], markdown: false);

            $twilio->send($from, $recipient['phone'], $message->smsBody());
        }
    }

    /**
     * Work out who can be texted about this booking, and in whose timezone the
     * times should read.
     *
     * A recipient with no number on file is simply left out: a host who has
     * not filled theirs in is not a reason to fail the run for everyone else.
     *
     * @return array<int, array{phone: string, timezone: string}>
     */
    protected function textRecipients(Automation $automation, Booking $booking): array
    {
        $fallbackTimezone = $booking->team->timezone ?: config('scheduling.default_timezone');

        $recipients = match ($automation->recipient) {
            AutomationRecipient::Host => $booking->attendingHosts()
                ->map(fn (User $host) => [
                    'phone' => (string) $host->phone,
                    'timezone' => $host->timezone ?: $fallbackTimezone,
                ])
                ->all(),
            AutomationRecipient::Invitee => [[
                'phone' => (string) $booking->inviteePhone(),
                'timezone' => $booking->invitee_timezone ?: $fallbackTimezone,
            ]],
            AutomationRecipient::Someone => array_map(
                fn (string $phone) => ['phone' => $phone, 'timezone' => $fallbackTimezone],
                $automation->recipient_phones ?? [],
            ),
        };

        return array_values(array_filter($recipients, fn (array $recipient) => filled($recipient['phone'])));
    }
}

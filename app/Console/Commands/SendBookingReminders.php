<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\BookingReminder;
use App\Notifications\Bookings\BookingReminder as BookingReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendBookingReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send the booking reminders that have come due';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sent = 0;

        BookingReminder::query()
            ->due()
            ->with(['booking.team', 'booking.eventType', 'booking.host', 'booking.hosts', 'booking.guests'])
            ->chunkById(100, function ($reminders) use (&$sent) {
                foreach ($reminders as $reminder) {
                    $booking = $reminder->booking;

                    // A disabled organization sends nothing; dropped rather than
                    // held, so enabling it later does not fire stale reminders.
                    if ($booking->status !== BookingStatus::Confirmed || $booking->starts_at->isPast() || $booking->team?->isDisabled()) {
                        $reminder->delete();

                        continue;
                    }

                    $notification = new BookingReminderNotification($booking, $reminder->minutes_before);

                    Notification::send($booking->attendingHosts(), $notification);

                    foreach ($booking->inviteeEmails() as $email) {
                        Notification::route('mail', $email)->notify($notification);
                    }

                    $reminder->update(['sent_at' => now()]);
                    $sent++;
                }
            });

        $this->components->info("Sent {$sent} booking reminder(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Actions\Bookings;

use App\Enums\WebhookScope;
use App\Jobs\SendBookingWebhook;
use App\Models\ApiKey;
use App\Models\Booking;
use App\Notifications\Bookings\BookingCanceled;
use App\Notifications\Bookings\BookingConfirmed;
use App\Notifications\Bookings\BookingDeclined;
use App\Notifications\Bookings\BookingPendingApproval;
use App\Notifications\Bookings\BookingRescheduled;
use App\Support\Demo;
use Illuminate\Support\Facades\Notification;

class NotifyBookingParties
{
    /**
     * Tell everyone a booking has been made.
     */
    public function confirmed(Booking $booking): void
    {
        $this->send($booking, new BookingConfirmed($booking));
    }

    /**
     * Tell everyone a booking request awaits a host's approval.
     */
    public function pending(Booking $booking): void
    {
        $this->send($booking, new BookingPendingApproval($booking));
    }

    /**
     * Tell everyone a booking request was turned down.
     */
    public function declined(Booking $booking): void
    {
        $this->send($booking, new BookingDeclined($booking));
    }

    /**
     * Tell everyone a booking has been called off.
     */
    public function canceled(Booking $booking): void
    {
        $this->send($booking, new BookingCanceled($booking));
    }

    /**
     * Tell everyone a booking has moved.
     */
    public function rescheduled(Booking $booking, Booking $previous): void
    {
        $this->send($booking, new BookingRescheduled($booking, $previous));
    }

    /**
     * The webhook event each notification stands for.
     *
     * One dispatch point, here: a booking change that tells the people
     * involved also tells the system that sent the invitee, and one that
     * deliberately tells nobody (the Calendly importer writes around this
     * class) tells neither.
     *
     * @var array<class-string, string>
     */
    private const WEBHOOK_EVENTS = [
        BookingConfirmed::class => 'booking.confirmed',
        BookingPendingApproval::class => 'booking.requested',
        BookingDeclined::class => 'booking.declined',
        BookingCanceled::class => 'booking.canceled',
        BookingRescheduled::class => 'booking.rescheduled',
    ];

    /**
     * Deliver a notification to the invitee, their guests, and every host.
     */
    protected function send(Booking $booking, mixed $notification): void
    {
        Notification::send($booking->attendingHosts(), $notification);

        foreach ($booking->inviteeEmails() as $email) {
            Notification::route('mail', $email)->notify($notification);
        }

        $this->report($booking, $notification);
    }

    /**
     * Columns whose change is already reported by a lifecycle event above, or
     * that are bookkeeping nobody outside needs to hear about.
     *
     * @var list<string>
     */
    private const UNREPORTED_CHANGES = [
        'status', 'canceled_at', 'canceled_by', 'cancellation_reason', 'rescheduled_from_id',
        'webhook_sequence', 'created_at', 'updated_at',
    ];

    /**
     * Tell the systems listening for this booking about any other change to
     * the booking: a no-show, the host's notes, the meeting link, the
     * invitee's details. Called from the model on every update, so a writer
     * added later is reported without having to remember to.
     *
     * @param  list<string>  $columns
     */
    public function changed(Booking $booking, array $columns): void
    {
        $changes = array_values(array_diff($columns, self::UNREPORTED_CHANGES));

        if ($changes === []) {
            return;
        }

        $receivers = $this->receivers($booking);

        if ($receivers === []) {
            return;
        }

        Booking::withoutEvents(fn () => $booking->increment('webhook_sequence'));

        foreach ($receivers as $receiver) {
            SendBookingWebhook::dispatch($booking, 'booking.updated', (int) $booking->webhook_sequence, changes: $changes, receiver: $receiver)
                ->afterCommit();
        }
    }

    /**
     * Tell the systems listening for this booking, when any are.
     *
     * The booking's own sequence is bumped first and travels with the job,
     * so the receiver can tell a late retry of an older event from the
     * newest one however the deliveries are ordered on the wire.
     */
    private function report(Booking $booking, mixed $notification): void
    {
        if (! is_object($notification)) {
            return;
        }

        $event = self::WEBHOOK_EVENTS[$notification::class] ?? null;
        $receivers = $event === null ? [] : $this->receivers($booking);

        if ($receivers === []) {
            return;
        }

        Booking::withoutEvents(fn () => $booking->increment('webhook_sequence'));

        foreach ($receivers as $receiver) {
            SendBookingWebhook::dispatch($booking, $event, (int) $booking->webhook_sequence, receiver: $receiver);
        }
    }

    /**
     * Who hears about a booking: every key of its organization whose webhook
     * scope covers it, plus, for a referenced booking, the endpoint in
     * services.texra (null) when no key takes referenced bookings only.
     *
     * The fallback stands in for such a key, never alongside it, so an
     * application moved from the env to its key is not told twice with two
     * different secrets. A key taking every booking is a different listener
     * and does not displace it.
     *
     * The public demo has no receivers at all: its bookings are made by
     * strangers and must never reach a real system.
     *
     * @return list<?ApiKey>
     */
    private function receivers(Booking $booking): array
    {
        if (Demo::isDemoTeam($booking->team_id)) {
            return [];
        }

        $keys = ApiKey::query()->receivingWebhooksFor($booking->team_id)->get();

        $receivers = array_values($keys
            ->filter(fn (ApiKey $key): bool => $key->webhook_scope->covers($booking->reference))
            ->all());

        $referencedKeyExists = $keys->contains(fn (ApiKey $key): bool => $key->webhook_scope === WebhookScope::Referenced);

        return $booking->reference !== null && ! $referencedKeyExists
            ? [...$receivers, null]
            : $receivers;
    }
}

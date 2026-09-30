<?php

namespace App\Services\Webhooks;

use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * The body of a booking webhook: everything the receiver needs to file the
 * booking against its own record and reach the people in it, and nothing
 * secret. The reference goes back as it came in; no token of ours does.
 */
class BookingWebhookPayload
{
    /**
     * @param  list<string>  $changes  For booking.updated: which columns moved.
     * @return array<string, mixed>
     */
    public function build(Booking $booking, string $event, int $sequence, string $deliveryId, CarbonInterface $occurredAt, array $changes = []): array
    {
        $booking->loadMissing(['eventType', 'host', 'hosts', 'rescheduledFrom']);

        $host = $booking->host;
        $eventType = $booking->eventType;

        return [
            'id' => $deliveryId,
            'event' => $event,
            'occurred_at' => $occurredAt->toIso8601String(),
            'sequence' => $sequence,
            'changes' => $changes,
            'booking' => [
                'uid' => $booking->uid,
                'status' => $booking->status->value,
                'reference' => $booking->reference,
                'starts_at' => $booking->starts_at->copy()->utc()->toIso8601String(),
                'ends_at' => $booking->ends_at->copy()->utc()->toIso8601String(),
                'timezone' => $booking->invitee_timezone,
                'event_type' => [
                    'slug' => $eventType->slug,
                    'name' => $eventType->name,
                    'duration_minutes' => $eventType->duration_minutes,
                ],
                'host' => ['name' => $host->name, 'email' => $host->email],
                'hosts' => $booking->attendingHosts()
                    ->map(fn (User $user): array => ['name' => $user->name, 'email' => $user->email])
                    ->values()
                    ->all(),
                'invitee' => [
                    'name' => $booking->name,
                    'email' => $booking->email,
                    // location_detail is the invitee's number only where the
                    // event type asked them for one; elsewhere it is the host's.
                    'phone' => $booking->location_type->requiresInviteeInput() ? $booking->location_detail : null,
                ],
                'notes' => $booking->notes,
                'host_notes' => $booking->host_notes,
                'meeting_url' => $booking->meeting_url,
                'no_show_at' => $booking->no_show_at?->copy()->utc()->toIso8601String(),
                'rescheduled_from_uid' => $booking->rescheduledFrom?->uid,
                'cancellation' => $booking->canceled_at === null ? null : [
                    'reason' => $booking->cancellation_reason,
                    'by' => $booking->canceled_by,
                    'at' => $booking->canceled_at->copy()->utc()->toIso8601String(),
                ],
                'manage_url' => route('booking.show', ['booking' => $booking->uid]),
            ],
        ];
    }
}

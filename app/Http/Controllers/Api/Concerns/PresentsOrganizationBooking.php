<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\ApiKey;
use App\Models\Booking;
use App\Models\BookingAnswer;
use Illuminate\Http\Request;

/**
 * One meeting on an organization's calendar, in the shape the calendar
 * listing answers with, so a caller that changes a booking reads back the
 * same thing it already keeps a copy of.
 */
trait PresentsOrganizationBooking
{
    /**
     * Find a booking the calling key's organization holds.
     *
     * Another organization's booking is reported as not found rather than
     * forbidden: whether it exists is not this caller's business.
     */
    protected function bookingForKey(Request $request, string $uid): Booking
    {
        $booking = Booking::query()->where('uid', $uid)->first();
        $key = $request->attributes->get('api_key');

        abort_if($booking === null, 404);
        abort_unless($key instanceof ApiKey && $key->team_id !== null && $booking->team_id === $key->team_id, 404);

        return $booking;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentBooking(Booking $booking): array
    {
        $booking->loadMissing(['eventType', 'host', 'answers.question']);

        return [
            'uid' => $booking->uid,
            'status' => $booking->status->value,
            'eventName' => $booking->eventType?->name,
            'eventSlug' => $booking->eventType?->slug,
            'startsAt' => $booking->starts_at->toIso8601String(),
            'endsAt' => $booking->ends_at->toIso8601String(),
            'invitee' => [
                'name' => $booking->name,
                'email' => $booking->email,
                'timezone' => $booking->invitee_timezone,
                'phone' => $booking->inviteePhone(),
            ],
            'host' => [
                'name' => $booking->host?->name,
                'email' => $booking->host?->email,
            ],
            'answers' => $booking->answers
                ->values()
                ->map(fn (BookingAnswer $answer, int $index): array => [
                    'question' => $answer->label,
                    'answer' => $answer->answer,
                    'position' => $index,
                ])
                ->all(),
            // What the invitee wrote in "Please share anything that will
            // help prepare for our meeting", kept apart from the answers
            // to the event type's own questions.
            'notes' => $booking->notes,
            'zone' => $booking->zone,
            'canceledAt' => $booking->canceled_at?->toIso8601String(),
            'manageUrl' => route('booking.show', ['booking' => $booking]),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Booking;
use App\Models\BookingAnswer;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationBookingController extends Controller
{
    /**
     * Every meeting booked with an organization within a window.
     *
     * The per-email list answers "what have I booked"; this answers "what is
     * on the organization's calendar", for somewhere like the scoreboard that
     * shows the whole team's meetings. Canceled and rescheduled meetings are
     * included with their status, so a caller that keeps its own copy can
     * notice a meeting was called off rather than just seeing it vanish.
     */
    public function index(Request $request, string $organization): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        // Teams bind by slug, and an id is what survives a rename, so both
        // are accepted here as they are for a booking page.
        $organization = ctype_digit($organization)
            ? Team::query()->whereKey((int) $organization)->first()
            : Team::query()->where('slug', $organization)->first();

        abort_if($organization === null, 404);

        $key = $request->attributes->get('api_key');

        abort_if($key instanceof ApiKey && ! $key->canRead($organization), 404);

        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])->utc()
            : CarbonImmutable::now()->subDays(7);
        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'])->utc()
            : CarbonImmutable::now()->addDays(60);

        // A generous window is still bounded, so one call cannot pull the
        // organization's whole history.
        abort_if($from->diffInDays($to) > 366, 422, 'The window may not be longer than a year.');

        $bookings = Booking::query()
            ->with(['eventType', 'host', 'answers'])
            ->where('team_id', $organization->id)
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')
            ->limit(1000)
            ->get();

        return response()->json([
            'data' => $bookings->map(fn (Booking $booking): array => [
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
                'canceledAt' => $booking->canceled_at?->toIso8601String(),
                'manageUrl' => route('booking.show', ['booking' => $booking]),
            ])->values(),
            'meta' => [
                'organization' => $organization->name,
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
        ]);
    }
}

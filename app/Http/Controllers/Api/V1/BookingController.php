<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bookings\CreateBooking;
use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Scheduling\BookingPageResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private BookingPageResolver $pages) {}

    /**
     * Book a meeting on someone's behalf.
     *
     * Booking goes through the same action the public page uses, so the slot
     * is re-checked under a lock and the confirmation, calendar event and
     * reminders all happen exactly as they would otherwise.
     */
    public function store(Request $request, CreateBooking $createBooking): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['required', 'string'],
            'event_type' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $owner = $this->pages->resolve($validated['page']);

        abort_if($owner === null, 404);

        $eventType = $this->pages->eventType($owner, $validated['event_type']);

        abort_if($eventType === null, 404);

        try {
            $booking = $createBooking->handle(
                $eventType,
                CarbonImmutable::parse($validated['starts_at'])->utc(),
                [
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'notes' => $validated['notes'] ?? null,
                    'timezone' => $validated['timezone'] ?? null,
                ],
            );
        } catch (SlotUnavailableException) {
            // Someone else took it between the list being read and this call.
            return response()->json(['message' => 'That time has just been taken.'], 409);
        }

        return response()->json(['data' => $this->present($booking)], 201);
    }

    /**
     * Someone's upcoming meetings, so the application that sent them here can
     * show what they booked without asking them to come back.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        // The model's own scopes decide what "still happening" means, so this
        // cannot drift from the rest of the app.
        $bookings = Booking::query()
            ->with('eventType')
            ->where('email', $validated['email'])
            ->active()
            ->upcoming()
            ->orderBy('starts_at')
            ->limit(25)
            ->get();

        return response()->json([
            'data' => $bookings->map(fn (Booking $booking): array => $this->present($booking))->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'uid' => $booking->uid,
            'name' => $booking->eventType?->name,
            'startsAt' => $booking->starts_at->toIso8601String(),
            'endsAt' => $booking->ends_at->toIso8601String(),
            'timezone' => $booking->invitee_timezone,
            'locationLabel' => $booking->location_type->label(),
            'meetingUrl' => $booking->meeting_url,
            'manageUrl' => route('booking.show', ['booking' => $booking]),
        ];
    }
}

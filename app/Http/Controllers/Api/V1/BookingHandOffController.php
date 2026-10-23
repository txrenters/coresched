<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bookings\RescheduleBooking;
use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Api\Concerns\PresentsOrganizationBooking;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BookingHandOffController extends Controller
{
    use PresentsOrganizationBooking;

    /**
     * Move a meeting to a named host at the same time.
     *
     * Used when another agent takes over the lead in TEXRA: the renter keeps
     * their time and the new agent gets the call. Like a host handing it
     * on, this replaces the booking, so the answer is the replacement and
     * its new uid. The host must be free then (and in the booking's zone),
     * otherwise 409 and the caller decides what to do with the meeting.
     */
    public function store(Request $request, string $booking, RescheduleBooking $rescheduleBooking): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $model = $this->bookingForKey($request, $booking);

        abort_unless($model->isChangeable(), 410);

        $wanted = mb_strtolower(trim($validated['email']));
        $host = $model->reassignableHosts()->first(fn (User $host): bool => mb_strtolower($host->email) === $wanted);

        if ($host === null) {
            throw ValidationException::withMessages([
                'email' => 'That person does not host this meeting.',
            ]);
        }

        $current = $model->attendingHosts();

        // Handing a meeting to the host who already has it changes nothing.
        if ($current->count() === 1 && $current->first()->id === $host->id) {
            return response()->json(['data' => $this->presentBooking($model)]);
        }

        try {
            $replacement = $rescheduleBooking->handle(
                $model,
                CarbonImmutable::parse($model->starts_at)->utc(),
                $validated['reason'] ?? 'Handed to another host',
                $host,
                'host',
            );
        } catch (SlotUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->presentBooking($replacement)]);
    }
}

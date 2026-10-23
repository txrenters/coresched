<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\PresentsOrganizationBooking;
use App\Http\Controllers\Controller;
use App\Models\HostZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingZoneController extends Controller
{
    use PresentsOrganizationBooking;

    /**
     * Re-tag a booking with the zone whose hosts it now belongs to.
     *
     * TEXRA hands a lead to another group of agents (the Spanish speakers)
     * by naming their zone here: the meeting stays where it is, but a
     * reschedule from then on offers only that zone's hosts, because
     * Booking::keptHost() no longer pins it to a host outside the zone.
     */
    public function update(Request $request, string $booking): JsonResponse
    {
        $validated = $request->validate([
            'zone' => ['present', 'nullable', 'string', 'regex:'.HostZone::PATTERN],
        ]);

        $model = $this->bookingForKey($request, $booking);

        abort_unless($model->isChangeable(), 410);

        // A plain update, so the change goes out as booking.updated.
        $model->update(['zone' => $validated['zone']]);

        return response()->json(['data' => $this->presentBooking($model)]);
    }
}

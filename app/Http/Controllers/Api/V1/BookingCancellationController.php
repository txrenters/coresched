<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bookings\CancelBooking;
use App\Http\Controllers\Api\Concerns\PresentsOrganizationBooking;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingCancellationController extends Controller
{
    use PresentsOrganizationBooking;

    /**
     * Call off a meeting on the host side.
     *
     * For when a hand-off finds the new host busy at that time: the renter
     * is told it was canceled exactly as if a host had done it.
     */
    public function store(Request $request, string $booking, CancelBooking $cancelBooking): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $model = $this->bookingForKey($request, $booking);

        abort_unless($model->isChangeable(), 410);

        $canceled = $cancelBooking->handle($model, $validated['reason'] ?? null, 'host');

        return response()->json(['data' => $this->presentBooking($canceled)]);
    }
}

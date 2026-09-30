<?php

namespace App\Http\Controllers\Booking;

use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\RescheduleBooking;
use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\StoreBookingRequest;
use App\Models\Booking;
use App\Models\SingleUseLink;
use App\Services\Scheduling\BookingPageResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PublicBookingController extends Controller
{
    public function __construct(protected BookingPageResolver $pages)
    {
        //
    }

    /**
     * Book a slot.
     */
    public function store(StoreBookingRequest $request, CreateBooking $createBooking): RedirectResponse
    {
        $validated = $request->validated();
        $eventType = $request->eventType();

        $hostId = (int) ($validated['host_id'] ?? 0);
        $host = $eventType->canBeBookedOnBehalfBy($request->user())
            ? $eventType->assignableHosts()->firstWhere('id', $hostId)
            : null;

        abort_if($hostId !== 0 && $host === null, 403);

        try {
            $booking = $createBooking->handle(
                $request->eventType(),
                CarbonImmutable::parse($validated['starts_at'])->utc(),
                [
                    'name' => (string) $validated['name'],
                    'email' => (string) $validated['email'],
                    'notes' => $validated['notes'] ?? null,
                    'timezone' => $validated['timezone'] ?? null,
                    // Only a number the invitee is asked for: every other
                    // location is the host's, and a posted value must not
                    // replace their address or meeting link.
                    'location_detail' => $request->eventType()->location_type->requiresInviteeInput()
                        ? ($validated['location_detail'] ?? null)
                        : null,
                    'guests' => $validated['guests'] ?? [],
                    'answers' => $validated['answers'] ?? [],
                    'reference' => $validated['reference'] ?? null,
                ],
                host: $host,
            );
        } catch (SlotUnavailableException $exception) {
            throw ValidationException::withMessages([
                'starts_at' => $exception->getMessage(),
            ]);
        }

        return to_route('booking.show', ['booking' => $booking->uid]);
    }

    /**
     * Book a slot through a single-use link, which then stops working.
     *
     * The link is claimed before booking with a conditional update, so two
     * people submitting at once cannot both use it; a failed booking gives
     * it back.
     */
    public function storeFromLink(StoreBookingRequest $request, SingleUseLink $singleUseLink, CreateBooking $createBooking): RedirectResponse
    {
        $claimed = SingleUseLink::query()
            ->whereKey($singleUseLink->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        abort_if($claimed === 0, 410);

        $validated = $request->validated();
        $eventType = $request->eventType();

        try {
            $booking = $createBooking->handle(
                $eventType,
                CarbonImmutable::parse($validated['starts_at'])->utc(),
                [
                    'name' => (string) $validated['name'],
                    'email' => (string) $validated['email'],
                    'notes' => $validated['notes'] ?? null,
                    'timezone' => $validated['timezone'] ?? null,
                    'location_detail' => $eventType->location_type->requiresInviteeInput()
                        ? ($validated['location_detail'] ?? null)
                        : null,
                    'guests' => $validated['guests'] ?? [],
                    'answers' => $validated['answers'] ?? [],
                ],
            );
        } catch (SlotUnavailableException $exception) {
            SingleUseLink::query()->whereKey($singleUseLink->id)->update(['used_at' => null]);

            throw ValidationException::withMessages([
                'starts_at' => $exception->getMessage(),
            ]);
        }

        $singleUseLink->update(['booking_id' => $booking->id]);

        return to_route('booking.show', ['booking' => $booking->uid]);
    }

    /**
     * Show a booking's confirmation page.
     */
    public function show(Booking $booking): Response
    {
        return Inertia::render('book/Confirmed', [
            'booking' => $this->toPayload($booking),
        ]);
    }

    /**
     * Show the reschedule picker for a booking.
     */
    public function editSchedule(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($booking->isChangeable() && ! $booking->eventType->trashed(), 410);

        $query = http_build_query(array_filter([
            'reschedule' => $booking->uid,
            'timezone' => $booking->invitee_timezone,
            'host' => $request->integer('host') ?: null,
        ]));

        return redirect()->to($booking->eventType->publicUrl().'?'.$query);
    }

    /**
     * Move a booking to a new time.
     */
    public function updateSchedule(Request $request, Booking $booking, RescheduleBooking $rescheduleBooking): RedirectResponse
    {
        abort_unless($booking->isChangeable() && ! $booking->eventType->trashed(), 410);

        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
            'host_id' => ['nullable', 'integer'],
        ]);

        $hostId = (int) ($validated['host_id'] ?? 0);
        $host = $booking->chosenHostFor($request->user(), $hostId);

        abort_if($hostId !== 0 && $host === null, 403);

        $byHost = $request->user()?->can('cancel', $booking) ?? false;

        try {
            $replacement = $rescheduleBooking->handle(
                $booking,
                CarbonImmutable::parse($validated['starts_at'])->utc(),
                $validated['reason'] ?? null,
                $host,
                $byHost ? 'host' : 'invitee',
            );
        } catch (SlotUnavailableException $exception) {
            throw ValidationException::withMessages([
                'starts_at' => $exception->getMessage(),
            ]);
        }

        return to_route('booking.show', ['booking' => $replacement->uid]);
    }

    /**
     * Show the cancellation screen for a booking.
     */
    public function confirmCancel(Booking $booking): Response
    {
        return Inertia::render('book/Cancel', [
            'booking' => $this->toPayload($booking),
        ]);
    }

    /**
     * Cancel a booking as the invitee.
     */
    public function cancel(Request $request, Booking $booking, CancelBooking $cancelBooking): RedirectResponse
    {
        abort_unless($booking->isChangeable(), 410);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $cancelBooking->handle($booking, $validated['reason'] ?? null);

        return to_route('booking.show', ['booking' => $booking->uid]);
    }

    /**
     * Present a booking for the public screens.
     *
     * @return array<string, mixed>
     */
    protected function toPayload(Booking $booking): array
    {
        $booking->loadMissing(['eventType', 'host', 'hosts', 'guests', 'answers']);
        $timezone = $booking->invitee_timezone ?: config('scheduling.default_timezone');

        return [
            'uid' => $booking->uid,
            'status' => $booking->status->value,
            'eventTypeName' => $booking->eventType->name,
            'hostNames' => ($booking->hosts->isNotEmpty() ? $booking->hosts : collect([$booking->host])->filter())
                ->pluck('name')->values(),
            'inviteeName' => $booking->name,
            'inviteeEmail' => $booking->email,
            'startsAt' => $booking->starts_at->toIso8601String(),
            'localDate' => $booking->starts_at->setTimezone($timezone)->format('l, j F Y'),
            'localTime' => $booking->starts_at->setTimezone($timezone)->format('g:ia')
                .' - '.$booking->ends_at->setTimezone($timezone)->format('g:ia'),
            'timezone' => $timezone,
            'locationLabel' => $booking->location_type->label(),
            'locationDetail' => $booking->location_detail,
            'meetingUrl' => $booking->meeting_url,
            'notes' => $booking->notes,
            'guests' => $booking->guests->pluck('email')->values(),
            'answers' => $booking->answers->map(fn ($answer) => [
                'label' => $answer->label,
                'answer' => $answer->answer,
            ])->values(),
            'isChangeable' => $booking->isChangeable(),
            'cancellationReason' => $booking->cancellation_reason,
            'rescheduleUrl' => route('booking.reschedule', ['booking' => $booking->uid]),
            'cancelUrl' => route('booking.cancel', ['booking' => $booking->uid]),
        ];
    }
}

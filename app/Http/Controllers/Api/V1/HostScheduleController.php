<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\SaveAvailabilitySchedule;
use App\Http\Controllers\Api\Concerns\ResolvesBookingPage;
use App\Http\Controllers\Api\Concerns\SerialisesHostSchedule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SaveHostScheduleRequest;
use App\Models\User;
use App\Services\Scheduling\BookingPageResolver;
use App\Services\Scheduling\ScheduleResolver;
use Illuminate\Http\JsonResponse;

class HostScheduleController extends Controller
{
    use ResolvesBookingPage, SerialisesHostSchedule;

    public function __construct(
        private BookingPageResolver $pages,
        private ScheduleResolver $schedules,
        private SaveAvailabilitySchedule $save,
    ) {}

    /**
     * Replace the personal hours of one host of an event type.
     *
     * The host is found by email on the event type's rota, so a key cannot
     * reach anybody outside the pools it already reads. What is written is
     * the host's default personal schedule -- the one the resolver falls
     * back to -- and the answer says which layer actually governs, because
     * a group's shared hours would make these changes invisible.
     */
    public function update(SaveHostScheduleRequest $request, string $page, string $eventType, string $email): JsonResponse
    {
        $owner = $this->pageForKey($request, $this->pages, $page);
        $type = $this->pages->eventType($owner, $eventType);

        abort_if($type === null, 404);

        $wanted = mb_strtolower(trim($email));
        $host = $type->hostPool()->first(fn (User $host): bool => mb_strtolower($host->email) === $wanted);

        abort_if($host === null, 404);

        $validated = $request->validated();
        $existing = $host->defaultAvailabilitySchedule();

        $schedule = $this->save->handle($host, [
            'name' => $existing->name ?? 'Working hours',
            'timezone' => $validated['timezone'],
            'is_default' => true,
            'is_active' => true,
            'rules' => $validated['rules'],
            'overrides' => $validated['overrides'] ?? [],
        ], $existing);

        return response()->json([
            'schedule' => $this->schedule($schedule),
            'governs' => $this->schedules->source($type, $host),
        ]);
    }
}

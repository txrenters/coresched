<?php

namespace App\Http\Controllers\Scheduling;

use App\Actions\Scheduling\SaveAvailabilitySchedule;
use App\Enums\CalendarProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\SaveAvailabilityScheduleRequest;
use App\Models\AvailabilityOverride;
use App\Models\AvailabilityRule;
use App\Models\AvailabilitySchedule;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Team;
use App\Services\Calendar\CalendarProviderManager;
use App\Services\Calendar\DetectsLeaveContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AvailabilityScheduleController extends Controller
{
    /**
     * Display the user's availability schedules.
     */
    public function index(Request $request, Team $current_team): Response
    {
        $user = $request->user();

        $schedules = $user->availabilitySchedules()
            ->with(['rules', 'overrides'])
            ->withCount('eventTypes')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $shared = $current_team->availabilitySchedules()
            ->with(['rules', 'overrides'])
            ->withCount('eventTypes')
            ->orderBy('name')
            ->get();

        return Inertia::render('scheduling/availability/Index', [
            'schedules' => $schedules->map(fn (AvailabilitySchedule $schedule) => $this->toPayload($schedule)),
            'sharedSchedules' => $shared->map(fn (AvailabilitySchedule $schedule) => $this->toPayload($schedule)),
            'canManageShared' => $user->can('createShared', [AvailabilitySchedule::class, $current_team]),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    /**
     * Show which calendars are checked for conflicts and written to.
     */
    public function calendars(Request $request, Team $current_team, CalendarProviderManager $providers): Response
    {
        $accounts = $request->user()
            ->calendarAccounts()
            ->with(['calendars', 'leavePeriod'])
            ->orderBy('provider')
            ->get();

        return Inertia::render('scheduling/availability/Calendars', [
            'providers' => array_map(fn (CalendarProvider $provider) => [
                'value' => $provider->value,
                'label' => $provider->label(),
                'isConfigured' => $provider->isConfigured(),
                'isConnected' => $accounts->contains(
                    fn (CalendarAccount $account) => $account->provider === $provider,
                ),
            ], CalendarProvider::cases()),
            'accounts' => $accounts->map(fn (CalendarAccount $account) => [
                'id' => $account->id,
                'provider' => $account->provider->value,
                'providerLabel' => $account->provider->label(),
                'email' => $account->email,
                'lastSyncedAt' => $account->last_synced_at?->toIso8601String(),
                'syncError' => $account->sync_error,
                'detectsLeave' => $providers->driver($account->provider) instanceof DetectsLeaveContract,
                'leave' => $account->leavePeriod === null ? null : [
                    'startsAt' => $account->leavePeriod->starts_at->toIso8601String(),
                    'endsAt' => $account->leavePeriod->ends_at->toIso8601String(),
                    'message' => $account->leavePeriod->message,
                ],
                'calendars' => $account->calendars->map(fn (Calendar $calendar) => [
                    'id' => $calendar->id,
                    'name' => $calendar->name,
                    'isPrimary' => $calendar->is_primary,
                    'checksConflicts' => $calendar->checks_conflicts,
                    'isWriteTarget' => $calendar->is_write_target,
                ])->values(),
            ]),
        ]);
    }

    /**
     * Store a new schedule.
     */
    public function store(SaveAvailabilityScheduleRequest $request, Team $current_team, SaveAvailabilitySchedule $saveSchedule): RedirectResponse
    {
        Gate::authorize('create', AvailabilitySchedule::class);

        $owner = $request->user();

        // Hours kept on the organization's behalf are an administrator's.
        if ($request->boolean('is_shared')) {
            Gate::authorize('createShared', [AvailabilitySchedule::class, $current_team]);

            $owner = $current_team;
        }

        $saveSchedule->handle($owner, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Schedule created.')]);

        return to_route('availability.index', ['current_team' => $current_team->slug]);
    }

    /**
     * Update a schedule.
     */
    public function update(
        SaveAvailabilityScheduleRequest $request,
        Team $current_team,
        AvailabilitySchedule $availability,
        SaveAvailabilitySchedule $saveSchedule,
    ): RedirectResponse {
        Gate::authorize('update', $availability);

        $saveSchedule->handle(
            $availability->isShared() ? $availability->team : $request->user(),
            $request->validated(),
            $availability,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Availability updated.')]);

        return to_route('availability.index', ['current_team' => $current_team->slug]);
    }

    /**
     * Delete a schedule.
     */
    public function destroy(Team $current_team, AvailabilitySchedule $availability): RedirectResponse
    {
        Gate::authorize('delete', $availability);

        $availability->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Schedule deleted.')]);

        return to_route('availability.index', ['current_team' => $current_team->slug]);
    }

    /**
     * Present a schedule for the availability screen.
     *
     * @return array<string, mixed>
     */
    protected function toPayload(AvailabilitySchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'timezone' => $schedule->timezone,
            'isDefault' => $schedule->is_default,
            'isShared' => $schedule->isShared(),
            'isActive' => $schedule->is_active,
            'eventTypeCount' => $schedule->event_types_count,
            'summary' => $schedule->summary(),
            'rules' => $schedule->rules->map(fn (AvailabilityRule $rule) => [
                'day_of_week' => $rule->day_of_week,
                'starts_at' => substr($rule->starts_at, 0, 5),
                'ends_at' => substr($rule->ends_at, 0, 5),
            ])->values(),
            'overrides' => $schedule->overrides->map(fn (AvailabilityOverride $override) => [
                'date' => $override->date->toDateString(),
                'is_unavailable' => $override->is_unavailable,
                'starts_at' => $override->starts_at ? substr($override->starts_at, 0, 5) : null,
                'ends_at' => $override->ends_at ? substr($override->ends_at, 0, 5) : null,
            ])->values(),
        ];
    }
}

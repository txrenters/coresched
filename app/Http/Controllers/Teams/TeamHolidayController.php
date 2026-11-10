<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\StoreTeamClosureRequest;
use App\Http\Requests\Teams\UpdateTeamHolidaysRequest;
use App\Models\Team;
use App\Models\TeamClosure;
use App\Services\Activity\ActivityLogger;
use App\Services\Scheduling\HolidayCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's advanced settings: holidays and blocked dates every
 * member is unavailable on, so invitees cannot book anyone in it then.
 */
class TeamHolidayController extends Controller
{
    /**
     * Show the organization's holidays and blocked dates.
     */
    public function edit(Request $request, Team $team, HolidayCalendar $holidays): Response
    {
        $country = $team->holiday_country;

        return Inertia::render('teams/Advanced', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'canUpdate' => $request->user()->can('update', $team),
            'holidayCountry' => $country,
            'countries' => $holidays->countries(),
            'holidays' => blank($country) ? [] : $holidays->forCountry($country),
            'requiredHolidays' => $team->requiredHolidays(),
            // Past dates no longer block anything, so they are left off.
            'closures' => $team->closures()
                ->whereDate('ends_on', '>=', today())
                ->orderBy('starts_on')
                ->get()
                ->map(fn (TeamClosure $closure) => [
                    'id' => $closure->id,
                    'name' => $closure->name,
                    'startsOn' => $closure->starts_on->toDateString(),
                    'endsOn' => $closure->ends_on->toDateString(),
                ]),
        ]);
    }

    /**
     * Save which holidays every member is unavailable on.
     */
    public function update(UpdateTeamHolidaysRequest $request, Team $team): RedirectResponse
    {
        $country = $request->validated('holiday_country');
        $keys = $country === null ? [] : array_values(array_unique($request->validated('holidays', [])));

        DB::transaction(function () use ($team, $country, $keys) {
            $team->update(['holiday_country' => $country]);

            DB::table('team_holidays')->where('team_id', $team->id)->delete();

            $now = now();

            DB::table('team_holidays')->insert(array_map(fn (string $key) => [
                'team_id' => $team->id,
                'holiday_key' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ], $keys));
        });

        app(ActivityLogger::class)->record(
            $team,
            'organization.holidays_updated',
            'Updated the organization holidays',
            $team,
            ['country' => $country, 'holidays' => $keys],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Holidays saved.')]);

        return to_route('teams.advanced.edit', ['team' => $team->slug]);
    }

    /**
     * Block a day, or a run of days, for everyone in the organization.
     */
    public function storeClosure(StoreTeamClosureRequest $request, Team $team): RedirectResponse
    {
        $closure = $team->closures()->create([
            'name' => $request->validated('name'),
            'starts_on' => $request->validated('starts_on'),
            'ends_on' => $request->validated('ends_on') ?? $request->validated('starts_on'),
        ]);

        app(ActivityLogger::class)->record(
            $team,
            'organization.closure_added',
            "Blocked {$closure->name}",
            $team,
            ['starts_on' => $closure->starts_on->toDateString(), 'ends_on' => $closure->ends_on->toDateString()],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dates blocked.')]);

        return to_route('teams.advanced.edit', ['team' => $team->slug]);
    }

    /**
     * Open blocked dates back up for booking.
     */
    public function destroyClosure(Team $team, TeamClosure $closure): RedirectResponse
    {
        Gate::authorize('update', $team);

        abort_unless($closure->team_id === $team->id, 404);

        $closure->delete();

        app(ActivityLogger::class)->record(
            $team,
            'organization.closure_removed',
            "Unblocked {$closure->name}",
            $team,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Dates unblocked.')]);

        return to_route('teams.advanced.edit', ['team' => $team->slug]);
    }
}

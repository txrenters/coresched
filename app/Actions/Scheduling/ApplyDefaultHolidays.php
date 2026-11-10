<?php

namespace App\Actions\Scheduling;

use App\Models\Team;
use App\Services\Scheduling\HolidayCalendar;
use Illuminate\Support\Facades\DB;

class ApplyDefaultHolidays
{
    public function __construct(protected HolidayCalendar $holidays)
    {
        //
    }

    /**
     * Opt an organization in to its country's public holidays.
     *
     * Being closed on a public holiday is the expectation rather than the
     * exception, so a new organization starts with every one switched on and
     * an admin turns off the ones it stays open for.
     */
    public function handle(Team $team, ?string $country = null): void
    {
        $country ??= config('scheduling.default_holiday_country');

        if (blank($country)) {
            return;
        }

        $holidays = $this->holidays->forCountry($country);

        if ($holidays->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($team, $country, $holidays) {
            $team->update(['holiday_country' => $country]);

            $now = now();

            DB::table('team_holidays')->upsert(
                $holidays->map(fn (array $holiday) => [
                    'team_id' => $team->id,
                    'holiday_key' => $holiday['key'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
                ['team_id', 'holiday_key'],
                ['updated_at'],
            );
        });
    }
}

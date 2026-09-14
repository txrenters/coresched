<?php

namespace App\Jobs;

use App\Models\BusyBlock;
use App\Models\CalendarAccount;
use App\Services\Calendar\CalendarProviderManager;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncCalendarBusyBlocks implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    public function __construct(
        public CalendarAccount $account,
        public int $daysAhead = 60,
    ) {
        // The hourly sweep queues one of these per account. On the default
        // queue that backlog held up every booking email behind it.
        $this->onQueue('calendars');
    }

    /**
     * Pull busy times from the provider and cache them locally.
     */
    public function handle(CalendarProviderManager $providers): void
    {
        $account = $this->account->fresh('calendars');

        if ($account === null) {
            return;
        }

        $calendars = $account->calendars->where('checks_conflicts', true);

        if ($calendars->isEmpty()) {
            return;
        }

        $window = new TimeRange(
            CarbonImmutable::now('UTC')->startOfDay(),
            CarbonImmutable::now('UTC')->addDays($this->daysAhead)->endOfDay(),
        );

        try {
            $driver = $providers->for($account);

            foreach ($calendars as $calendar) {
                $busy = $driver->busyPeriods($account, [$calendar->external_id], $window);

                DB::transaction(function () use ($calendar, $account, $busy, $window) {
                    // Replace the cached window wholesale: the provider is the
                    // source of truth and events may have moved or vanished.
                    BusyBlock::query()
                        ->where('calendar_id', $calendar->id)
                        ->where('ends_at', '>', $window->start)
                        ->where('starts_at', '<', $window->end)
                        ->delete();

                    foreach (TimeRange::merge($busy) as $range) {
                        BusyBlock::create([
                            'calendar_id' => $calendar->id,
                            'user_id' => $account->user_id,
                            'starts_at' => $range->start,
                            'ends_at' => $range->end,
                        ]);
                    }
                });
            }

            $account->update(['last_synced_at' => now(), 'sync_error' => null]);
        } catch (Throwable $exception) {
            $account->update(['sync_error' => $exception->getMessage()]);

            throw $exception;
        }
    }
}

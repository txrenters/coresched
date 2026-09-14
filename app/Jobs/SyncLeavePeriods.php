<?php

namespace App\Jobs;

use App\Exceptions\MailboxAccessDeniedException;
use App\Models\CalendarAccount;
use App\Models\LeavePeriod;
use App\Services\Calendar\CalendarProviderManager;
use App\Services\Calendar\DetectsLeaveContract;
use App\Support\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class SyncLeavePeriods implements ShouldQueue
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
     * Ask the mailbox whether its owner is away and cache the answer.
     */
    public function handle(CalendarProviderManager $providers): void
    {
        $account = $this->account->fresh();

        if ($account === null) {
            return;
        }

        $window = new TimeRange(
            CarbonImmutable::now('UTC')->startOfDay(),
            CarbonImmutable::now('UTC')->addDays($this->daysAhead)->endOfDay(),
        );

        try {
            $driver = $providers->for($account);

            if (! $driver instanceof DetectsLeaveContract) {
                return;
            }

            $leave = $driver->leavePeriod($account, $window);
        } catch (MailboxAccessDeniedException $exception) {
            // Only the account owner can grant the mailbox scope, so retrying
            // this job cannot win it back. Surface it and stop.
            $account->update(['sync_error' => $this->summarise($exception)]);

            return;
        } catch (Throwable $exception) {
            $account->update(['sync_error' => $this->summarise($exception)]);

            throw $exception;
        }

        if ($leave === null) {
            LeavePeriod::query()->where('calendar_account_id', $account->id)->delete();

            return;
        }

        LeavePeriod::query()->updateOrCreate(
            ['calendar_account_id' => $account->id],
            [
                'user_id' => $account->user_id,
                'starts_at' => $leave->startsAt,
                'ends_at' => $leave->endsAt,
                'message' => $leave->message,
            ],
        );
    }

    /**
     * Trim a failure down to something sync_error can actually hold.
     *
     * The column is a varchar(255) and Graph failures are not: a cURL timeout
     * quotes the whole request URL and overflows it, which made the write
     * itself throw and buried the real error.
     */
    protected function summarise(Throwable $exception): string
    {
        return Str::limit($exception->getMessage(), 250);
    }
}

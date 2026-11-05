<?php

use App\Models\PageVisit;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

/*
 * Reminders and workflows run every minute: a workflow set to send
 * "immediately when a meeting is booked" waits for this run, so at five
 * minutes those emails went out up to five minutes late. The overlap lock
 * expires after ten minutes, not the default day, so a scheduler killed
 * mid-run by a deploy cannot hold every reminder and workflow until tomorrow.
 */
Schedule::command('bookings:send-reminders')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->description('Send due booking reminders');

Schedule::command('automations:run')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->description('Send due workflow emails');

Schedule::command('calendars:sync')
    ->hourly()
    ->withoutOverlapping()
    ->description('Refresh cached busy times from connected calendars');

/*
 * The public demo is shared by every visitor, so it starts each day clean.
 * Runs at night Central time, when nobody is likely to be mid-demo.
 */
Schedule::command('demo:reset')
    ->dailyAt('03:00')
    ->timezone('America/Chicago')
    ->withoutOverlapping()
    ->description('Rebuild the public demo organization');

/*
 * Website traffic counts are kept 13 months (PageVisit::prunable), enough
 * for a year-on-year look at the admin dashboard.
 */
Schedule::command('model:prune', ['--model' => [PageVisit::class]])
    ->daily()
    ->description('Prune old website traffic counts');

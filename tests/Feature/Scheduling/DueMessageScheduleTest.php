<?php

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Find the scheduled event that runs an artisan command.
 */
function scheduledCommand(string $command): Event
{
    return collect(app(Schedule::class)->events())
        ->sole(fn (Event $event) => str_contains((string) $event->command, $command));
}

test('reminders and workflow emails are picked up at every minute, not every fifth', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 08:01:00', 'UTC'));

    $dueCommands = collect(app(Schedule::class)->dueEvents(app()))
        ->map(fn (Event $event) => (string) $event->command)
        ->filter(fn (string $command) => str_contains($command, 'bookings:send-reminders') || str_contains($command, 'automations:run'));

    expect($dueCommands)->toHaveCount(2);
});

test('a run killed mid-send stops blocking reminders and workflows after ten minutes', function (string $command) {
    // The database store is what production runs on; the array store's lock
    // ignores its own expiry when asked whether it is still held.
    config(['cache.default' => 'database']);

    $this->travelTo(CarbonImmutable::parse('2026-09-14 08:00:00', 'UTC'));

    $event = scheduledCommand($command);
    $event->mutex->create($event);

    $this->travel(9)->minutes();
    expect($event->mutex->exists($event))->toBeTrue();

    $this->travel(2)->minutes();
    expect($event->mutex->exists($event))->toBeFalse();
})->with(['bookings:send-reminders', 'automations:run']);

<?php

use App\Http\Controllers\Scheduling\AutomationController;
use App\Http\Controllers\Scheduling\AvailabilityScheduleController;
use App\Http\Controllers\Scheduling\CalendarAccountController;
use App\Http\Controllers\Scheduling\CalendarOAuthController;
use App\Http\Controllers\Scheduling\EventTypeController;
use App\Http\Controllers\Scheduling\GroupController;
use App\Http\Controllers\Scheduling\MeetingController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('scheduling', [EventTypeController::class, 'index'])->name('scheduling.index');
        Route::post('scheduling', [EventTypeController::class, 'store'])->name('scheduling.store');

        // Scoped so an event type slug always resolves within the current team.
        Route::scopeBindings()->group(function () {
            Route::get('scheduling/{event_type}', [EventTypeController::class, 'edit'])->name('scheduling.edit');
            Route::patch('scheduling/{event_type}', [EventTypeController::class, 'update'])->name('scheduling.update');
            Route::patch('scheduling/{event_type}/active', [EventTypeController::class, 'updateActive'])->name('scheduling.active.update');
            Route::post('scheduling/{event_type}/duplicate', [EventTypeController::class, 'duplicate'])->name('scheduling.duplicate');
            Route::post('scheduling/{event_type}/single-use-links', [EventTypeController::class, 'createSingleUseLink'])->name('scheduling.single-use-links.store');
            Route::delete('scheduling/{event_type}', [EventTypeController::class, 'destroy'])->name('scheduling.destroy');
        });

        Route::get('groups', [GroupController::class, 'index'])->name('groups.index');
        Route::post('groups', [GroupController::class, 'store'])->name('groups.store');

        Route::scopeBindings()->group(function () {
            Route::patch('groups/{group}', [GroupController::class, 'update'])->name('groups.update');
            Route::delete('groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');
        });

        // "Workflows" in the UI; the rule itself is an Automation.
        Route::get('automations', [AutomationController::class, 'index'])->name('automations.index');
        Route::get('automations/new', [AutomationController::class, 'create'])->name('automations.create');
        Route::post('automations', [AutomationController::class, 'store'])->name('automations.store');

        // Scoped so a workflow only resolves inside the organization that owns it.
        Route::scopeBindings()->group(function () {
            Route::get('automations/{automation}', [AutomationController::class, 'edit'])->name('automations.edit');
            Route::patch('automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
            Route::delete('automations/{automation}', [AutomationController::class, 'destroy'])->name('automations.destroy');
        });

        Route::get('availability', [AvailabilityScheduleController::class, 'index'])->name('availability.index');
        Route::get('availability/calendars', [AvailabilityScheduleController::class, 'calendars'])->name('availability.calendars');
        Route::get('availability/advanced', [AvailabilityScheduleController::class, 'advanced'])->name('availability.advanced');
        Route::patch('availability/advanced', [AvailabilityScheduleController::class, 'updateAdvanced'])->name('availability.advanced.update');
        Route::post('availability', [AvailabilityScheduleController::class, 'store'])->name('availability.store');
        Route::patch('availability/{availability}', [AvailabilityScheduleController::class, 'update'])->name('availability.update');
        Route::delete('availability/{availability}', [AvailabilityScheduleController::class, 'destroy'])->name('availability.destroy');

        Route::get('meetings', [MeetingController::class, 'index'])->name('meetings.index');
        Route::post('meetings/hand-off', [MeetingController::class, 'handOffAll'])->name('meetings.hand-off-all');
        Route::scopeBindings()->group(function () {
            Route::patch('meetings/{booking}/notes', [MeetingController::class, 'updateNotes'])->name('meetings.notes.update');
            Route::post('meetings/{booking}/approve', [MeetingController::class, 'approve'])->name('meetings.approve');
            Route::post('meetings/{booking}/decline', [MeetingController::class, 'decline'])->name('meetings.decline');
            Route::post('meetings/{booking}/hand-off', [MeetingController::class, 'handOff'])->name('meetings.hand-off');
            Route::post('meetings/{booking}/no-show', [MeetingController::class, 'markNoShow'])->name('meetings.no-show.store');
            Route::delete('meetings/{booking}/no-show', [MeetingController::class, 'undoNoShow'])->name('meetings.no-show.destroy');
            Route::delete('meetings/{booking}', [MeetingController::class, 'destroy'])->name('meetings.destroy');
        });
    });

Route::middleware(['auth', 'verified'])->group(function () {
    // Calendars are managed under Availability, but the OAuth redirect URI is
    // registered with Google and Microsoft, so these paths must not move.
    Route::get('integrations/{provider}/connect', [CalendarOAuthController::class, 'redirect'])->name('integrations.connect');
    Route::get('integrations/{provider}/callback', [CalendarOAuthController::class, 'callback'])->name('integrations.callback');

    Route::patch('integrations/calendars/{calendar}', [CalendarAccountController::class, 'updateCalendar'])->name('integrations.calendars.update');
    Route::post('integrations/accounts/{calendarAccount}/sync', [CalendarAccountController::class, 'sync'])->name('integrations.sync');
    Route::delete('integrations/accounts/{calendarAccount}', [CalendarAccountController::class, 'destroy'])->name('integrations.destroy');
});

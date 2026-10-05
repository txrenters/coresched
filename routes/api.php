<?php

use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\EventTypeController;
use App\Http\Controllers\Api\V1\HostController;
use App\Http\Controllers\Api\V1\IdentityController;
use App\Http\Controllers\Api\V1\OrganizationBookingController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationEventTypeController;
use App\Http\Controllers\Api\V1\SlotController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

/*
 * The machine to machine API, used by our other applications to offer booking
 * inside their own interfaces. Everything it exposes is already public on the
 * booking pages; the token is here so the endpoints cannot be scraped.
 */
Route::middleware(AuthenticateApiToken::class)->prefix('v1')->name('api.v1.')->group(function () {
    // Lets a caller discover the organization its key belongs to, so the
    // key is the only thing anybody has to configure.
    Route::get('me', [IdentityController::class, 'show'])->name('me');

    Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');

    // Every meeting the organization offers, wherever it is advertised.
    Route::get('organizations/{organization}/event-types', [OrganizationEventTypeController::class, 'index'])
        ->name('organizations.event-types.index');

    // Every meeting booked with the organization, for a team-wide calendar.
    Route::get('organizations/{organization}/bookings', [OrganizationBookingController::class, 'index'])
        ->name('organizations.bookings.index');

    // {page} is a booking slug or an organization id; the id survives a rename.
    Route::get('pages/{page}/event-types', [EventTypeController::class, 'index'])->name('event-types.index');
    Route::get('pages/{page}/event-types/{eventType}/slots', [SlotController::class, 'index'])->name('slots.index');

    // The one exception to "already public": who hosts a meeting and the
    // hours each keeps, so another application can show a host their own
    // hours and an administrator who covers which part of the week.
    Route::get('pages/{page}/event-types/{eventType}/hosts', [HostController::class, 'index'])->name('hosts.index');

    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::post('bookings', [BookingController::class, 'store'])
        ->middleware('throttle:30,1')->name('bookings.store');
});

<?php

use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\EventTypeController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\SlotController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

/*
 * The machine to machine API, used by our other applications to offer booking
 * inside their own interfaces. Everything it exposes is already public on the
 * booking pages; the token is here so the endpoints cannot be scraped.
 */
Route::middleware(AuthenticateApiToken::class)->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');

    // {page} is a booking slug or an organization id; the id survives a rename.
    Route::get('pages/{page}/event-types', [EventTypeController::class, 'index'])->name('event-types.index');
    Route::get('pages/{page}/event-types/{eventType}/slots', [SlotController::class, 'index'])->name('slots.index');

    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::post('bookings', [BookingController::class, 'store'])
        ->middleware('throttle:30,1')->name('bookings.store');
});

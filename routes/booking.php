<?php

use App\Http\Controllers\Booking\BookingPageController;
use App\Http\Controllers\Booking\PublicBookingController;
use Illuminate\Support\Facades\Route;

/*
 * The public, unauthenticated booking flow. These live under /book and
 * /booking so they can never collide with the team prefixed app routes.
 */
// Before book/{page}/{eventType}, which would otherwise read "once" as a page.
Route::get('book/once/{singleUseLink}', [BookingPageController::class, 'singleUseLink'])->name('book.once');
Route::post('book/once/{singleUseLink}', [PublicBookingController::class, 'storeFromLink'])
    ->middleware('throttle:20,1')
    ->name('book.once.store');

Route::get('book/{page}', [BookingPageController::class, 'show'])->name('book.page');
// One segment longer than book/{page}/{eventType}, so it can never collide with it.
Route::get('book/{page}/departments/{departmentPage}', [BookingPageController::class, 'departmentPage'])->name('book.departments');
Route::get('book/{page}/{eventType}', [BookingPageController::class, 'eventType'])->name('book.event-type');
Route::post('book/{page}/{eventType}', [PublicBookingController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('book.store');

Route::get('booking/{booking}', [PublicBookingController::class, 'show'])->name('booking.show');
Route::get('booking/{booking}/reschedule', [PublicBookingController::class, 'editSchedule'])->name('booking.reschedule');
Route::post('booking/{booking}/reschedule', [PublicBookingController::class, 'updateSchedule'])
    ->middleware('throttle:20,1')
    ->name('booking.reschedule.store');
Route::get('booking/{booking}/cancel', [PublicBookingController::class, 'confirmCancel'])->name('booking.cancel');
Route::delete('booking/{booking}', [PublicBookingController::class, 'cancel'])
    ->middleware('throttle:20,1')
    ->name('booking.cancel.store');

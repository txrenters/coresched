<?php

use App\Http\Controllers\AccountSetupController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\BookingQrCodeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Settings\OrganizationDirectoryController;
use App\Http\Controllers\Settings\UserDirectoryController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');
    });

/*
 * Guest-reachable on purpose: this is the "Join Now" link from the invitation
 * email, and the whole point is that the invitee has no account yet. The
 * 64-character invitation code is the credential, and it is single use -- the
 * controller accepts only a pending invitation and marks it accepted.
 *
 * The GET only shows a confirmation page; the POST joins. Mail scanners open
 * links on their own, so a GET must never create an account or sign anyone in.
 */
Route::get('invitations/{invitation}/join', [TeamInvitationController::class, 'showJoin'])
    ->name('invitations.join');
Route::post('invitations/{invitation}/join', [TeamInvitationController::class, 'join'])
    ->middleware('throttle:10,1')
    ->name('invitations.join.store');

/*
 * The "Set your password" link from the welcome email sent when an account is
 * created for someone. The token is the credential; see AccountSetupController.
 */
Route::middleware('guest')->group(function () {
    Route::get('account/setup/{token}', [AccountSetupController::class, 'show'])->name('account-setup.show');
    Route::post('account/setup', [AccountSetupController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('account-setup.store');
});

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');

    /*
     * Operator tools, kept out of the organization prefix: they span every
     * organization, and the manageUsers gate is what guards them.
     */
    Route::get('users', [UserDirectoryController::class, 'index'])->name('users.index');
    Route::post('users', [UserDirectoryController::class, 'store'])->name('users.store');
    Route::post('users/{user}/password-reset', [UserDirectoryController::class, 'sendPasswordReset'])
        ->name('users.password-reset');
    Route::patch('users/{user}/role', [UserDirectoryController::class, 'updateRole'])->name('users.role');

    Route::get('organizations', [OrganizationDirectoryController::class, 'index'])->name('organizations.index');
    Route::post('organizations/{team}/members', [OrganizationDirectoryController::class, 'storeMember'])
        ->name('organizations.members.store');
    Route::patch('organizations/{team}/members/{user}', [OrganizationDirectoryController::class, 'updateMember'])
        ->name('organizations.members.update');
    Route::delete('organizations/{team}/members/{user}', [OrganizationDirectoryController::class, 'destroyMember'])
        ->name('organizations.members.destroy');
    Route::delete('users/{user}/organizations/{team}', [UserDirectoryController::class, 'removeFromTeam'])
        ->name('users.organizations.destroy');
    Route::delete('users/{user}', [UserDirectoryController::class, 'destroy'])->name('users.destroy');

    Route::get('qr-code', BookingQrCodeController::class)->name('booking-qr-code');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('notifications/{notification}', [NotificationController::class, 'update'])->name('notifications.update');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/scheduling.php';
require __DIR__.'/booking.php';

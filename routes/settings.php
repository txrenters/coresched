<?php

use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamHolidayController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    // Issuing a key reaches every booking page, so it is super admin only.
    Route::middleware('can:manageApiKeys')->group(function () {
        Route::get('settings/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
        Route::post('settings/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
        Route::patch('settings/api-keys/{apiKey}', [ApiKeyController::class, 'update'])->name('api-keys.update');
        Route::delete('settings/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
        Route::delete('settings/api-keys/{apiKey}/forget', [ApiKeyController::class, 'forget'])->name('api-keys.forget');
    });

    Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/teams', [TeamController::class, 'store'])->name('teams.store');

    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::get('settings/teams/{team}', [TeamController::class, 'edit'])->name('teams.edit');
        Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
        Route::delete('settings/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');

        Route::get('settings/teams/{team}/advanced', [TeamHolidayController::class, 'edit'])->name('teams.advanced.edit');
        Route::patch('settings/teams/{team}/advanced', [TeamHolidayController::class, 'update'])->name('teams.advanced.update');
        Route::post('settings/teams/{team}/closures', [TeamHolidayController::class, 'storeClosure'])->name('teams.closures.store');
        Route::delete('settings/teams/{team}/closures/{closure}', [TeamHolidayController::class, 'destroyClosure'])->name('teams.closures.destroy');

        Route::get('settings/teams/{team}/members', [TeamMemberController::class, 'index'])->name('teams.members.index');
        Route::post('settings/teams/{team}/members', [TeamMemberController::class, 'store'])->name('teams.members.store');
        Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');

    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');

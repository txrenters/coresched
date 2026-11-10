<?php

use App\PortalSso\BackchannelLogoutController;
use App\PortalSso\EnsurePortalEnabled;
use App\PortalSso\PortalLoginController;
use Illuminate\Support\Facades\Route;

// Sign-in through the TR Staff Portal (only when PORTAL_ENABLED; see config/portal.php).
Route::middleware(EnsurePortalEnabled::class)->group(function () {
    Route::get('auth/portal/redirect', [PortalLoginController::class, 'redirect'])->name('portal.redirect');
    Route::get('auth/portal/callback', [PortalLoginController::class, 'callback'])
        ->middleware('throttle:60,1')
        ->name('portal.callback');
    Route::post('auth/portal/backchannel-logout', BackchannelLogoutController::class)
        ->middleware('throttle:120,1')
        ->name('portal.backchannel-logout');
});

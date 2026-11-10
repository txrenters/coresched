<?php

namespace App\Listeners;

use App\Support\Impersonation;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Ends any impersonation when somebody signs in.
 *
 * Auth::login() and session()->regenerate() keep the session's data, so a
 * super admin who signed in again (through the staff portal, say) while still
 * signed in as a member carried the impersonation marker into their own
 * account, and every route blocked while impersonating -- their own profile
 * among them -- answered 403. ImpersonationController writes the marker
 * after its own login() call, so starting an impersonation is unaffected.
 */
class EndImpersonationOnLogin
{
    public function __construct(protected Request $request)
    {
        //
    }

    /**
     * Forget the impersonation state the previous sign-in left behind.
     */
    public function handle(Login $event): void
    {
        if ($this->request->hasSession()) {
            $this->request->session()->forget([Impersonation::IMPERSONATOR_KEY, Impersonation::TEAM_KEY]);
        }
    }
}

<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DemoController extends Controller
{
    /**
     * Sign a visitor into the shared demo organization as its admin.
     *
     * There is no password to share: this route is the only way in, and
     * RestrictInDemo keeps the account's security settings locked so one
     * visitor cannot shut the next one out.
     */
    public function store(Request $request): RedirectResponse
    {
        $admin = Demo::admin();
        $team = $admin?->currentTeam;

        if ($team === null || ! Demo::isDemoTeam($team) || $team->isDisabled()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The demo is not available right now. Please try again later.')]);

            return back();
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('dashboard', ['current_team' => $team->slug]);
    }
}

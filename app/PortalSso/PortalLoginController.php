<?php

namespace App\PortalSso;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveOrganization;
use App\Http\Responses\Concerns\RedirectsToCurrentTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class PortalLoginController extends Controller
{
    use RedirectsToCurrentTeam;

    public function redirect(Request $request, PortalClient $portal): RedirectResponse
    {
        $state = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put(['portal.state' => $state, 'portal.verifier' => $verifier]);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return redirect()->away($portal->authorizationUrl($state, $challenge));
    }

    public function callback(Request $request, PortalClient $portal, PortalUserLinker $linker): RedirectResponse
    {
        // One-time use: pull state and verifier out of the session whatever happens next.
        $expectedState = $request->session()->pull('portal.state');
        $verifier = $request->session()->pull('portal.verifier');

        if (! is_string($expectedState) || ! is_string($verifier) || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return $this->refuse('This sign-in link has expired. Please sign in again.');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->refuse('The TR Staff Portal did not sign you in.');
        }

        try {
            $tokens = $portal->exchangeCode((string) $request->query('code'), $verifier);
            $claims = $portal->userinfo($tokens['access_token']);
        } catch (PortalAuthException|PortalUnavailableException $e) {
            Log::warning('Portal sign-in failed', ['error' => $e->getMessage()]);

            return $this->refuse('Sign-in through the TR Staff Portal did not complete. Please try again.');
        }

        if (! in_array(config('portal.app_slug'), (array) ($claims['apps'] ?? []), true)) {
            return $this->refuse("You don't have access to CoreSched. Ask IT to add it for you in the TR Staff Portal.");
        }

        try {
            $user = $linker->link($claims);
        } catch (PortalLinkConflict|PortalAccessRefused $e) {
            return $this->refuse($e->getMessage());
        }

        if (! $user->isSuperAdmin() && $user->hasOnlyDisabledOrganizations()) {
            return $this->refuse(EnsureActiveOrganization::MESSAGE);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put([
            'portal.sub' => (string) $claims['sub'],
            'portal.refresh_token' => $tokens['refresh_token'],
            'portal.checked_at' => now()->getTimestamp(),
        ]);

        return $this->landing($request);
    }

    /**
     * CoreSched's error page shows only a generic message per status, so a refusal goes
     * back to the sign-in page with the reason, like a suspended organization does.
     */
    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    /** Same destination as the password login (App\Http\Responses\LoginResponse). */
    private function landing(Request $request): RedirectResponse
    {
        return redirect()->intended($this->redirectPathForCurrentTeam($request, Fortify::redirects('login')));
    }
}

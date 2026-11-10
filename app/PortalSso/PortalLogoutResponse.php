<?php

namespace App\PortalSso;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff who signed in through the TR Staff Portal go back to its app list after signing
 * out; everyone else gets Fortify's usual response.
 */
class PortalLogoutResponse implements LogoutResponse
{
    public function toResponse($request): Response
    {
        if ($request->attributes->get('portal_session')) {
            return redirect()->away(config('portal.issuer').'/dashboard');
        }

        return $request->wantsJson() ? new JsonResponse('', 204) : redirect(Fortify::redirects('logout', '/'));
    }
}

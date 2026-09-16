<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountSetupController extends Controller
{
    /**
     * Show the page where a newly created account chooses its password.
     */
    public function show(Request $request, string $token): Response
    {
        return Inertia::render('auth/SetPassword', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
            'passwordRules' => PasswordRule::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Set the password from a welcome link.
     *
     * Checked against the "welcome" broker, whose tokens last longer than a
     * reset's. The password itself goes through the same action as a reset,
     * so the rules and the email verification it implies stay in one place.
     */
    public function store(Request $request, ResetUserPassword $resetUserPassword): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        $status = Password::broker('welcome')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, $resetUserPassword) {
                $resetUserPassword->reset($user, $request->only('password', 'password_confirmation'));

                $user->setRememberToken(Str::random(60));
                $user->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($status === Password::INVALID_TOKEN
                    ? 'This link has expired or has already been used. Use "Forgot password" on the sign-in page to get a new one.'
                    : $status),
            ]);
        }

        return to_route('login')->with('status', __('Your password is set. Sign in to get started.'));
    }
}

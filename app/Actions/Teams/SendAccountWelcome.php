<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\AccountCreated;
use Illuminate\Support\Facades\Password;

class SendAccountWelcome
{
    /**
     * Email someone whose account was just made for them a link to set a password.
     *
     * The token comes from the "welcome" password broker, which shares the
     * reset token table but lets the link live for days rather than the hour
     * a "forgot password" link gets. Nobody is waiting at a screen for this
     * one.
     */
    public function handle(User $user, ?Team $team = null): void
    {
        $token = Password::broker('welcome')->createToken($user);

        $user->notify(new AccountCreated($token, $team?->name));
    }
}

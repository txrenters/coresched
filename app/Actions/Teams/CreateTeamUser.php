<?php

namespace App\Actions\Teams;

use App\Actions\Scheduling\CreateDefaultAvailability;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateTeamUser
{
    public function __construct(
        private CreateDefaultAvailability $createDefaultAvailability,
        private SendAccountWelcome $sendAccountWelcome,
    ) {
        //
    }

    /**
     * Create a user directly inside an existing organization.
     *
     * This is the super admin's alternative to an invitation: the account
     * exists immediately, already a member, with no self-service signup. No
     * password is ever chosen for the user — the account is created with a
     * throwaway secret and they set their own from the welcome email, so a usable
     * password never passes through the creator's hands or the logs.
     *
     * Unlike CreateNewUser this does NOT create a personal organization. The
     * user is being placed into an existing one, and a second personal org
     * would just clutter their switcher.
     */
    public function handle(Team $team, string $name, string $email, TeamRole $role): User
    {
        $user = DB::transaction(function () use ($team, $name, $email, $role) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Str::password(32),
            ]);

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => $role,
            ]);

            $user->forceFill(['current_team_id' => $team->id])->save();

            $this->createDefaultAvailability->handle($user);

            return $user;
        });

        $this->sendAccountWelcome->handle($user, $team);

        return $user;
    }
}

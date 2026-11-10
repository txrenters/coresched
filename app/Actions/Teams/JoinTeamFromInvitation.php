<?php

namespace App\Actions\Teams;

use App\Actions\Scheduling\CreateDefaultAvailability;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JoinTeamFromInvitation
{
    public function __construct(
        private CreateDefaultAvailability $createDefaultAvailability,
    ) {
        //
    }

    /**
     * Create the invited account, place it in the organization, and mark the
     * invitation accepted.
     *
     * This is the one-click path behind the "Join Now" button: the invitee
     * never fills in a registration form, so the account is built from what the
     * invitation already knows. Like CreateTeamUser, and unlike CreateNewUser,
     * it creates NO personal organization — the user is being placed into an
     * existing one, and a second org would only clutter their switcher.
     *
     * No password is chosen here either. The account gets a throwaway secret
     * and the user sets their own from settings/security, so a usable password
     * never passes through a link or the logs.
     */
    public function handle(TeamInvitation $invitation): User
    {
        return DB::transaction(function () use ($invitation) {
            $team = $invitation->team;

            $user = User::create([
                'name' => Str::of($invitation->email)->before('@')->headline()->toString(),
                'email' => $invitation->email,
                'password' => Str::password(32),
            ]);

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => $invitation->role,
            ]);

            /*
             * forceFill, not the create() above: neither column is in the
             * model's Fillable list, so mass assignment drops them silently and
             * the account lands unverified with no organization -- which the
             * `verified` middleware then bounces straight back out.
             *
             * Following a link sent to the address is itself proof of control
             * of it, so a separate verification round-trip would only interrupt
             * the flow the invitee just completed.
             */
            $user->forceFill([
                'current_team_id' => $team->id,
                'email_verified_at' => now(),
            ])->save();

            $invitation->update(['accepted_at' => now()]);

            $this->createDefaultAvailability->handle($user);

            return $user;
        });
    }
}

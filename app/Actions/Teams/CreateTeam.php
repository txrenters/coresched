<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    /**
     * Create a new team and add the user as its admin.
     *
     * Except a super admin: they reach every organization without joining
     * any (see .ai/rules/concerns.md), so an organization they create starts
     * with no members and they add its first admin themselves. Joining would
     * put them on its roster, in its "Sign in as" list and in its round-robin
     * host pools.
     */
    public function handle(User $user, string $name): Team
    {
        return DB::transaction(function () use ($user, $name) {
            $team = Team::create(['name' => $name]);

            if ($user->isSuperAdmin()) {
                return $team;
            }

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Admin,
            ]);

            $user->switchTeam($team);

            return $team;
        });
    }
}

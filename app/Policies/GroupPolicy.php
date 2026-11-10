<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Group;
use App\Models\Team;
use App\Models\User;

class GroupPolicy
{
    /**
     * Determine whether the user can open the organization's Teams page.
     *
     * Admin only: a member never sees how the organization is split into teams.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageGroups);
    }

    /**
     * Determine whether the user can view the model.
     *
     * Anyone in the team may see groups, since they pick one when building an
     * event type; only admins may change them.
     */
    public function view(User $user, Group $group): bool
    {
        return $user->belongsToTeam($group->team);
    }

    /**
     * Determine whether the user can create groups in the given team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageGroups);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Group $group): bool
    {
        return $user->hasTeamPermission($group->team, TeamPermission::ManageGroups);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Group $group): bool
    {
        return $this->update($user, $group);
    }
}

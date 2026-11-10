<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Automation;
use App\Models\Team;
use App\Models\User;

class AutomationPolicy
{
    /**
     * Determine whether the user can open the organization's Workflows page.
     *
     * Workflows are admin only: a member can neither see nor change them.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageAutomations);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Automation $automation): bool
    {
        return $user->hasTeamPermission($automation->team, TeamPermission::ManageAutomations);
    }

    /**
     * Determine whether the user can create workflows in the given team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageAutomations);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Automation $automation): bool
    {
        return $user->hasTeamPermission($automation->team, TeamPermission::ManageAutomations);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Automation $automation): bool
    {
        return $this->update($user, $automation);
    }
}

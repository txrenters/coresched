<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\DepartmentPage;
use App\Models\Team;
use App\Models\User;

class DepartmentPagePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * Anyone in the team may see its pages; only those who manage its teams
     * may change them.
     */
    public function view(User $user, DepartmentPage $page): bool
    {
        return $user->belongsToTeam($page->team);
    }

    /**
     * Determine whether the user can create pages in the given team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageGroups);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DepartmentPage $page): bool
    {
        return $user->hasTeamPermission($page->team, TeamPermission::ManageGroups);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DepartmentPage $page): bool
    {
        return $this->update($user, $page);
    }
}

<?php

namespace App\Concerns;

use App\Data\TeamPermissions;
use App\Data\UserTeam;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

trait HasTeams
{
    /**
     * Get all of the teams the user belongs to.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members', 'user_id', 'team_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all of the memberships for the user.
     *
     * @return HasMany<Membership, $this>
     */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * Get the user's current team.
     *
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * Switch to the given team.
     */
    public function switchTeam(Team $team): bool
    {
        // A super admin can stand in any organization without joining it.
        if (! $this->belongsToTeam($team) && ! $this->isSuperAdmin()) {
            return false;
        }

        $this->update(['current_team_id' => $team->id]);
        $this->setRelation('currentTeam', $team);

        URL::defaults(['current_team' => $team->slug]);

        return true;
    }

    /**
     * Determine if the user belongs to the given team.
     */
    public function belongsToTeam(Team $team): bool
    {
        return $this->teams()->where('teams.id', $team->id)->exists();
    }

    /**
     * Determine if the given team is the user's current team.
     */
    public function isCurrentTeam(Team $team): bool
    {
        return $this->current_team_id === $team->id;
    }

    /**
     * Get the user's role on the given team.
     */
    public function teamRole(Team $team): ?TeamRole
    {
        return $this->teamMemberships()
            ->where('team_id', $team->id)
            ->first()
            ?->role;
    }

    /**
     * Get the user's teams as a collection of UserTeam objects.
     *
     * @return Collection<int, UserTeam>
     */
    public function toUserTeams(bool $includeCurrent = false): Collection
    {
        // A super admin can switch into any organization, so list them all.
        $teams = $this->isSuperAdmin()
            ? Team::query()->orderBy('name')->get()
            // A disabled organization is out of reach, so it is not offered.
            : $this->teams()->whereNull('teams.disabled_at')->get();

        return $teams
            ->map(fn (Team $team) => ! $includeCurrent && $this->isCurrentTeam($team) ? null : $this->toUserTeam($team))
            ->filter()
            ->values();
    }

    /**
     * Get the user's team as a UserTeam object.
     */
    public function toUserTeam(Team $team): UserTeam
    {
        $role = $this->teamRole($team);

        return new UserTeam(
            id: $team->id,
            name: $team->name,
            slug: $team->slug,
            role: $role?->value,
            roleLabel: $role?->label(),
            isCurrent: $this->isCurrentTeam($team),
            // The switcher shows the organization's own logo, so it travels
            // with every UserTeam rather than only the settings payload.
            logoUrl: $team->logoUrl(),
        );
    }

    /**
     * Get the standard permissions for a team as a TeamPermissions object.
     */
    public function toTeamPermissions(Team $team): TeamPermissions
    {
        // A super admin can act in any organization, member or not.
        if ($this->isSuperAdmin()) {
            return new TeamPermissions(
                canUpdateTeam: true,
                canDeleteTeam: true,
                canAddMember: true,
                canUpdateMember: true,
                canRemoveMember: true,
                canCreateInvitation: true,
                canCancelInvitation: true,
                canManageApiKeys: true,
            );
        }

        $role = $this->teamRole($team);

        return new TeamPermissions(
            canUpdateTeam: $role?->hasPermission(TeamPermission::UpdateTeam) ?? false,
            canDeleteTeam: $role?->hasPermission(TeamPermission::DeleteTeam) ?? false,
            canAddMember: $role?->hasPermission(TeamPermission::AddMember) ?? false,
            canUpdateMember: $role?->hasPermission(TeamPermission::UpdateMember) ?? false,
            canRemoveMember: $role?->hasPermission(TeamPermission::RemoveMember) ?? false,
            canCreateInvitation: $role?->hasPermission(TeamPermission::CreateInvitation) ?? false,
            canCancelInvitation: $role?->hasPermission(TeamPermission::CancelInvitation) ?? false,
            canManageApiKeys: $role?->hasPermission(TeamPermission::ManageApiKeys) ?? false,
        );
    }

    /**
     * Determine whether every organization this user belongs to is disabled.
     */
    public function hasOnlyDisabledOrganizations(): bool
    {
        // Somebody in no organization at all (a fresh account, a super admin)
        // is not shut out by this; only having every one disabled is.
        return ! $this->isSuperAdmin()
            && $this->teams()->exists()
            && ! $this->teams()->whereNull('teams.disabled_at')->exists();
    }

    /**
     * Get the first active organization to fall back to, by name.
     */
    public function fallbackTeam(?Team $excluding = null): ?Team
    {
        return $this->teams()
            ->whereNull('teams.disabled_at')
            ->when($excluding, fn ($query) => $query->where('teams.id', '!=', $excluding->id))
            ->orderByRaw('LOWER(teams.name)')
            ->first();
    }

    /**
     * Determine if the user has the given permission on the team.
     *
     * A super admin holds a role in no organization, so the role lookup below
     * would deny them everywhere. Bypassing here rather than at each call site
     * covers the direct callers that never reach a policy — canAssignOwner on
     * the event type form and the meeting list's ManageTeamBookings check.
     */
    public function hasTeamPermission(Team $team, TeamPermission $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->teamRole($team)?->hasPermission($permission) ?? false;
    }
}

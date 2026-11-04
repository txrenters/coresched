---
paths:
  - app/Concerns/HasTeams.php
---

# Concerns

## Super admins bypass membership rather than joining every organization
`users.is_super_admin` grants cross-organization access. A super admin is deliberately NOT a member of anything — team_members stays clean and org rosters never show them. Four places implement the bypass and all four are load-bearing:

- EnsureTeamMembership: skips both the membership check and the minimum-role check.
- HasTeams::switchTeam(): allows switching into an organization they do not belong to.
- HasTeams::toUserTeams(): returns every Team so the switcher lists them all.
- HasTeams::toTeamPermissions(): returns all-true, because teamRole() is null for them and would otherwise deny everything.

Consequence to keep in mind: teamRole() and ownsTeam() still return null/false for a super admin. Any NEW authorization check must ask isSuperAdmin() explicitly — do not assume a role lookup covers them.

The log viewer is super-admin-only in every environment (it used to be a non-production gate). It 404s rather than 403s so the screen's existence stays quiet. Logs carry tokens and PII, so this account is the most sensitive in the app.

Create or promote with `php artisan user:super-admin {email}` (`--revoke` to remove). It verifies the address and assigns a current organization, since a super admin joins none and would otherwise land nowhere after signing in.

## hasTeamPermission() is now the fifth super admin bypass
The "four places implement the bypass" list above is now five. HasTeams::hasTeamPermission() returns true for a super admin before the role lookup, because teamRole() is null for them and would otherwise deny.

This matters beyond policies: EventTypeController::formOptions (canAssignOwner) and MeetingController call hasTeamPermission() DIRECTLY and never reach a policy, so the Gate::before grant in AppServiceProvider cannot cover them. Symptom when it was missing: a super admin creating an event type got the host field locked to themselves and the save failed with "The selected user id is invalid", because SaveEventTypeRequest checks Rule::exists('team_members', ...) and a super admin is deliberately not a member.

Related: CreateEventTypePanel.vue defaults the host to the first real team member rather than the acting user, for the same reason. Do not default a new event type's user_id/host_ids to currentUser without checking they are in teamMembers.

## Disabled organizations (teams.disabled_at) are enforced in several places, all load-bearing
A super admin disables an organization from the Organizations page (OrganizationConsoleController::updateStatus). Enforcement, each covering a path the others miss:
- EnsureActiveOrganization (web group): signs out anyone whose EVERY organization is disabled, even mid-session and after a passkey login; moves someone onto an active organization when their current one is disabled. Fortify::authenticateUsing refuses the password login up front with the same message.
- HasTeams::toUserTeams / fallbackTeam: a disabled organization is never offered or fallen back to (super admins still see all).
- EnsureTeamMembership: 403 for members of a disabled organization; super admins pass so they can re-enable it.
- EventType::scopeBookable: a disabled organization's event types disappear from every public page, including members' personal pages and the API listing.
- CreateBooking: throws SlotUnavailableException::organizationDisabled — the backstop for every new booking and every reschedule, whatever the entry point.
- AuthenticateApiToken: an organization's API keys are refused. SendBookingReminders drops, and RunAutomations marks as failed, anything due for a disabled organization, so re-enabling never fires stale messages.
- ImpersonationController refuses to sign in as its members. disabled_reason is #[Hidden] on Team (see models.md).
Someone in no organization at all is NOT shut out by this; only having every one disabled is.

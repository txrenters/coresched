---
paths:
  - 'app/Policies/**'
---

# Policies

## Super admins are granted every ability by Gate::before, not per policy
A super admin belongs to no organization, so teamRole() is null and every role based permission lookup denies them. EnsureTeamMembership lets them REACH any org, but before 2026-08 the policies still returned 403 the moment they tried to act — only TeamPolicy::createMember worked, because it asks isSuperAdmin() explicitly.

AppServiceProvider::grantSuperAdminsEveryAbility() now registers Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null). Do not copy the old per-policy isSuperAdmin() workaround into new policies; the central grant covers them.

The closure MUST return null, not false, for non super admins. Returning false short circuits Gate and denies the entire application. There is a regression test pinning this in tests/Feature/Settings/SuperAdminTest.php.

## Teams, Workflows and Activity are admin only
Members cannot open the Teams (groups), Workflows (automations) or Activity pages, not even a filtered view of their own activity. GroupPolicy::viewAny uses ManageGroups. AutomationPolicy uses TeamPermission::ManageAutomations for view, create and update (it used to use ManageEventTypes, which members hold). Activity is gated by the `viewActivity` Gate (TeamPermission::ViewActivity) in AppServiceProvider. The sidebar and the Dashboard quick action check the shared props canManageGroups, canManageWorkflows and canViewActivity from HandleInertiaRequests. Admins get new TeamPermission cases automatically, so a new admin-only area only needs a new case.

---
paths:
  - app/Http/Controllers/Settings/OrganizationDirectoryController.php
---

# Settings

## Organization directory: admins see and manage only their own
The `viewOrganizations` gate (AppServiceProvider) opens /organizations to super admins and to anyone who is an Admin of at least one organization. index() narrows non-super-admins to the orgs they administer. Member actions authorize per organization through TeamPolicy (addMember / createMember / updateMember / removeMember), never through the listing gate, so an admin of org A gets 403 on org B.
Adding an EXISTING account straight into an organization stays super-admin only (explicit isSuperAdmin() check): an org admin must invite that person from the org's settings, so nobody lands in an organization they did not agree to join. Admins may still create brand-new accounts, matching TeamMemberController::store.

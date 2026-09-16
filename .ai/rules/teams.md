---
paths:
  - 'app/Http/Controllers/Teams/**'
---

# Teams

## Organization logo uploads: no SVG, and absent fields never clear stored branding
Logos live on the `public` disk under `team-logos/` and are served from the app's own origin, so SVG is deliberately rejected in SaveTeamRequest — an SVG can carry script and would be same-origin. PNG/JPG/WebP only, 2 MB and 2000x2000 max. `php artisan storage:link` must exist in any new environment or logos 404.

TeamController::resolveLogo() returns an empty array when the request says nothing about the logo, so a save that only changes the name never wipes it. `remove_logo=true` clears it; a new upload is stored first and the old file deleted only after, so a failed write cannot lose both. The same "only apply what was sent" rule covers welcome_message / website_url / timezone via $request->has().

The form posts multipart with `_method: 'patch'` because Laravel cannot read multipart bodies on a real PATCH.

Team::logoUrl() is the only place that builds the public URL — do not construct the path in a controller or component. It is null-safe and returns null when nothing is uploaded.

## Super admins create users directly; everyone else invites
POST settings/teams/{team}/members (teams.members.store) creates a user account outright inside an organization. It is gated by TeamPolicy::createMember, which asks isSuperAdmin() EXPLICITLY — a permission lookup would deny super admins, since teamRole() is null for them (see .ai/rules/concerns.md).

Deliberately narrower than inviteMember: creating an account skips the invitee's consent and the email round-trip. Org owners and admins keep the invitation flow; only super admins create.

App\Actions\Teams\CreateTeamUser does the work and differs from CreateNewUser in two ways worth knowing:
- It does NOT create a personal organization. The user is being placed into an existing one, and a second personal org would only clutter their switcher. There is a test pinning this.
- No password is ever chosen for the user. The account is created with Str::password(32) and SendAccountWelcome emails an AccountCreated "Set your password" link, so a usable password never passes through the creator's hands or the logs. Do not add an "initial password" field. Do not go back to Password::sendResetLink(): that email tells a brand-new person they asked for a reset and to ignore it otherwise, and its link dies after 60 minutes. The welcome token comes from the separate `welcome` broker in config/auth.php (7 days, same token table) and is redeemed only by AccountSetupController, because Fortify's reset route checks the 60-minute `users` broker. The directory's explicit "Send password reset" button still sends the real reset email.

It still calls CreateDefaultAvailability and ApplyDefaultHolidays, so a created user lands with working hours rather than an empty calendar — same as a self-registered one.

The UI is CreateMemberModal.vue on the team edit page, shown on the shared isSuperAdmin Inertia prop (HandleInertiaRequests), not on the TeamPermissions DTO.

## Invitation codes are credentials: GET never joins, and only managers see codes
GET invitations/{code}/join (showJoin) only renders auth/JoinInvitation; POST invitations.join.store creates the account and signs in. Mail scanners (Safe Links) fetch emailed links, so a GET that joined burned invitations and handed scanners a session. Never make the GET act again. TeamController::edit only sends `invitations` (which carry the code) to users who can inviteMember or cancelInvitation — a plain member holding a code could take an admin invitation via the join link.

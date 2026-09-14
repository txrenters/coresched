---
paths:
  - 'app/Actions/Fortify/**'
---

# Fortify

## Registration is invitation-only — the gate lives in CreateNewUser
This is an internal system: there is no public signup. Fortify's Features::registration() is still ON, because the invite flow needs it — the invitation email links to LOGIN, and the invitee follows the "Create one" link there to reach /register with ?invitation=<code>. Turning the feature off would strand every invited person.

Two layers, and only one of them is the boundary:
- CreateNewUser::create() runs an after() validator requiring a pending, unexpired TeamInvitation whose email matches (case-insensitively) the submitted address. THIS is the real gate — every Fortify registration path goes through it.
- FortifyServiceProvider::registerView() redirects to login when there is no valid ?invitation= code. Cosmetic only: it keeps the form out of sight, it does not secure anything.

Use TeamInvitation::pending() for the "not accepted, not expired" query — it is the query-side twin of isPending() and both must stay in agreement.

Consequence for tests: any test that POSTs to register.store must first call the global invitationFor($email) helper in tests/Pest.php. Several scheduling tests that assert new-user defaults already do.

Super admins are created out-of-band with `php artisan user:super-admin {email}`, which bypasses all of this.

## Registration needs the invitation code, not just a matching email
Supersedes the "email match is the real gate" note above. As of 2026-09-14 CreateNewUser::invitationFor() requires the submitted `invitation` code AND a case-insensitive email match on a pending TeamInvitation. Email alone let anyone who knew an invited address register as that person with the invited role. Register.vue posts the code as a hidden `invitation` field. Tests posting to register.store must send 'invitation' => invitationFor($email)->code.

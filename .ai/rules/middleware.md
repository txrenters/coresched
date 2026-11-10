---
paths:
  - app/Http/Middleware/RestrictWhileImpersonating.php
---

# Middleware

## Profile saves are allowed while impersonating, except an email change
profile.update is deliberately NOT in BLOCKED_ROUTES. A super admin signed in as a member may change that member's name, photo and phone, so they can fix a mobile number for them. handle() still returns 403 when the submitted email differs from the account's (compared exactly; ProfileController also drops `email` while impersonating), because password resets go to that address. Password, 2FA, passkeys, API keys, profile.destroy and teams.leave stay fully blocked. Impersonation::active() ignores a marker that names the signed-in user, and EndImpersonationOnLogin clears the marker on every sign-in.

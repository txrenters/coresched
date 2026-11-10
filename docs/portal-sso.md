# Signing in through the TR Staff Portal

When `PORTAL_ENABLED=true`, TexasRenters staff sign in to CoreSched through the TR Staff Portal (OpenID Connect). **Staff** means an account with a company email: `@texasrenters.com` or `@texra.net`. Members of customer organizations are not affected: they keep their email and password, and invitations and sign-up work as before.

## Settings (`.env`)

```
PORTAL_ENABLED=true
PORTAL_ISSUER=https://login.texasrenters.com    # no trailing slash
PORTAL_CLIENT_ID=<from the portal: Apps → CoreSched → Keys>
PORTAL_CLIENT_SECRET=<shown once when the app was registered>
PORTAL_APP_SLUG=coresched                       # the app's Short ID in the portal
PORTAL_RECHECK_MINUTES=5
PORTAL_ALLOW_PASSWORD_LOGIN=true                # keep on: staff can still use their password
```

In the portal, register CoreSched with:

- **App address:** `https://coresched.com`
- **Callback:** `https://coresched.com/auth/portal/callback`
- **Logout address:** `https://coresched.com/auth/portal/backchannel-logout`

## What happens

- **The login page** shows "TexasRenters staff: sign in with TR Staff Portal" above the password form. It is hidden while someone is accepting an organization invitation.
- **Linking accounts:** the first time, your portal account is linked to your existing CoreSched account by email. It must be a verified company address, and exactly one CoreSched account may have it. Your organizations, roles and settings stay as they were.
- **No automatic accounts** (owner decision): if you don't have a CoreSched account yet, you're sent back to the login page with "Ask an admin to invite you". An admin invites you into your organization as today.
- **Suspended organizations:** if all your organizations are suspended, the portal sign-in is refused with the usual message. Super admins land on the admin console, as with the password login.
- **Every 5 minutes** CoreSched checks with the portal that you still have access.
  - Deactivated, removed from CoreSched, or past the portal's 12-hour sign-in window: you're signed out.
  - If the portal is briefly unreachable: you are **not** signed out.
- **Signing out:**
  - Deactivating someone in the portal, or "Sign out everywhere", ends their CoreSched sessions immediately (back-channel logout).
  - Staff who came in through the portal go back to the portal's app list when they sign out.
- **Password login** stays on for staff while `PORTAL_ALLOW_PASSWORD_LOGIN=true` (the setting we run with), so nobody is locked out. If it is ever set to `false`, staff password sign-in is refused with the usual "credentials do not match" message, and staff sessions that didn't come from the portal (password or passkey) are ended. Customer organizations always keep password and passkey sign-in.

## Testing locally

1. Run the portal at `http://localhost:8000`.
2. Run CoreSched at `http://127.0.0.1:8001` with `php artisan serve --port=8001`. Use 127.0.0.1, not localhost, so the two apps don't overwrite each other's cookies.
3. In the portal, register an app with:
   - **App address:** `http://127.0.0.1:8001`
   - **Callback:** `http://127.0.0.1:8001/auth/portal/callback`
   - **Logout address:** `http://127.0.0.1:8001/auth/portal/backchannel-logout`
4. Assign it to yourself.
5. In CoreSched's `.env`, set `APP_URL=http://127.0.0.1:8001`, `PORTAL_ISSUER=http://localhost:8000` and the keys.

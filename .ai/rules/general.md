---
paths:
  - docker-compose.yml
---

# General

## Queue split: only the calendar sweep runs on the calendars queue
The worker runs --queue=default,calendars. SyncCalendarBusyBlocks and SyncLeavePeriods go on `calendars` so the hourly per-account sweep can't delay emails. SyncBookingToCalendars/RemoveBookingFromCalendars MUST stay on `default`, dispatched before the booking notifications: SyncBookingToCalendars writes the Teams/Meet meeting_url the confirmation email reads, so moving it to a lower-priority queue sends emails without the join link.

## The public hostname is scheduling.texasrenters.com; bookings.* is redirect-only
Migrated 2026-09-22 from bookings.texasrenters.com. The old vhost on the VPS is now a 301-only server block (no proxy_pass) because booking links in already-sent confirmation emails and calendar invites carry it.

Never serve the app on both names. APP_URL is baked into the JS bundle at build time (ARG APP_URL in the Dockerfile, fed from APP_HOST in the server's compose .env), so a second hostname hands the browser a bundle whose URLs all point at the other origin and every form becomes a cross-origin post.

To change the hostname again, the order is: edit APP_HOST in /opt/tx-bookings/.env AND APP_URL in .env.docker (both, they must match), then certbot + nginx, then bin/deploy.sh to REBUILD. Editing the env files without a rebuild leaves the old host in the bundle.

Side effects of any such change: everyone is signed out once (SESSION_DOMAIN=null means host-only cookies), and every registered passkey is invalidated because config/fortify.php derives the WebAuthn relying_party_id from APP_URL's host. There were 0 passkeys at the time of this migration; check again before the next one.

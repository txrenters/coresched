---
paths:
  - 'app/Notifications/Bookings/**'
---

# Notifications Bookings

## Every booking email goes through BookingMailPresenter::withSender
withSender() sets the From display name to the organization's name (address stays mail.from.address, the mailbox Graph may send as), sets Reply-To (host <-> invitee; the shared From mailbox is unread) and puts $organization (name, booking page url) into viewData. The published mail views (resources/views/vendor/mail/html/message + header, text/message, vendor/notifications/email) read $organization for the header name, footer and sign-off, falling back to app.name for account emails. Anonymous mail components do not inherit parent variables, so a custom markdown view must pass it explicitly: @component('mail::message', ['organization' => $organization ?? null]). Canceled and Declined once skipped reply-to entirely; any new booking notification must call withSender (withFooter calls it).

## Booking emails carry no logo
Removed 2026-10-06 at the owner's request: the header shows the organization's name as text, never its logo image, even when the team has one. The logo stays on the booking page.

---
paths:
  - app/Models/Booking.php
  - 'app/Services/Webhooks/**'
  - app/Jobs/SendBookingWebhook.php
  - app/Actions/Bookings/NotifyBookingParties.php
---

# Webhooks

## Who hears about a booking, from one place
A booking with a `reference` was opened from a caller's link, e.g. TEXRA's (the token arrives as `ref`, is validated in StoreBookingRequest, stored verbatim, and copied onto the replacement by RescheduleBooking).

Receivers are API keys of the booking's organization that name a `webhook_url`, each signing with its own encrypted `webhook_secret`. A key's `webhook_scope` (WebhookScope) is `referenced` (default: only bookings with a reference) or `all` (every booking the organization takes, reference or not). `services.texra.webhook_url` is the legacy fallback for referenced bookings only, used when no key of the organization has scope `referenced`; it never runs alongside one, or a receiver moved from the env to its key would get every event twice under two secrets. A booking no receiver covers bumps no sequence and dispatches nothing. `NotifyBookingParties::receivers()` decides; `SendBookingWebhook` re-checks the key (revoked, cleared, narrowed scope) at send time.

The single dispatch point is `NotifyBookingParties::send()`, mapping each notification class to an event name (`booking.confirmed`, `booking.requested`, `booking.declined`, `booking.canceled`, `booking.rescheduled`). Do not dispatch `SendBookingWebhook` from an action directly: a change that reaches people must reach the receivers, and a change that deliberately reaches nobody (the Calendly importer writes around this class, see calendly.md) must reach neither. A pending replacement of a reschedule therefore goes out as `requested`, carrying `rescheduled_from_uid`.

## Every other change goes out as booking.updated, from the model
`Booking::booted()` hands every `updated` event to `NotifyBookingParties::changed()`, which reports the changed columns as `booking.updated` (payload `changes`). Columns a lifecycle event already covers (`status`, the `canceled_*` trio, `cancellation_reason`, `rescheduled_from_id`) and bookkeeping (`webhook_sequence`, timestamps) are dropped, so a cancel is not sent twice. It is on the model on purpose: a writer added later (no-show, host notes, calendar sync's meeting link) is reported without anyone remembering to. Bump `webhook_sequence` inside `Booking::withoutEvents()`: a plain `increment()` fires `updated` again with the previous save's changes and loops forever.

## Delivery id at creation, signature at send time, sequence before dispatch
`SendBookingWebhook` fixes its delivery id in the constructor so every retry presents the same id, and signs `"{timestamp}.{body}"` in `handle()` so a retry an hour later still verifies. `report()` increments `bookings.webhook_sequence` before dispatching and passes the new value with the job, so the receiver can order events per booking whatever the wire does. A 4xx fails the job at once (`$this->fail()`); a 5xx or connection failure throws and retries on the backoff. Nothing secret goes in the payload: the reference is the caller's own token, and the signing secret never leaves the header.

Tests run the job's `handle()` directly (`phpunit.xml` pins the sync queue, so `dispatch()` would run it inline and a retry would surface as a thrown exception) and end every `Http::fake` with a `'*'` catch-all.

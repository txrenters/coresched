---
paths:
  - app/Models/Booking.php
  - 'app/Services/Webhooks/**'
  - app/Jobs/SendBookingWebhook.php
  - app/Actions/Bookings/NotifyBookingParties.php
---

# Webhooks

## Only referenced bookings are reported, from one place
A booking with a `reference` was opened from a TEXRA link (the token arrives as `ref`, is validated in StoreBookingRequest, stored verbatim, and copied onto the replacement by RescheduleBooking). Those bookings, and only those, are reported to `services.texra.webhook_url`. Every other booking the company makes stays inside this app.

The single dispatch point is `NotifyBookingParties::send()`, mapping each notification class to an event name (`booking.confirmed`, `booking.requested`, `booking.declined`, `booking.canceled`, `booking.rescheduled`). Do not dispatch `SendBookingWebhook` from an action directly: a change that reaches people must reach TEXRA, and a change that deliberately reaches nobody (the Calendly importer writes around this class, see calendly.md) must reach neither. A pending replacement of a reschedule therefore goes out as `requested`, carrying `rescheduled_from_uid`.

## Every other change goes out as booking.updated, from the model
`Booking::booted()` hands every `updated` event to `NotifyBookingParties::changed()`, which reports the changed columns as `booking.updated` (payload `changes`). Columns a lifecycle event already covers (`status`, the `canceled_*` trio, `cancellation_reason`, `rescheduled_from_id`) and bookkeeping (`webhook_sequence`, timestamps) are dropped, so a cancel is not sent twice. It is on the model on purpose: a writer added later (no-show, host notes, calendar sync's meeting link) is reported without anyone remembering to. Bump `webhook_sequence` inside `Booking::withoutEvents()`: a plain `increment()` fires `updated` again with the previous save's changes and loops forever.

## Delivery id at creation, signature at send time, sequence before dispatch
`SendBookingWebhook` fixes its delivery id in the constructor so every retry presents the same id, and signs `"{timestamp}.{body}"` in `handle()` so a retry an hour later still verifies. `report()` increments `bookings.webhook_sequence` before dispatching and passes the new value with the job, so the receiver can order events per booking whatever the wire does. A 4xx fails the job at once (`$this->fail()`); a 5xx or connection failure throws and retries on the backoff. Nothing secret goes in the payload: the reference is the caller's own token, and the signing secret never leaves the header.

Tests run the job's `handle()` directly (`phpunit.xml` pins the sync queue, so `dispatch()` would run it inline and a retry would surface as a thrown exception) and end every `Http::fake` with a `'*'` catch-all.

---
paths:
  - app/Actions/Bookings/AssignHosts.php
  - app/Actions/Bookings/ScheduleAutomations.php
  - 'app/Actions/Bookings/**'
---

# Bookings

## Host assignment is priority-first, and sortBy([...]) is the wrong tool here
Pooled events go to the highest-priority host who is free. The order is: pivot priority (lower number wins), then fewest upcoming bookings, then id. Hosts sharing a priority still load-balance, so a tier of equals spreads evenly and setting no priorities behaves like plain round robin. A busy host simply is not in $availableHostIds, so the next priority down picks the slot up.

Do NOT rewrite this as `->sortBy([fn($host) => ..., ...])`. Laravel's sortByMany treats a callable in that array as a two-argument COMPARATOR ($a, $b), not a key extractor, so the chained-closure form silently compares the wrong values — that was a live bug here before 2026-08. Use the single `->sort(fn ($a, $b) => [...] <=> [...])` comparator.

Priority is read off whichever pivot brought the host into the pool: event_type_hosts for pinned hosts, group_members for a group. Both relations already orderByPivot('priority'). SaveEventType::syncHosts writes priority as the position in the submitted host_ids array, so the order of that array IS the priority order — the UI (HostPriorityList.vue) sends it top-to-bottom.

## Workflows: a reschedule replaces the booking row, so sent runs live on the old one
An Automation (a "workflow" in the UI) queues one automation_run per booking. RescheduleBooking does not move the booking — it creates a replacement and points rescheduled_from_id at the original — so anything already sent is recorded against the booking the replacement supersedes. ScheduleAutomations::sentAutomationIds() walks that chain; drop the walk and every move re-announces the meeting.

No rows in automation_event_type means "every event type in the organization", including ones added later. Do not introduce a magic all-event-types value.

CreateBooking and ApproveBooking only queue runs for a CONFIRMED booking, like reminders; CancelBooking, DeclineBooking and RescheduleBooking delete the pending ones.

## Choosing a host and no-shows ride on the existing booking flow
A chosen host is passed to CreateBooking/RescheduleBooking as `host:`; the slot must list that host in its hostIds, otherwise SlotUnavailableException. Who may choose is decided in one place: EventType::canBeBookedOnBehalfBy (new bookings) and Booking::chosenHostFor (reschedules, needs the cancel ability). Only EventType::assignableHosts() may be chosen: pooled and not collective.
With nobody chosen, a reschedule stays with the host who has the meeting (Booking::keptHost): the picker offers only their times and the replacement is theirs, never re-run through round robin. TEXRA's lead is held by that agent, so a renter's move must not hand the call to someone else; only an organizer choosing a host hands it on.
RescheduleBooking sends BookingCanceled to hosts dropped from the replacement, because BookingRescheduled only reaches the new booking's hosts.
A no-show keeps the booking Confirmed and sets no_show_at. Automation::sendAtFor queues NoShow runs only once no_show_at is set, and skips AfterEnd runs while it is set. RecordNoShow re-runs ScheduleAutomations both when marking and when undoing.

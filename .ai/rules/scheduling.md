---
paths:
  - 'app/Services/Scheduling/**'
---

# Scheduling

## Availability engine: how slots are computed
Slot generation is layered and each layer has one job:
- WorkingHoursCalculator expands a schedule's weekly rules + date overrides into UTC windows. Rules are wall-clock times in the schedule's own timezone, so always expand per local day, then convert to UTC. An override for a date REPLACES that day's rules.
- BusyTimeRepository merges internal bookings (padded by the event type's buffers) with synced busy_blocks. Only calendars with checks_conflicts = true block time.
- AvailabilityEngine subtracts busy from working windows, then steps by slot interval.

Kind matters: round robin/one-on-one take the UNION of hosts' free time (slot keeps the list of hosts free for it); collective takes the INTERSECTION. Seats only gate group events — for every other kind a booked host is already removed from the slot's host list, so do not also filter on per-start booking counts (that bug hid round-robin slots another host could still take).

Always store timestamps in UTC; convert at the edges only.

## Public page listings split by event type kind, both ways
BookingPageResolver::eventTypes() filters by kind: an organization page lists only EventTypeKind::shared() (round robin, collective). A person's page lists their own other kinds, plus shared events by host pool (see below). user_id is the creator, not the host pool, so a round robin its creator handed to another host must not show on the creator's own /book/{slug} page.

Listing is not resolution: eventType() applies no kind filter, so every link already handed out still opens on either page.

## A person's page also lists the shared events they host
Supersedes "person's page lists only the other kinds". BookingPageResolver::eventTypes() for a User now returns their own non-shared kinds PLUS shared (round robin, collective) event types in their organizations whose hostPool() contains them. Being the creator (user_id) still does not list a shared event — only being in its host pool does. Shared events only resolve under the organization slug, so BookingPageController::toListing() links their cards via the team slug, and every card carries kind/kindLabel/isShared for the type tag.

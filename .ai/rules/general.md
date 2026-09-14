---
paths:
  - docker-compose.yml
---

# General

## Queue split: only the calendar sweep runs on the calendars queue
The worker runs --queue=default,calendars. SyncCalendarBusyBlocks and SyncLeavePeriods go on `calendars` so the hourly per-account sweep can't delay emails. SyncBookingToCalendars/RemoveBookingFromCalendars MUST stay on `default`, dispatched before the booking notifications: SyncBookingToCalendars writes the Teams/Meet meeting_url the confirmation email reads, so moving it to a lower-priority queue sends emails without the join link.

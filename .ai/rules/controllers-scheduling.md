---
paths:
  - app/Http/Controllers/Scheduling/GroupController.php
---

# Controllers Scheduling

## The Group model is "Team" in the UI, one level below the organization
Naming after the 2026-08 rename: the `Team` model is an ORGANIZATION in the UI, and the `Group` model is a TEAM (Leasing, Maintenance) inside it. Do not rename the models — routes, Wayfinder output, policies and the teams/ controllers all key off the current names. Only user-facing copy says organization/team.

A Group is a reusable host pool. GroupController::syncMembers writes `priority` as the position in the submitted member_ids array, exactly like SaveEventType::syncHosts, so the order in the UI IS the booking order. Group::members() already orderByPivot('priority').

Point an event type at a group and every member hosts it: EventType::hostPool() prefers the group over pinned hosts, and SaveEventType clears pinned hosts whenever group_id is set so there is only ever one answer. Editing the group therefore changes every event type pointed at it — that propagation is the feature, and there is a test for it.

The page is at /{current_team}/groups and reached from the sidebar's "Teams" entry. It had no navigation entry at all until 2026-08; if you add a scheduling page, link it.

## Team page listing is separate from hosting
A team's public page (/book/{org}/{team}) lists event types with group_id = team (they host from it) PLUS those picked in the group_page_event_types pivot (Group::pageEventTypes). The pivot only affects listing, never the host pool, so any org event type (a member's one-on-one, a shared event hosted elsewhere) can appear on many team pages. Links still resolve through the org page by slug. BookingPageResolver::eventTypesForGroup wraps the OR in a closure so is_hidden still applies to picked ones. GroupController only syncs the pivot when page_event_type_ids is sent.

---
paths:
  - routes/booking.php
---

# Routes

## Single-use link routes must stay above book/{page}/{eventType}
book/once/{singleUseLink} has the same shape as book/{page}/{eventType}, so it must be registered first or "once" resolves as a booking page. A link is claimed with a conditional update (whereNull used_at) before booking, and released with a query update if the slot is taken. Releasing it with $model->update() does nothing because the in-memory used_at is already null. Links deliberately work for switched-off event types but not for deleted ones.

## Department pages live one segment deeper than teams and event types
book/{page}/departments/{code} lists the teams (Group) picked for a DepartmentPage (pivot department_page_groups). It is deliberately one segment longer than book/{page}/{eventType} so it can never collide with a team or event type slug; do not "shorten" it to book/{page}/{slug}, which would need reserved slugs in both slug generators. The code is a random 8-character lowercase string set once on create (DepartmentPage::generateUniqueSlug), never derived from the name, so renaming a page keeps its link.

Cards link to the team page with ?from={department slug}. BookingPageController::teamPage uses it for the back link only when that department page really lists the team; anything else falls back to the organization page. Admins manage the pages in the "Pages" section of the Teams page (DepartmentPageController has no index of its own).

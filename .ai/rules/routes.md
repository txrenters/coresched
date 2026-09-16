---
paths:
  - routes/booking.php
---

# Routes

## Single-use link routes must stay above book/{page}/{eventType}
book/once/{singleUseLink} has the same shape as book/{page}/{eventType}, so it must be registered first or "once" resolves as a booking page. A link is claimed with a conditional update (whereNull used_at) before booking, and released with a query update if the slot is taken. Releasing it with $model->update() does nothing because the in-memory used_at is already null. Links deliberately work for switched-off event types but not for deleted ones.

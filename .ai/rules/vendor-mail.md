---
paths:
  - 'resources/views/vendor/mail/**'
---

# Vendor Mail

## Mail theme mirrors the app palette in hex
resources/views/vendor/mail/html/themes/default.css overrides Laravel's default mail theme with the app palette from resources/css/app.css :root, converted from oklch to hex because email clients do not support oklch: primary/button/links #006bff, page #f5f8fd, card white with #dce5f1 border, text #16253a / muted #4b5c73, success button #527a21. When the app's :root palette changes, re-convert and update this file too. BookingManagementTest asserts the primary button and wrapper colours.

---
paths:
  - app/Support/PhoneNumber.php
---

# Support

## A phone number must exist, not just have ten digits
PhoneNumber::toE164 (and so isValid, DialableNumber and the booking form) passes the E.164 result through libphonenumber's isValidNumber (giggsey/libphonenumber-for-php-lite). Added 2026-10-02 after Twilio refused +1861239XXXX: 861 is no US area code, but it had ten digits. Test fixtures must use numbers libphonenumber accepts; 512-555-01XX and 281-555-0100 do, made-up area codes do not. A stored number that fails is treated as no number (recipient skipped), not sent to Twilio.

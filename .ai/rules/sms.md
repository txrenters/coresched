---
paths:
  - 'app/Services/Sms/**'
---

# Sms

## Twilio: every organization brings its own account; there is no house account
As of 2026-10-27 each organization connects its own Twilio account in its settings (teams.twilio_account_sid, teams.twilio_auth_token — the token is an `encrypted` cast and #[Hidden]). An organization without both has SMS off: RunAutomations records "has not connected a Twilio account" and the workflow editor links to the settings. Never add a fallback to an installation-wide account — that decision was made so no organization texts on someone else's bill.

Build clients with Team::twilio(); do not resolve TwilioClient from the container (it would have no credentials). services.twilio.sid/token are read ONLY by the 2026_10_27 migration, which handed the old house account to every organization that existed at deploy time.

The settings page never receives the token: TeamController::smsSettings sends its last four characters as tokenHint, and SID/hint/numbers only to users who can update the team. A blank token on save means "keep the saved one"; a changed SID never reuses the saved token. SaveTeamRequest::after() checks new credentials against Twilio (GET the account) before saving, and twilioAfterSave() refuses a SID not shaped like AC + 32 hex before any request, because the sms_from_number rule is built from raw input. Disconnecting (remove_twilio, or emptying the SID) also clears sms_from_number.

Each organization picks which of ITS account's numbers it sends from (teams.sms_from_number); SaveTeamRequest only accepts a number that account owns. The numbers list is cached per SID + token hash.

TwilioClient is plain Http facade calls, no SDK — same as the Graph mailer and the calendar providers — so tests use Http::fake(). Tests give the team credentials (twilio_account_sid / twilio_auth_token) alongside Http::fake(); nothing reads Twilio config at runtime.

Every number is stored in E.164 (App\Support\PhoneNumber), because that is what Twilio dials. Hosts' numbers are users.phone (optional, profile page); the invitee's comes from Booking::inviteePhone(). A recipient with no number is skipped silently, but an organization with no sms_from_number fails the run — one unfilled profile must not stop the text reaching everyone else, while a workflow that can never text anybody is a setup mistake worth surfacing.

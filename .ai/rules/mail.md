---
paths:
  - 'app/Services/Mail/**'
---

# Mail

## Mail goes out through Graph because the sender is a shared mailbox
scheduling@texasrenters.com is a shared M365 mailbox: no licence, no password, sign-in blocked, so it cannot authenticate to SMTP. MicrosoftGraphTransport posts to /users/{mailbox}/sendMail with an app-only client-credentials token instead of signing in as the mailbox.

Mail has its OWN Azure app registration (MAIL_GRAPH_TENANT / _CLIENT_ID / _CLIENT_SECRET on the mailer's config in config/mail.php), separate from the calendar's services.microsoft.*. It holds the Mail.Send APPLICATION permission with admin consent — a permission that sends as a mailbox with no user present — so it gets its own secret to rotate. Narrow it to this one mailbox with an Exchange application access policy, or it can send as every mailbox in the tenant. The keys fall back to the calendar's registration for a setup that runs both from one app.

MAIL_GRAPH_TENANT must name the real tenant. The calendar's user sign-in flow accepts the multi-tenant 'common' placeholder and MICROSOFT_TENANT is deliberately left on it; client credentials cannot, since there is no user to resolve the tenant from. AppServiceProvider::registerMicrosoftGraphMailer() rejects 'common' rather than letting the token request fail at send time.

The fallbacks use `?:`, not env()'s second argument: these keys ship present-but-empty in .env.example, and env() returns '' for those, so a default would never be reached. The same trap is why the provider checks blank() on every key instead of trusting config to be filled.

Like the calendar providers, this is plain Http facade calls, no microsoft-graph SDK, so tests use Http::fake(). A Graph message holds one body: an email with both parts sends as HTML and the plain text alternative is dropped.

## Every email comes from one sender by default
All mail, booking and account alike, goes out through the microsoft-graph mailer from mail.from (MAIL_FROM_ADDRESS, default scheduling@texasrenters.com). mail.account_from falls back to it. Only when MAIL_ACCOUNT_FROM_ADDRESS is set do password reset, email verification and organization invitations switch to that address. App\Listeners\SendAccountMailFromNoReply rewrites From on MessageSending by matching the __laravel_notification class. It is a listener, not an override, because ResetPassword and VerifyEmail are the framework's own classes: subclassing them would break every Notification::assertSentTo(ResetPassword::class). Add any new account notification to its ACCOUNT_NOTIFICATIONS list.
MicrosoftGraphTransport posts to /users/{from}/sendMail when the From address is the configured mailbox or is listed in the mailer's send_as config (MAIL_ACCOUNT_FROM_ADDRESS when set). Otherwise it uses the configured mailbox, so an unexpected From can never pick an arbitrary mailbox. The Exchange application access policy must cover every mailbox mail is sent from, or Graph returns ErrorAccessDenied.

## Graph mail keeps a copy in the shared mailbox's Sent Items
save_to_sent_items defaults to true (MAIL_GRAPH_SAVE_TO_SENT_ITEMS) since 2026-10-08, so scheduling@texasrenters.com's Sent Items is the place to check whether a reminder or booking email went out. Before that date it defaulted to false and production kept no copies: an empty Sent Items for older mail means nothing, use an Exchange message trace instead. The app's own record of a reminder is booking_reminders.sent_at; the scheduler log's DONE line does not say whether anything was sent.

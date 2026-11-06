<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import LegalDocument from '@/components/marketing/LegalDocument.vue';
import { terms } from '@/routes';

const name = usePage().props.name;
</script>

<template>
    <Head title="Privacy Policy" />

    <LegalDocument title="Privacy Policy" updated="November 6, 2026">
        <section>
            <p>
                This Privacy Policy explains what information {{ name }}
                collects, how we use it, and the choices you have. It applies to
                people who use the Service through an organization's account and
                to people who book meetings on an organization's booking page.
                It should be read with our
                <Link :href="terms().url">Terms of Service</Link>. {{ name }} is
                developed and maintained by
                <a href="https://corepathbpo.com" target="_blank" rel="noopener"
                    >corepathbpo.com</a
                >
                ("we", "us").
            </p>
            <p>
                When you book with an organization, that organization decides
                why your details are collected and how they are used. We process
                them on the organization's behalf to run the booking.
            </p>
        </section>

        <section>
            <h2>1. Information we collect</h2>
            <ul>
                <li>
                    <strong>Account information:</strong> name, email address,
                    password (stored hashed), passkeys, two-factor settings,
                    time zone and the organizations and teams you belong to.
                </li>
                <li>
                    <strong>Booking information:</strong> the name, email
                    address, phone number and answers to booking questions that
                    a person gives when booking, plus the meeting time, host,
                    status, notes and any reschedule or cancellation.
                </li>
                <li>
                    <strong>Calendar information:</strong> when you connect a
                    calendar, the free and busy times of your events, the events
                    {{ name }} creates for bookings, and, for Microsoft 365,
                    whether an automatic out of office reply is on.
                </li>
                <li>
                    <strong>Messages:</strong> the confirmation, reminder and
                    workflow emails and text messages sent for a booking, and
                    whether they were delivered.
                </li>
                <li>
                    <strong>Usage and device information:</strong> IP address,
                    browser type, pages visited, sign-in times and an activity
                    log of changes made in an organization.
                </li>
                <li>
                    <strong>Sales inquiries:</strong> the details you enter in
                    the contact sales form.
                </li>
            </ul>
        </section>

        <section>
            <h2>2. How we use information</h2>
            <ul>
                <li>
                    To show availability, take bookings, assign hosts and create
                    calendar events and meeting links.
                </li>
                <li>
                    To send confirmations, reminders, follow-ups and account
                    emails such as password resets and invitations.
                </li>
                <li>
                    To deliver booking updates to systems an organization has
                    connected through webhooks or the API.
                </li>
                <li>
                    To keep the Service secure, prevent abuse, fix problems and
                    provide support.
                </li>
                <li>To respond to sales inquiries.</li>
                <li>To meet legal obligations.</li>
            </ul>
            <p>
                We do not sell personal information, and we do not use it for
                advertising.
            </p>
        </section>

        <section>
            <h2>3. Google and Microsoft calendar data</h2>
            <p>
                If you connect Google Calendar, {{ name }} reads your calendars
                to find busy times so people cannot book over existing meetings,
                and creates, updates and deletes the events for bookings made
                through the Service. If you connect Microsoft 365, we do the
                same with your Outlook calendar and read your mailbox settings
                only to check whether an automatic out of office reply is on.
            </p>
            <p>
                {{ name }}'s use and transfer of information received from
                Google APIs adheres to the
                <a
                    href="https://developers.google.com/terms/api-services-user-data-policy"
                    target="_blank"
                    rel="noopener"
                    >Google API Services User Data Policy</a
                >, including the Limited Use requirements. We use calendar data
                only to provide scheduling features you can see, we do not use
                it for advertising, we do not sell it, and people do not read it
                except with your permission, for security, or where the law
                requires. You can disconnect a calendar at any time from your
                settings, which deletes the stored access tokens.
            </p>
        </section>

        <section>
            <h2>4. Text messages</h2>
            <p>
                Organizations can send booking confirmations and reminders by
                text message through their own Twilio account. Message and data
                rates may apply. Reply STOP to opt out or HELP for help. We do
                not share phone numbers or text message consent with third
                parties for their marketing.
            </p>
        </section>

        <section>
            <h2>5. Who we share information with</h2>
            <ul>
                <li>
                    <strong>The organization you book with or work for,</strong>
                    including its hosts and administrators.
                </li>
                <li>
                    <strong>Service providers</strong> that help us run the
                    Service, such as hosting, Microsoft 365 for email, Twilio
                    for text messages, and Google and Microsoft for calendar and
                    meeting features. They may use the information only to
                    provide their service to us.
                </li>
                <li>
                    <strong>Systems an organization connects,</strong> such as a
                    CRM receiving booking webhooks.
                </li>
                <li>
                    <strong>Legal and safety:</strong> where required by law or
                    to protect the rights, property or safety of people or the
                    Service.
                </li>
                <li>
                    <strong>Business transfers:</strong> as part of a merger,
                    acquisition or sale of assets, subject to this Policy.
                </li>
            </ul>
        </section>

        <section>
            <h2>6. Cookies</h2>
            <p>
                We use cookies that are needed to keep you signed in, protect
                forms against forgery and remember settings such as light or
                dark mode. We do not use advertising or cross-site tracking
                cookies.
            </p>
            <p>
                On our public website pages (not booking pages or the app after
                you sign in) we count visits ourselves, without cookies or
                tracking scripts. We record the page, the site that linked to it
                and whether you used a phone, tablet or computer. We do not
                store your IP address, and nothing is shared with anyone else.
            </p>
        </section>

        <section>
            <h2>7. Retention</h2>
            <p>
                We keep account and booking information for as long as the
                organization's account is active, or as long as needed to
                provide the Service and meet legal obligations. When an
                organization closes its account, we delete or anonymize its data
                within a reasonable time, except where we must keep it by law.
            </p>
        </section>

        <section>
            <h2>8. Security</h2>
            <p>
                We protect information with encryption in transit, encrypted
                storage of secrets such as calendar tokens, two-factor
                authentication and passkeys, and access limited by role. No
                system is perfectly secure, but we work to keep your information
                safe and will notify affected organizations of a breach as the
                law requires.
            </p>
        </section>

        <section>
            <h2>9. Your choices and rights</h2>
            <p>
                You can update your profile, disconnect calendars and delete
                your account from your settings. Depending on where you live,
                you may also have the right to access, correct, delete or
                receive a copy of your personal information, or to object to
                certain uses. If you booked with an organization, contact that
                organization first; we will help them respond. Otherwise contact
                us using the details below.
            </p>
        </section>

        <section>
            <h2>10. Children</h2>
            <p>
                The Service is not directed to children under 13, and we do not
                knowingly collect their personal information.
            </p>
        </section>

        <section>
            <h2>11. Changes to this Policy</h2>
            <p>
                We may update this Policy from time to time. We will change the
                date at the top of this page and, for material changes, notify
                organization administrators by email.
            </p>
        </section>

        <section>
            <h2>12. Contact</h2>
            <p>
                Questions or requests about privacy can be sent to
                <a href="mailto:support@coresched.com">support@coresched.com</a
                >.
            </p>
        </section>
    </LegalDocument>
</template>

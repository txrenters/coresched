<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import LegalDocument from '@/components/marketing/LegalDocument.vue';
import { pricing, privacy } from '@/routes';

const name = usePage().props.name;
</script>

<template>
    <Head title="Terms of Service" />

    <LegalDocument title="Terms of Service" updated="November 4, 2026">
        <section>
            <p>
                These Terms of Service ("Terms") govern your use of {{ name }},
                the scheduling software available at this website (the
                "Service"). By creating an account, joining an organization, or
                booking a meeting through the Service, you agree to these Terms.
                If you use the Service on behalf of an organization, you agree
                to them for that organization and confirm you have the authority
                to do so. {{ name }} is developed and maintained by
                <a href="https://corepathbpo.com" target="_blank" rel="noopener"
                    >corepathbpo.com</a
                >
                ("we", "us").
            </p>
        </section>

        <section>
            <h2>1. The Service</h2>
            <p>
                {{ name }} lets organizations publish booking pages, route
                meetings to the right people, sync with Google and Microsoft
                calendars, and send email and text message confirmations and
                reminders. Features available to you depend on your
                organization's plan.
            </p>
        </section>

        <section>
            <h2>2. Accounts</h2>
            <ul>
                <li>
                    You must give accurate information and keep your sign-in
                    details, passkeys and two-factor codes secure.
                </li>
                <li>
                    You are responsible for activity under your account. Tell us
                    promptly if you believe it has been used without permission.
                </li>
                <li>
                    Organization administrators control who joins their
                    organization, what members can do, and the settings that
                    apply to their booking pages.
                </li>
            </ul>
        </section>

        <section>
            <h2>3. People who book meetings</h2>
            <p>
                You do not need an account to book a meeting. When you book, you
                share your details with the organization you are booking with,
                and that organization is responsible for how it uses them. You
                agree to give accurate details and not to make bookings in
                someone else's name without their permission.
            </p>
        </section>

        <section>
            <h2>4. Acceptable use</h2>
            <p>You agree not to:</p>
            <ul>
                <li>
                    Use the Service to send spam, or send text messages or
                    emails to people who have not agreed to receive them.
                </li>
                <li>
                    Break any law, including privacy, anti-spam and
                    telemarketing laws such as the TCPA and CAN-SPAM.
                </li>
                <li>
                    Upload content that is unlawful, harmful or infringes
                    someone else's rights.
                </li>
                <li>
                    Try to access accounts or data that are not yours, probe or
                    disrupt the Service, or get around its limits or security.
                </li>
                <li>
                    Resell or copy the Service, or use the API beyond the access
                    your organization has been given.
                </li>
            </ul>
        </section>

        <section>
            <h2>5. Your content and data</h2>
            <p>
                You and your organization keep ownership of the content you put
                into the Service, such as event types, booking questions,
                messages and booking records. You give us permission to store,
                process and display that content only as needed to run and
                support the Service. How we handle personal information is
                described in our
                <Link :href="privacy().url">Privacy Policy</Link>.
            </p>
        </section>

        <section>
            <h2>6. Connected services</h2>
            <p>
                The Service connects to third-party services you choose to link,
                such as Google Calendar, Microsoft 365, Microsoft Teams, Google
                Meet and Twilio. Your use of those services is governed by their
                own terms. We are not responsible for outages or changes on
                their side, and you can disconnect them at any time.
            </p>
        </section>

        <section>
            <h2>7. Plans and payment</h2>
            <p>
                Plans are quoted for your organization and agreed with you
                before they start. See
                <Link :href="pricing().url">Pricing</Link> to contact sales. Any
                fees, billing periods and renewal terms in your agreement with
                us apply alongside these Terms. Text messages are sent through
                your organization's own Twilio account and billed by Twilio.
            </p>
        </section>

        <section>
            <h2>8. Availability and changes</h2>
            <p>
                We work to keep the Service available and secure, but we do not
                promise it will be uninterrupted or error free. We may improve,
                change or remove features. If a change materially reduces what
                your organization has paid for, we will tell you in advance.
            </p>
        </section>

        <section>
            <h2>9. Suspension and termination</h2>
            <p>
                You can stop using the Service at any time. We may suspend or
                close an account that breaks these Terms or puts the Service or
                other people at risk. When an organization's account closes, its
                booking pages stop accepting bookings and we delete or return
                its data as described in the Privacy Policy.
            </p>
        </section>

        <section>
            <h2>10. Disclaimers and limitation of liability</h2>
            <p>
                The Service is provided "as is" and "as available". To the
                fullest extent the law allows, we disclaim all implied
                warranties, including merchantability, fitness for a particular
                purpose and non-infringement. We are not liable for indirect,
                incidental, special or consequential damages, or for missed
                meetings, lost profits or lost data. Our total liability for any
                claim is limited to the amount your organization paid us for the
                Service in the twelve months before the claim.
            </p>
        </section>

        <section>
            <h2>11. Indemnity</h2>
            <p>
                You agree to cover any claims, losses and costs that arise from
                your misuse of the Service or your breach of these Terms,
                including messages you send through it.
            </p>
        </section>

        <section>
            <h2>12. Governing law</h2>
            <p>
                These Terms are governed by the laws of the State of Texas,
                without regard to its conflict of law rules. Any dispute will be
                heard in the state or federal courts located in Texas.
            </p>
        </section>

        <section>
            <h2>13. Changes to these Terms</h2>
            <p>
                We may update these Terms from time to time. We will change the
                date at the top of this page and, for material changes, notify
                organization administrators by email. Continuing to use the
                Service after a change means you accept the updated Terms.
            </p>
        </section>

        <section>
            <h2>14. Contact</h2>
            <p>
                Questions about these Terms can be sent to
                <a href="mailto:support@coresched.com">support@coresched.com</a
                >.
            </p>
        </section>
    </LegalDocument>
</template>

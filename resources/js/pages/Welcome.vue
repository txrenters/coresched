<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgeCheck,
    Briefcase,
    CalendarCheck,
    CalendarClock,
    CalendarSync,
    Check,
    ClipboardList,
    Globe,
    HeadsetIcon,
    Link2,
    MessageSquare,
    QrCode,
    Shuffle,
    Sparkles,
    Stethoscope,
    UserPlus,
    Users,
    Video,
    Webhook,
    Wrench,
} from '@lucide/vue';
import BookingPreview from '@/components/marketing/BookingPreview.vue';
import DashboardPreview from '@/components/marketing/DashboardPreview.vue';
import MarketingFooter from '@/components/marketing/MarketingFooter.vue';
import MarketingHeader from '@/components/marketing/MarketingHeader.vue';
import ProductShowcase from '@/components/marketing/ProductShowcase.vue';
import RoutingPreview from '@/components/marketing/RoutingPreview.vue';
import WorkflowPreview from '@/components/marketing/WorkflowPreview.vue';
import { Button } from '@/components/ui/button';
import { vReveal } from '@/lib/reveal';
import { pricing } from '@/routes';
import { store as startDemo } from '@/routes/demo';

const contactUrl = `${pricing().url}#contact`;

const worksWith = [
    { icon: CalendarSync, label: 'Google Calendar' },
    { icon: CalendarSync, label: 'Microsoft 365' },
    { icon: Video, label: 'Microsoft Teams' },
    { icon: Video, label: 'Google Meet' },
    { icon: MessageSquare, label: 'SMS reminders' },
    { icon: Sparkles, label: 'Calendly import' },
];

const steps = [
    {
        title: 'Connect your calendars',
        body: 'Each person links Google or Microsoft 365 once. Busy time, holidays and leave are picked up automatically.',
    },
    {
        title: 'Publish your meetings',
        body: 'Set up one-on-one, round-robin, collective or group events, with the questions you need answered up front.',
    },
    {
        title: 'Share one link',
        body: 'Invitees pick a time that works. The right person is booked, the video link is created, and reminders go out.',
    },
];

const extras = [
    {
        icon: BadgeCheck,
        title: 'Approvals',
        body: 'Hold requests as pending until someone on the team approves or declines them.',
    },
    {
        icon: ClipboardList,
        title: 'Booking questions',
        body: 'Text, choice, yes/no and phone questions, answered before the meeting is booked.',
    },
    {
        icon: Link2,
        title: 'Single-use links',
        body: 'Send a one-time link that closes the moment it is used.',
    },
    {
        icon: QrCode,
        title: 'QR codes',
        body: 'Print a code for any booking page, for front desks, flyers and events.',
    },
    {
        icon: Webhook,
        title: 'Webhooks & API',
        body: 'Push every booking into your CRM or build on top of the REST API.',
    },
    {
        icon: Globe,
        title: 'Timezone-proof',
        body: 'Hosts work in their own hours, invitees see their own time, and buffers and notice periods are respected.',
    },
];

const useCases = [
    {
        icon: Briefcase,
        title: 'Sales',
        body: 'Demos and discovery calls, routed to the next rep up.',
    },
    {
        icon: Users,
        title: 'Customer success',
        body: 'Onboarding and account reviews with the right owner.',
    },
    {
        icon: HeadsetIcon,
        title: 'Support',
        body: 'Callbacks booked instead of tickets left waiting.',
    },
    {
        icon: UserPlus,
        title: 'Recruiting',
        body: 'Panel interviews that find a time for everyone at once.',
    },
    {
        icon: Wrench,
        title: 'Field services',
        body: 'In-person visits with the address and phone collected up front.',
    },
    {
        icon: Stethoscope,
        title: 'Professional services',
        body: 'Consultations that need approval before they are confirmed.',
    },
];

const faqs = [
    {
        question: 'How is this different from a personal booking link?',
        answer: 'Personal links book one person. CoreSched books a team: it checks everyone in a pool, spreads meetings with round-robin, finds a time that works for a whole panel, and gives each department its own page.',
    },
    {
        question: 'Which calendars and video tools does it work with?',
        answer: 'Google Calendar and Microsoft 365 for availability and booking, with Microsoft Teams or Google Meet links created automatically. Phone, in-person and custom meeting links work too.',
    },
    {
        question: 'Can we move over from Calendly?',
        answer: 'Yes. During onboarding we import your event types and schedules from Calendly, quietly, so nobody is emailed about meetings that were already arranged.',
    },
    {
        question: 'How do we get started?',
        answer: 'Contact sales. Accounts are set up for your organization and your team is invited by email.',
    },
];
</script>

<template>
    <Head title="Scheduling software for service teams" />

    <div class="min-h-svh overflow-x-clip bg-background">
        <MarketingHeader />

        <main>
            <!-- Hero -->
            <section class="relative isolate">
                <div
                    class="pointer-events-none absolute inset-0 -z-10 bg-[linear-gradient(to_right,var(--border)_1px,transparent_1px),linear-gradient(to_bottom,var(--border)_1px,transparent_1px)] [mask-image:radial-gradient(ellipse_70%_60%_at_50%_0%,black,transparent)] bg-[size:56px_56px] opacity-60"
                    aria-hidden="true"
                />
                <div
                    class="pointer-events-none absolute -top-40 left-1/2 -z-10 size-[44rem] -translate-x-1/2 rounded-full bg-primary/15 blur-3xl"
                    aria-hidden="true"
                />

                <div
                    class="mx-auto grid w-full max-w-6xl items-center gap-14 px-4 pt-16 pb-20 sm:px-6 sm:pt-24 lg:grid-cols-[1.05fr_1fr] lg:gap-12"
                >
                    <div>
                        <p
                            v-reveal
                            class="inline-flex items-center gap-2 rounded-full border border-border bg-card/80 px-3 py-1 text-xs font-semibold text-muted-foreground backdrop-blur"
                        >
                            <span
                                class="size-1.5 rounded-full bg-success"
                                aria-hidden="true"
                            />
                            Built for teams that run on appointments
                        </p>

                        <h1
                            v-reveal="80"
                            class="mt-6 text-4xl font-bold tracking-tight text-balance sm:text-6xl"
                        >
                            Every booking, to the
                            <span
                                class="bg-linear-to-r from-primary to-success bg-clip-text text-transparent"
                                >right person</span
                            >.
                        </h1>
                        <p
                            v-reveal="160"
                            class="mt-6 max-w-xl text-lg leading-relaxed text-muted-foreground"
                        >
                            CoreSched is the booking link your whole team
                            shares. It checks everyone's calendar, routes each
                            meeting to the right host, sends the reminders, and
                            keeps Google and Microsoft calendars in sync.
                        </p>

                        <div
                            v-reveal="240"
                            class="mt-8 flex flex-col gap-3 sm:flex-row"
                        >
                            <Button
                                size="lg"
                                class="cursor-pointer font-semibold"
                                as-child
                            >
                                <Link :href="contactUrl">
                                    Contact sales
                                    <ArrowRight
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </Button>
                            <Button
                                size="lg"
                                variant="outline"
                                class="cursor-pointer font-semibold"
                                as-child
                            >
                                <Link
                                    :href="startDemo()"
                                    as="button"
                                    data-test="hero-try-demo"
                                >
                                    Try the demo
                                </Link>
                            </Button>
                        </div>

                        <ul
                            v-reveal="320"
                            class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground"
                        >
                            <li
                                v-for="item in [
                                    'Round-robin & panels',
                                    'Email & SMS workflows',
                                    'Teams & Meet links',
                                ]"
                                :key="item"
                                class="inline-flex items-center gap-1.5"
                            >
                                <Check
                                    class="size-4 shrink-0 text-success"
                                    aria-hidden="true"
                                />
                                {{ item }}
                            </li>
                        </ul>
                    </div>

                    <!--
                      Static renderings of the real product, so the hero shows
                      the app rather than stock art. Decorative: hidden from
                      the accessibility tree.
                    -->
                    <div v-reveal="200" class="relative" aria-hidden="true">
                        <BookingPreview class="relative" />

                        <div
                            class="absolute -bottom-6 -left-4 hidden animate-float items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 shadow-overlay sm:flex"
                        >
                            <span
                                class="flex size-8 items-center justify-center rounded-full bg-success-muted text-success-muted-foreground"
                            >
                                <CalendarCheck class="size-4" />
                            </span>
                            <span class="text-xs">
                                <span class="block font-semibold"
                                    >Booked with James T.</span
                                >
                                <span class="text-muted-foreground"
                                    >Teams link sent · Thu 9:30 am</span
                                >
                            </span>
                        </div>

                        <div
                            class="absolute -top-5 -right-3 hidden animate-float items-center gap-2 rounded-full border border-border bg-card px-3 py-1.5 text-xs font-semibold shadow-raised [animation-delay:-3s] sm:flex"
                        >
                            <Shuffle class="size-3.5 text-primary" />
                            Round-robin · 3 hosts
                        </div>
                    </div>
                </div>
            </section>

            <!-- Works with -->
            <section
                class="border-y border-border bg-card/60"
                aria-label="Works with"
            >
                <div
                    class="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-center gap-x-8 gap-y-3 px-4 py-6 text-sm font-medium text-muted-foreground sm:px-6"
                >
                    <span class="text-xs font-semibold tracking-wider uppercase"
                        >Works with</span
                    >
                    <span
                        v-for="item in worksWith"
                        :key="item.label"
                        class="inline-flex items-center gap-2"
                    >
                        <component
                            :is="item.icon"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ item.label }}
                    </span>
                </div>
            </section>

            <!-- Product showcase: real screenshots -->
            <section
                class="mx-auto w-full max-w-6xl px-4 pt-20 sm:px-6 sm:pt-28"
                aria-labelledby="showcase-heading"
            >
                <div v-reveal class="text-center">
                    <p class="text-sm font-semibold text-primary">
                        See it in action
                    </p>
                    <h2
                        id="showcase-heading"
                        class="mx-auto mt-2 max-w-2xl text-3xl font-bold tracking-tight text-balance sm:text-4xl"
                    >
                        One app for the invitee, the host and the admin
                    </h2>
                </div>

                <ProductShowcase v-reveal="120" class="mt-10" />
            </section>

            <!-- How it works -->
            <section
                id="how-it-works"
                class="mx-auto w-full max-w-6xl scroll-mt-20 px-4 py-20 sm:px-6 sm:py-28"
                aria-labelledby="how-heading"
            >
                <p v-reveal class="text-sm font-semibold text-primary">
                    How it works
                </p>
                <h2
                    id="how-heading"
                    v-reveal="60"
                    class="mt-2 max-w-2xl text-3xl font-bold tracking-tight text-balance sm:text-4xl"
                >
                    Live in an afternoon. Running itself by tomorrow.
                </h2>

                <ol class="mt-12 grid gap-6 md:grid-cols-3">
                    <li
                        v-for="(step, index) in steps"
                        :key="step.title"
                        v-reveal="index * 120"
                        class="rounded-2xl border border-border bg-card p-6 shadow-flat"
                    >
                        <span
                            class="flex size-9 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground"
                        >
                            {{ index + 1 }}
                        </span>
                        <h3 class="mt-5 font-semibold">{{ step.title }}</h3>
                        <p
                            class="mt-2 text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ step.body }}
                        </p>
                    </li>
                </ol>
            </section>

            <!-- Features -->
            <section
                id="features"
                class="scroll-mt-16 border-y border-border bg-card"
                aria-labelledby="features-heading"
            >
                <div
                    class="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6 sm:py-28"
                >
                    <p v-reveal class="text-sm font-semibold text-primary">
                        Features
                    </p>
                    <h2
                        id="features-heading"
                        v-reveal="60"
                        class="mt-2 max-w-2xl text-3xl font-bold tracking-tight text-balance sm:text-4xl"
                    >
                        A booking link that knows how your team works
                    </h2>

                    <div class="mt-12 grid gap-6 lg:grid-cols-3">
                        <article
                            v-reveal
                            class="flex flex-col rounded-2xl border border-border bg-muted/40 p-6 transition hover:-translate-y-0.5 hover:shadow-raised"
                        >
                            <Shuffle
                                class="size-5 text-primary"
                                aria-hidden="true"
                            />
                            <h3 class="mt-4 font-semibold">
                                Routing that is fair
                            </h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                Round-robin spreads the load across a pool.
                                Collective finds a time for everyone on a panel.
                                Group events fill seats.
                            </p>
                            <RoutingPreview class="mt-6" aria-hidden="true" />
                        </article>

                        <article
                            v-reveal
                            class="flex flex-col rounded-2xl border border-border bg-muted/40 p-6 transition hover:-translate-y-0.5 hover:shadow-raised"
                        >
                            <MessageSquare
                                class="size-5 text-primary"
                                aria-hidden="true"
                            />
                            <h3 class="mt-4 font-semibold">
                                Workflows that follow up
                            </h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                Email and SMS on booking, before the start,
                                after the end, or on a no-show, to the invitee,
                                the host, or anyone you name.
                            </p>
                            <WorkflowPreview class="mt-6" aria-hidden="true" />
                        </article>

                        <article
                            v-reveal
                            class="flex flex-col rounded-2xl border border-border bg-muted/40 p-6 transition hover:-translate-y-0.5 hover:shadow-raised"
                        >
                            <CalendarClock
                                class="size-5 text-primary"
                                aria-hidden="true"
                            />
                            <h3 class="mt-4 font-semibold">
                                One view of the day
                            </h3>
                            <p
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                Upcoming and past meetings, approvals waiting on
                                you, no-shows and hand-offs, with an activity
                                log of who changed what.
                            </p>
                            <DashboardPreview class="mt-6" aria-hidden="true" />
                        </article>
                    </div>

                    <ul class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        <li
                            v-for="(extra, index) in extras"
                            :key="extra.title"
                            v-reveal="(index % 3) * 100"
                            class="flex gap-4 rounded-2xl border border-border p-5"
                        >
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground"
                            >
                                <component
                                    :is="extra.icon"
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <span>
                                <span class="block font-semibold">{{
                                    extra.title
                                }}</span>
                                <span
                                    class="mt-1 block text-sm leading-relaxed text-muted-foreground"
                                    >{{ extra.body }}</span
                                >
                            </span>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Use cases -->
            <section
                class="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6 sm:py-28"
                aria-labelledby="teams-heading"
            >
                <p v-reveal class="text-sm font-semibold text-primary">
                    Who it's for
                </p>
                <h2
                    id="teams-heading"
                    v-reveal="60"
                    class="mt-2 max-w-2xl text-3xl font-bold tracking-tight text-balance sm:text-4xl"
                >
                    For every team whose work starts with a meeting
                </h2>

                <ul class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <li
                        v-for="(useCase, index) in useCases"
                        :key="useCase.title"
                        v-reveal="(index % 3) * 100"
                        class="group rounded-2xl border border-border bg-card p-6 shadow-flat transition hover:-translate-y-0.5 hover:shadow-raised"
                    >
                        <component
                            :is="useCase.icon"
                            class="size-6 text-primary"
                            aria-hidden="true"
                        />
                        <h3 class="mt-4 font-semibold">{{ useCase.title }}</h3>
                        <p class="mt-1.5 text-sm text-muted-foreground">
                            {{ useCase.body }}
                        </p>
                    </li>
                </ul>
            </section>

            <!-- FAQ -->
            <section
                class="border-t border-border bg-card"
                aria-labelledby="faq-heading"
            >
                <div
                    class="mx-auto grid w-full max-w-6xl gap-10 px-4 py-20 sm:px-6 sm:py-24 lg:grid-cols-[1fr_2fr]"
                >
                    <h2
                        id="faq-heading"
                        class="text-3xl font-bold tracking-tight"
                    >
                        Questions, answered
                    </h2>
                    <dl class="divide-y divide-border">
                        <div
                            v-for="(faq, index) in faqs"
                            :key="faq.question"
                            v-reveal="index * 80"
                            class="py-5 first:pt-0"
                        >
                            <dt class="font-semibold">{{ faq.question }}</dt>
                            <dd
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ faq.answer }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>

            <!-- Closing CTA -->
            <section
                class="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6 sm:py-24"
            >
                <div
                    v-reveal
                    class="relative isolate overflow-hidden rounded-3xl bg-primary px-6 py-14 text-center text-primary-foreground sm:px-12"
                >
                    <div
                        class="absolute -top-24 -right-24 -z-10 size-72 animate-float rounded-full bg-primary-foreground/10 blur-2xl"
                        aria-hidden="true"
                    />
                    <div
                        class="absolute -bottom-24 -left-24 -z-10 size-72 rounded-full bg-success/30 blur-3xl"
                        aria-hidden="true"
                    />
                    <h2
                        class="mx-auto max-w-2xl text-3xl font-bold tracking-tight text-balance sm:text-4xl"
                    >
                        Give people one link instead of six emails
                    </h2>
                    <p
                        class="mx-auto mt-4 max-w-prose leading-relaxed opacity-90"
                    >
                        Tell us about your team and we will set CoreSched up
                        around how you already work.
                    </p>
                    <div
                        class="mt-8 flex flex-col justify-center gap-3 sm:flex-row"
                    >
                        <Button
                            size="lg"
                            variant="secondary"
                            class="cursor-pointer font-semibold"
                            as-child
                        >
                            <Link :href="contactUrl">
                                Contact sales
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>
        </main>

        <MarketingFooter />
    </div>
</template>

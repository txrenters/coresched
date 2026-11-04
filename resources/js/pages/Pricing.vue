<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowRight, Check, CircleCheck } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import MarketingFooter from '@/components/marketing/MarketingFooter.vue';
import MarketingHeader from '@/components/marketing/MarketingHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { vReveal } from '@/lib/reveal';
import { store } from '@/routes/contact-sales';

type PlanKey = 'team' | 'business' | 'enterprise';

const plans: {
    key: PlanKey;
    name: string;
    tagline: string;
    recommended?: boolean;
    lead?: string;
    features: string[];
}[] = [
    {
        key: 'team',
        name: 'Team',
        tagline: 'For a small team sharing one booking link.',
        features: [
            'Event types and booking pages',
            'One-on-one and group events',
            'Google Calendar and Microsoft 365 sync',
            'Teams and Meet links created automatically',
            'Email confirmations and reminders',
            'Booking questions',
        ],
    },
    {
        key: 'business',
        name: 'Business',
        tagline: 'For teams that route meetings across people.',
        recommended: true,
        lead: 'Everything in Team, plus',
        features: [
            'Round-robin and collective events',
            'Email and SMS workflows',
            'Approvals, hand-offs and no-show tracking',
            'Department booking pages',
            'Single-use links and QR codes',
            'Webhooks and REST API',
        ],
    },
    {
        key: 'enterprise',
        name: 'Enterprise',
        tagline: 'For organizations running many teams.',
        lead: 'Everything in Business, plus',
        features: [
            'Multiple organizations under one account',
            'Central user directory and roles',
            'Activity log across every team',
            'Calendly import during onboarding',
            'Hands-on setup and onboarding',
        ],
    },
];

const teamSizes = ['1-10', '11-50', '51-200', '200+'];

const selectedPlan = ref<PlanKey | ''>('');

function choosePlan(plan: PlanKey): void {
    selectedPlan.value = plan;
    document
        .getElementById('contact')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
</script>

<template>
    <Head title="Pricing" />

    <div class="min-h-svh overflow-x-clip bg-background">
        <MarketingHeader />

        <main>
            <section class="relative isolate">
                <div
                    class="pointer-events-none absolute -top-48 left-1/2 -z-10 size-[40rem] -translate-x-1/2 rounded-full bg-primary/15 blur-3xl"
                    aria-hidden="true"
                />
                <div
                    class="mx-auto w-full max-w-6xl px-4 pt-16 pb-12 text-center sm:px-6 sm:pt-24"
                >
                    <p class="text-sm font-semibold text-primary">Pricing</p>
                    <h1
                        v-reveal
                        class="mx-auto mt-2 max-w-3xl text-4xl font-bold tracking-tight text-balance sm:text-5xl"
                    >
                        A plan for how your team books
                    </h1>
                    <p
                        class="mx-auto mt-5 max-w-2xl text-lg text-muted-foreground"
                    >
                        Every plan is quoted for your team size and set up with
                        you. Pick the one that fits and we will be in touch.
                    </p>
                </div>
            </section>

            <section
                class="mx-auto w-full max-w-6xl px-4 pb-20 sm:px-6"
                aria-label="Plans"
            >
                <div class="grid gap-6 lg:grid-cols-3">
                    <article
                        v-for="(plan, index) in plans"
                        :key="plan.key"
                        v-reveal="index * 120"
                        :class="[
                            'relative flex flex-col rounded-2xl border bg-card p-7',
                            plan.recommended
                                ? 'border-primary shadow-overlay ring-1 ring-primary'
                                : 'border-border shadow-flat',
                        ]"
                    >
                        <span
                            v-if="plan.recommended"
                            class="absolute -top-3 left-7 rounded-full bg-primary px-3 py-1 text-xs font-semibold text-primary-foreground"
                        >
                            Recommended
                        </span>

                        <h2 class="text-xl font-bold">{{ plan.name }}</h2>
                        <p class="mt-1.5 text-sm text-muted-foreground">
                            {{ plan.tagline }}
                        </p>
                        <p class="mt-6 text-3xl font-bold tracking-tight">
                            Custom
                            <span
                                class="text-sm font-medium text-muted-foreground"
                                >quote</span
                            >
                        </p>

                        <Button
                            class="mt-6 w-full cursor-pointer font-semibold"
                            :variant="plan.recommended ? 'default' : 'outline'"
                            @click="choosePlan(plan.key)"
                        >
                            Contact sales
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Button>

                        <p v-if="plan.lead" class="mt-7 text-sm font-semibold">
                            {{ plan.lead }}
                        </p>
                        <ul
                            :class="[
                                'grid gap-3 text-sm',
                                plan.lead ? 'mt-3' : 'mt-7',
                            ]"
                        >
                            <li
                                v-for="feature in plan.features"
                                :key="feature"
                                class="flex gap-2.5"
                            >
                                <Check
                                    class="mt-0.5 size-4 shrink-0 text-success"
                                    aria-hidden="true"
                                />
                                {{ feature }}
                            </li>
                        </ul>
                    </article>
                </div>
            </section>

            <section
                id="contact"
                class="scroll-mt-16 border-t border-border bg-card"
                aria-labelledby="contact-heading"
            >
                <div
                    class="mx-auto grid w-full max-w-6xl gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[1fr_1.3fr]"
                >
                    <div>
                        <h2
                            id="contact-heading"
                            class="text-3xl font-bold tracking-tight text-balance"
                        >
                            Talk to sales
                        </h2>
                        <p class="mt-4 leading-relaxed text-muted-foreground">
                            Tell us about your team and how you book meetings
                            today. We will reply with a quote and a time to walk
                            you through CoreSched.
                        </p>
                        <ul class="mt-8 grid gap-3 text-sm">
                            <li
                                v-for="point in [
                                    'A quote sized to your team',
                                    'A live walkthrough with your use case',
                                    'Help moving over from your current tool',
                                ]"
                                :key="point"
                                class="flex gap-2.5"
                            >
                                <Check
                                    class="mt-0.5 size-4 shrink-0 text-success"
                                    aria-hidden="true"
                                />
                                {{ point }}
                            </li>
                        </ul>
                    </div>

                    <Form
                        v-bind="store.form()"
                        reset-on-success
                        :options="{ preserveScroll: true }"
                        class="rounded-2xl border border-border bg-background p-6 shadow-raised sm:p-8"
                        v-slot="{ errors, processing, wasSuccessful }"
                    >
                        <div
                            v-if="wasSuccessful"
                            class="flex flex-col items-center py-10 text-center"
                            role="status"
                        >
                            <CircleCheck
                                class="size-10 text-success"
                                aria-hidden="true"
                            />
                            <p class="mt-4 text-lg font-semibold">
                                Thanks, we have your message
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Someone from our team will reply by email
                                shortly.
                            </p>
                        </div>

                        <div v-show="!wasSuccessful" class="grid gap-5">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        autocomplete="name"
                                        required
                                    />
                                    <InputError :message="errors.name" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="email">Work email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        autocomplete="email"
                                        required
                                    />
                                    <InputError :message="errors.email" />
                                </div>
                            </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="company">Company</Label>
                                    <Input
                                        id="company"
                                        name="company"
                                        autocomplete="organization"
                                        required
                                    />
                                    <InputError :message="errors.company" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="team_size">Team size</Label>
                                    <select
                                        id="team_size"
                                        name="team_size"
                                        required
                                        :class="selectClass"
                                    >
                                        <option value="" disabled selected>
                                            Choose one
                                        </option>
                                        <option
                                            v-for="size in teamSizes"
                                            :key="size"
                                            :value="size"
                                        >
                                            {{ size }} people
                                        </option>
                                    </select>
                                    <InputError :message="errors.team_size" />
                                </div>
                            </div>

                            <div class="grid gap-2">
                                <Label for="plan">Plan</Label>
                                <select
                                    id="plan"
                                    v-model="selectedPlan"
                                    name="plan"
                                    :class="selectClass"
                                >
                                    <option value="">Not sure yet</option>
                                    <option
                                        v-for="plan in plans"
                                        :key="plan.key"
                                        :value="plan.key"
                                    >
                                        {{ plan.name }}
                                    </option>
                                </select>
                                <InputError :message="errors.plan" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="message">
                                    How do you book meetings today?
                                    <span
                                        class="font-normal text-muted-foreground"
                                        >(optional)</span
                                    >
                                </Label>
                                <Textarea
                                    id="message"
                                    name="message"
                                    rows="4"
                                />
                                <InputError :message="errors.message" />
                            </div>

                            <!-- Honeypot: hidden from people, filled by bots. -->
                            <div class="hidden" aria-hidden="true">
                                <label for="website">Website</label>
                                <input
                                    id="website"
                                    name="website"
                                    tabindex="-1"
                                    autocomplete="off"
                                />
                            </div>

                            <Button
                                size="lg"
                                class="w-full cursor-pointer font-semibold"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" />
                                Contact sales
                            </Button>
                        </div>
                    </Form>
                </div>
            </section>
        </main>

        <MarketingFooter />
    </div>
</template>

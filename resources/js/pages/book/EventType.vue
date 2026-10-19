<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarClock,
    Check,
    Clock,
    Globe,
    MapPin,
    Users,
    X,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import MonthCalendar from '@/components/booking/MonthCalendar.vue';
import InputError from '@/components/InputError.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { locationIcon } from '@/lib/locationIcons';
import { store as storeBooking } from '@/routes/book';
import { store as storeFromLink } from '@/routes/book/once';
import { store as storeReschedule } from '@/routes/booking/reschedule';

type Slot = {
    startsAt: string;
    label: string;
    seatsRemaining: number;
};

type Question = {
    id: number;
    type: string;
    label: string;
    helpText: string | null;
    options: string[];
    isRequired: boolean;
};

type Props = {
    page: { slug: string; name: string; url: string; logoUrl: string | null };
    /** Details the link carried, so the invitee only has to pick a time. */
    prefill?: {
        name: string | null;
        email: string | null;
        phone: string | null;
        reference: string | null;
        zone: string | null;
        /** The host the link pinned the booking to, by email. */
        host: string | null;
    } | null;
    eventType: {
        slug: string;
        name: string;
        description: string | null;
        color: string;
        durationMinutes: number;
        kind: string;
        locationType: string;
        locationLabel: string;
        locationDetail: string | null;
        needsInviteePhone: boolean;
        seatsPerSlot: number;
        hostNames: string[];
        questions: Question[];
    };
    timezone: string;
    /** Today as YYYY-MM-DD in the organizer's timezone. */
    today: string;
    month: string;
    slots?: Record<string, Slot[]>;
    reschedule?: string | null;
    chosenHost?: { id: number; name: string } | null;
    bookingOnBehalf?: boolean;
    /** The link named one host: only their times show and nobody else takes the booking. */
    lockedToHost?: boolean;
    singleUseLink?: string | null;
};

const props = defineProps<Props>();

const selectedDate = ref<string | null>(null);
/** Highlighted but not yet committed — the invitee still has to press Next. */
const pendingSlot = ref<Slot | null>(null);
const selectedSlot = ref<Slot | null>(null);
const guestInput = ref('');
const guestError = ref<string | null>(null);
const detailsHeading = ref<HTMLElement | null>(null);
const timesHeading = ref<HTMLElement | null>(null);

/** Matches the `guests` max rule in StoreBookingRequest. */
const maxGuests = 10;

const isRescheduling = computed(() => Boolean(props.reschedule));
const slotsLoaded = computed(() => props.slots !== undefined);
const availableDates = computed(() => Object.keys(props.slots ?? {}));
/*
 * "Today" is the organizer's today, worked out on the server in the timezone
 * the times are shown in, so a visitor's wrong or foreign clock cannot move it.
 */
const today = computed(() => props.today);
const currentMonth = computed(() => today.value.slice(0, 7));

const slotsForSelectedDate = computed(() =>
    selectedDate.value ? (props.slots?.[selectedDate.value] ?? []) : [],
);

const formatDate = (date: string) =>
    new Date(`${date}T00:00:00Z`).toLocaleDateString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        timeZone: 'UTC',
    });

const selectedDateLabel = computed(() =>
    selectedDate.value ? formatDate(selectedDate.value) : '',
);

const form = useForm({
    starts_at: '',
    timezone: props.timezone,
    name: props.prefill?.name ?? '',
    email: props.prefill?.email ?? '',
    notes: '',
    location_detail: props.prefill?.phone ?? '',
    guests: [] as string[],
    answers: {} as Record<number, string | string[]>,
    reason: '',
    // Never shown or edited: it ties the booking back to whoever sent the
    // invitee here, and goes out again in every webhook about it.
    reference: props.prefill?.reference ?? null,
    // Where the invitee is, as the link named it. The times shown were
    // already narrowed to the hosts covering it; posting it back keeps the
    // booking with one of them.
    zone: props.prefill?.zone ?? null,
    // The host the link pinned the booking to. Posting it back keeps the
    // booking with them: the renter is already theirs in the sending app.
    host: props.prefill?.host ?? null,
});

/*
 * A pick-multiple answer has to start as an array: a checkbox bound to an
 * undefined value sets a boolean instead of collecting the options.
 */
props.eventType.questions
    .filter((question) => question.type === 'multi_select')
    .forEach((question) => {
        form.answers[question.id] = [];
    });

/** The map holds arrays for pick-multiple, so single controls read through. */
const singleAnswer = (id: number) => {
    const answer = form.answers[id];

    return Array.isArray(answer) ? '' : (answer ?? '');
};

const errorFor = (key: string) => (form.errors as Record<string, string>)[key];

const hasErrors = computed(() => Object.keys(form.errors).length > 0);

/** The list-level rule reports on `guests`, a bad address on `guests.N`. */
const guestsServerError = computed(() => {
    const errors = form.errors as Record<string, string>;

    return (
        errors.guests ??
        Object.entries(errors).find(([key]) => key.startsWith('guests.'))?.[1]
    );
});

// Reloading with new query state re-runs the deferred slot lookup server side.
const reload = (query: Record<string, string>) => {
    selectedDate.value = null;
    pendingSlot.value = null;
    selectedSlot.value = null;

    router.reload({
        data: { month: props.month, ...query },
        only: ['slots', 'month', 'timezone'],
    });
};

/*
 * Open on a day rather than an empty picker: today when it still has times,
 * else the month's first open day. Set directly, not through selectDate, so
 * a phone is not scrolled past the calendar before the invitee has looked.
 */
watch(
    () => props.slots,
    (slots) => {
        if (!slots || selectedDate.value) {
            return;
        }

        const openDates = Object.keys(slots).sort();

        selectedDate.value = openDates.includes(today.value)
            ? today.value
            : (openDates[0] ?? null);
    },
    { immediate: true },
);

watch(
    () => props.timezone,
    (value) => {
        form.timezone = value;
    },
);

const selectDate = (date: string) => {
    selectedDate.value = date;
    pendingSlot.value = null;

    /*
     * On a phone the times stack under the calendar, below the fold, so bring
     * them into view or picking a date looks like it did nothing.
     */
    if (!window.matchMedia('(max-width: 639px)').matches) {
        return;
    }

    const reduceMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    void nextTick(() =>
        timesHeading.value?.scrollIntoView({
            behavior: reduceMotion ? 'auto' : 'smooth',
            block: 'start',
        }),
    );
};

const selectSlot = (slot: Slot) => {
    // Re-pressing the highlighted time confirms it, matching the split control.
    if (pendingSlot.value?.startsAt === slot.startsAt) {
        confirmSlot();

        return;
    }

    pendingSlot.value = slot;
};

const confirmSlot = () => {
    if (!pendingSlot.value) {
        return;
    }

    selectedSlot.value = pendingSlot.value;
    form.starts_at = pendingSlot.value.startsAt;

    // Step two replaces step one in place, so move focus to the new heading.
    void nextTick(() => detailsHeading.value?.focus());
};

const backToTimes = () => {
    selectedSlot.value = null;
};

const addGuest = () => {
    const email = guestInput.value.trim();

    if (!email) {
        return;
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        guestError.value = 'Enter a valid email address.';

        return;
    }

    if (form.guests.length >= maxGuests) {
        guestError.value = `You can add up to ${maxGuests} guests.`;

        return;
    }

    if (form.guests.includes(email)) {
        guestError.value = 'That guest has already been added.';

        return;
    }

    form.guests.push(email);
    guestInput.value = '';
    guestError.value = null;
};

const submit = () => {
    if (isRescheduling.value) {
        form.transform((data) => ({
            starts_at: data.starts_at,
            reason: data.reason,
            host_id: props.chosenHost?.id ?? null,
        })).post(storeReschedule(props.reschedule as string).url);

        return;
    }

    if (props.singleUseLink) {
        form.post(storeFromLink(props.singleUseLink).url);

        return;
    }

    form.transform((data) => ({
        ...data,
        host_id: props.chosenHost?.id ?? null,
    })).post(
        storeBooking({ page: props.page.slug, eventType: props.eventType.slug })
            .url,
    );
};
</script>

<template>
    <Head :title="`${eventType.name} · ${page.name}`" />

    <div class="min-h-svh bg-background px-4 py-6 sm:py-12">
        <div class="mx-auto w-full max-w-5xl">
            <div
                class="grid overflow-hidden rounded-2xl border border-border bg-card shadow-raised lg:grid-cols-[21rem_minmax(0,1fr)]"
            >
                <!-- Event details rail. -->
                <aside
                    class="border-b border-border p-6 sm:p-8 lg:border-r lg:border-b-0"
                >
                    <a
                        :href="page.url"
                        class="inline-flex items-center gap-1.5 rounded-md text-sm font-medium text-muted-foreground transition-colors duration-200 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        <ArrowLeft class="size-4" aria-hidden="true" />
                        {{ page.name }}
                    </a>

                    <img
                        v-if="page.logoUrl"
                        :src="page.logoUrl"
                        alt=""
                        class="mt-6 h-12 w-auto max-w-40 object-contain"
                    />

                    <p
                        v-if="eventType.hostNames.length"
                        class="text-sm font-medium text-muted-foreground"
                        :class="page.logoUrl ? 'mt-5' : 'mt-6'"
                    >
                        {{ eventType.hostNames.join(', ') }}
                    </p>
                    <h1
                        class="mt-1 text-2xl font-bold tracking-tight text-balance"
                        data-wrap-anywhere
                    >
                        {{ eventType.name }}
                    </h1>

                    <dl class="mt-6 space-y-3.5 text-sm">
                        <div class="flex items-start gap-3">
                            <dt class="sr-only">Duration</dt>
                            <Clock
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <dd class="font-medium" data-numeric>
                                {{ eventType.durationMinutes }} minutes
                            </dd>
                        </div>
                        <div class="flex items-start gap-3">
                            <dt class="sr-only">Location</dt>
                            <component
                                :is="locationIcon(eventType.locationType)"
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <dd class="font-medium">
                                {{ eventType.locationLabel }}
                            </dd>
                        </div>
                        <div
                            v-if="eventType.locationDetail"
                            class="flex items-start gap-3"
                        >
                            <dt class="sr-only">Location detail</dt>
                            <MapPin
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <dd data-wrap-anywhere>
                                {{ eventType.locationDetail }}
                            </dd>
                        </div>
                        <div
                            v-if="eventType.kind === 'group'"
                            class="flex items-start gap-3"
                        >
                            <dt class="sr-only">Capacity</dt>
                            <Users
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <dd data-numeric>
                                Up to {{ eventType.seatsPerSlot }} attendees
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="eventType.description"
                        class="mt-6 border-t border-border pt-6 text-sm leading-relaxed whitespace-pre-line text-muted-foreground"
                        data-wrap-anywhere
                    >
                        {{ eventType.description }}
                    </p>

                    <div
                        v-if="bookingOnBehalf"
                        class="mt-6 flex gap-3 rounded-lg border border-primary/25 bg-accent p-3.5 text-sm text-accent-foreground"
                        data-test="booking-on-behalf"
                    >
                        <Users
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <p>
                            You are booking for someone else<template
                                v-if="chosenHost"
                                >, hosted by
                                <strong>{{ chosenHost.name }}</strong></template
                            >. Enter their details; they get the confirmation
                            email.
                        </p>
                    </div>

                    <div
                        v-if="lockedToHost && chosenHost"
                        class="mt-6 flex gap-3 rounded-lg border border-primary/25 bg-accent p-3.5 text-sm text-accent-foreground"
                        data-test="locked-to-host"
                    >
                        <Users
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <p>
                            Your call will be with
                            <strong>{{ chosenHost.name }}</strong
                            >, who is already looking after your inquiry.
                        </p>
                    </div>

                    <div
                        v-if="isRescheduling"
                        class="mt-6 flex gap-3 rounded-lg border border-primary/25 bg-accent p-3.5 text-sm text-accent-foreground"
                    >
                        <CalendarClock
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <p>
                            You are picking a new time for an existing
                            booking<template v-if="chosenHost"
                                >, hosted by
                                <strong data-test="reschedule-host-name">{{
                                    chosenHost.name
                                }}</strong></template
                            >.
                        </p>
                    </div>
                </aside>

                <div class="p-6 sm:p-8">
                    <!-- Step one: pick a day, then a time. -->
                    <div v-if="!selectedSlot">
                        <h2 class="text-lg font-bold tracking-tight">
                            Select a Date &amp; Time
                        </h2>

                        <div
                            class="mt-6 grid gap-8 sm:grid-cols-[minmax(0,1fr)_13rem]"
                        >
                            <div>
                                <MonthCalendar
                                    :month="month"
                                    :available-dates="availableDates"
                                    :selected-date="selectedDate"
                                    :loading="!slotsLoaded"
                                    :min-month="currentMonth"
                                    :today="today"
                                    @update:month="
                                        (value) => reload({ month: value })
                                    "
                                    @select="selectDate"
                                />

                                <!--
                                  Times are shown in the organizer's timezone
                                  and said so, rather than offering the invitee
                                  a picker: one answer to "what time is this?".
                                -->
                                <p
                                    class="mt-8 flex items-center gap-1.5 text-sm text-muted-foreground"
                                    data-test="booking-timezone"
                                >
                                    <Globe
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Times shown in {{ timezone }}
                                </p>
                            </div>

                            <div class="min-w-0">
                                <h3
                                    ref="timesHeading"
                                    class="mb-4 scroll-mt-4 text-sm font-semibold"
                                    aria-live="polite"
                                >
                                    {{
                                        selectedDate
                                            ? selectedDateLabel
                                            : 'Select a time'
                                    }}
                                </h3>

                                <div v-if="!slotsLoaded" class="space-y-2">
                                    <Skeleton
                                        v-for="index in 6"
                                        :key="index"
                                        class="h-11 w-full animate-pulse rounded-lg"
                                    />
                                    <span class="sr-only">Loading times</span>
                                </div>

                                <div
                                    v-else-if="!selectedDate"
                                    class="rounded-lg border border-dashed border-border px-4 py-8 text-center"
                                >
                                    <CalendarClock
                                        class="mx-auto size-5 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <p
                                        class="mt-2 text-sm text-muted-foreground"
                                    >
                                        Pick a highlighted date to see the open
                                        times.
                                    </p>
                                </div>

                                <div
                                    v-else-if="
                                        slotsForSelectedDate.length === 0
                                    "
                                    class="rounded-lg border border-dashed border-border px-4 py-8 text-center"
                                >
                                    <X
                                        class="mx-auto size-5 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <p
                                        class="mt-2 text-sm text-muted-foreground"
                                    >
                                        No times left on this date. Try another
                                        highlighted day.
                                    </p>
                                </div>

                                <div
                                    v-else
                                    class="grid max-h-[26rem] gap-2 overflow-y-auto pr-1"
                                >
                                    <!--
                                      Calendly's split control: the first press
                                      highlights the time, the second (or the
                                      Next button beside it) commits it.
                                    -->
                                    <div
                                        v-for="slot in slotsForSelectedDate"
                                        :key="slot.startsAt"
                                        class="flex gap-2"
                                    >
                                        <button
                                            type="button"
                                            :aria-pressed="
                                                pendingSlot?.startsAt ===
                                                slot.startsAt
                                            "
                                            :data-test="`slot-${slot.startsAt}`"
                                            :class="[
                                                'h-11 flex-1 cursor-pointer rounded-lg border text-sm font-semibold transition-colors duration-200',
                                                'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none',
                                                pendingSlot?.startsAt ===
                                                slot.startsAt
                                                    ? 'border-foreground bg-foreground text-background'
                                                    : 'border-primary/40 text-primary hover:border-primary hover:bg-accent',
                                            ]"
                                            @click="selectSlot(slot)"
                                        >
                                            <span data-numeric>{{
                                                slot.label
                                            }}</span>
                                            <span
                                                v-if="
                                                    eventType.kind === 'group'
                                                "
                                                class="ml-2 text-xs font-normal opacity-80"
                                                data-numeric
                                            >
                                                {{ slot.seatsRemaining }} left
                                            </span>
                                        </button>
                                        <Button
                                            v-if="
                                                pendingSlot?.startsAt ===
                                                slot.startsAt
                                            "
                                            type="button"
                                            class="h-11 flex-1 cursor-pointer font-semibold"
                                            data-test="slot-next"
                                            @click="confirmSlot"
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step two: say who you are. -->
                    <form v-else class="max-w-lg" @submit.prevent="submit">
                        <button
                            type="button"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-md text-sm font-medium text-muted-foreground transition-colors duration-200 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                            @click="backToTimes"
                        >
                            <ArrowLeft class="size-4" aria-hidden="true" />
                            Change time
                        </button>

                        <h2
                            ref="detailsHeading"
                            tabindex="-1"
                            class="mt-4 text-lg font-bold tracking-tight focus-visible:outline-none"
                        >
                            {{
                                isRescheduling
                                    ? 'Confirm new time'
                                    : 'Enter Details'
                            }}
                        </h2>

                        <div
                            class="mt-4 flex items-start gap-3 rounded-lg border border-border bg-muted px-4 py-3 text-sm"
                        >
                            <CalendarClock
                                class="mt-0.5 size-4 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <div>
                                <p class="font-semibold" data-numeric>
                                    {{ selectedDateLabel }} at
                                    {{ selectedSlot.label }}
                                </p>
                                <p class="text-muted-foreground">
                                    {{ eventType.durationMinutes }} min ·
                                    {{ timezone }}
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="hasErrors"
                            role="alert"
                            class="mt-4 rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                        >
                            Something needs fixing before we can book this time.
                            Check the highlighted fields below.
                        </div>

                        <InputError
                            class="mt-2"
                            :message="form.errors.starts_at"
                        />
                        <InputError
                            class="mt-2"
                            :message="form.errors.timezone"
                        />

                        <div v-if="isRescheduling" class="mt-6 grid gap-2">
                            <Label for="reason">Reason for rescheduling</Label>
                            <Textarea
                                id="reason"
                                v-model="form.reason"
                                maxlength="500"
                                data-test="reschedule-reason"
                            />
                            <InputError :message="form.errors.reason" />
                        </div>

                        <div v-if="!isRescheduling" class="mt-6 space-y-5">
                            <div class="grid gap-2">
                                <Label for="name">
                                    Name
                                    <span
                                        class="text-destructive"
                                        aria-hidden="true"
                                        >*</span
                                    >
                                </Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    autocomplete="name"
                                    data-test="booking-name"
                                    required
                                    :aria-invalid="Boolean(form.errors.name)"
                                />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="email">
                                    Email
                                    <span
                                        class="text-destructive"
                                        aria-hidden="true"
                                        >*</span
                                    >
                                </Label>
                                <Input
                                    id="email"
                                    v-model="form.email"
                                    type="email"
                                    autocomplete="email"
                                    inputmode="email"
                                    data-test="booking-email"
                                    required
                                    :aria-invalid="Boolean(form.errors.email)"
                                />
                                <InputError :message="form.errors.email" />
                            </div>

                            <div
                                v-if="eventType.needsInviteePhone"
                                class="grid gap-2"
                            >
                                <Label for="location_detail">
                                    Phone number
                                    <span
                                        class="text-destructive"
                                        aria-hidden="true"
                                        >*</span
                                    >
                                </Label>
                                <PhoneInput
                                    id="location_detail"
                                    v-model="form.location_detail"
                                    data-test="booking-phone"
                                    required
                                    aria-describedby="location_detail-help"
                                    :aria-invalid="
                                        Boolean(form.errors.location_detail)
                                    "
                                />
                                <p
                                    id="location_detail-help"
                                    class="text-xs text-muted-foreground"
                                >
                                    We will call you on this number at the
                                    scheduled time.
                                </p>
                                <InputError
                                    :message="form.errors.location_detail"
                                />
                            </div>

                            <div
                                v-for="question in eventType.questions"
                                :key="question.id"
                                class="grid gap-2"
                            >
                                <!--
                                  Radios and checkboxes have no single control
                                  to point at; their group is labelled by id.
                                -->
                                <Label
                                    :id="`question-${question.id}-label`"
                                    :for="
                                        question.type === 'yes_no' ||
                                        question.type === 'multi_select'
                                            ? undefined
                                            : `question-${question.id}`
                                    "
                                >
                                    {{ question.label }}
                                    <span
                                        v-if="question.isRequired"
                                        class="text-destructive"
                                        aria-hidden="true"
                                        >*</span
                                    >
                                </Label>
                                <Textarea
                                    v-if="question.type === 'textarea'"
                                    :id="`question-${question.id}`"
                                    :model-value="singleAnswer(question.id)"
                                    @update:model-value="
                                        (value) =>
                                            (form.answers[question.id] =
                                                String(value))
                                    "
                                    :required="question.isRequired"
                                    :aria-describedby="
                                        question.helpText
                                            ? `question-${question.id}-help`
                                            : undefined
                                    "
                                />
                                <!--
                                  Radios for a yes or no, checkboxes for a pick
                                  multiple: both used to fall through to a bare
                                  text box, which asked the invitee to guess.
                                -->
                                <div
                                    v-else-if="question.type === 'yes_no'"
                                    class="flex gap-4"
                                    role="radiogroup"
                                    :aria-labelledby="`question-${question.id}-label`"
                                    :aria-describedby="
                                        question.helpText
                                            ? `question-${question.id}-help`
                                            : undefined
                                    "
                                >
                                    <label
                                        v-for="option in ['Yes', 'No']"
                                        :key="option"
                                        class="flex items-center gap-2 text-sm"
                                    >
                                        <input
                                            v-model="form.answers[question.id]"
                                            type="radio"
                                            :name="`question-${question.id}`"
                                            :value="option"
                                            :required="question.isRequired"
                                            class="size-4 accent-primary"
                                        />
                                        {{ option }}
                                    </label>
                                </div>
                                <div
                                    v-else-if="question.type === 'multi_select'"
                                    class="grid gap-2"
                                    role="group"
                                    :aria-labelledby="`question-${question.id}-label`"
                                    :aria-describedby="
                                        question.helpText
                                            ? `question-${question.id}-help`
                                            : undefined
                                    "
                                >
                                    <label
                                        v-for="option in question.options"
                                        :key="option"
                                        class="flex items-center gap-2 text-sm"
                                    >
                                        <input
                                            v-model="form.answers[question.id]"
                                            type="checkbox"
                                            :value="option"
                                            class="size-4 accent-primary"
                                        />
                                        {{ option }}
                                    </label>
                                </div>
                                <Select
                                    :model-value="singleAnswer(question.id)"
                                    v-else-if="question.type === 'select'"
                                    @update:model-value="
                                        (value) =>
                                            (form.answers[question.id] =
                                                String(value))
                                    "
                                >
                                    <SelectTrigger
                                        :id="`question-${question.id}`"
                                        class="w-full cursor-pointer"
                                        :aria-describedby="
                                            question.helpText
                                                ? `question-${question.id}-help`
                                                : undefined
                                        "
                                    >
                                        <SelectValue placeholder="Choose one" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in question.options"
                                            :key="option"
                                            :value="option"
                                        >
                                            {{ option }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <PhoneInput
                                    v-else-if="question.type === 'phone'"
                                    :id="`question-${question.id}`"
                                    :model-value="singleAnswer(question.id)"
                                    @update:model-value="
                                        (value) =>
                                            (form.answers[question.id] = value)
                                    "
                                    :required="question.isRequired"
                                    :aria-describedby="
                                        question.helpText
                                            ? `question-${question.id}-help`
                                            : undefined
                                    "
                                />
                                <Input
                                    v-else
                                    :id="`question-${question.id}`"
                                    type="text"
                                    :model-value="singleAnswer(question.id)"
                                    @update:model-value="
                                        (value) =>
                                            (form.answers[question.id] =
                                                String(value))
                                    "
                                    :required="question.isRequired"
                                    :aria-describedby="
                                        question.helpText
                                            ? `question-${question.id}-help`
                                            : undefined
                                    "
                                />
                                <p
                                    v-if="question.helpText"
                                    :id="`question-${question.id}-help`"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ question.helpText }}
                                </p>
                                <InputError
                                    :message="
                                        errorFor(`answers.${question.id}`)
                                    "
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="notes">
                                    Anything we should know beforehand?
                                </Label>
                                <Textarea
                                    id="notes"
                                    v-model="form.notes"
                                    rows="3"
                                    data-test="booking-notes"
                                />
                                <InputError :message="form.errors.notes" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="guest">Add guests</Label>
                                <div class="flex gap-2">
                                    <Input
                                        id="guest"
                                        v-model="guestInput"
                                        type="email"
                                        inputmode="email"
                                        placeholder="colleague@example.com"
                                        aria-describedby="guest-help"
                                        :aria-invalid="
                                            Boolean(
                                                guestError || guestsServerError,
                                            )
                                        "
                                        @keydown.enter.prevent="addGuest"
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        class="cursor-pointer"
                                        @click="addGuest"
                                    >
                                        Add
                                    </Button>
                                </div>
                                <p
                                    id="guest-help"
                                    class="text-xs text-muted-foreground"
                                >
                                    Guests get the same invite and calendar
                                    updates.
                                </p>
                                <InputError
                                    :message="guestError ?? guestsServerError"
                                />

                                <ul
                                    v-if="form.guests.length"
                                    class="mt-1 flex flex-wrap gap-2"
                                >
                                    <li
                                        v-for="(guest, index) in form.guests"
                                        :key="guest"
                                        class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-border bg-muted py-1 pr-1 pl-3 text-sm"
                                    >
                                        <span
                                            class="min-w-0 truncate"
                                            data-wrap-anywhere
                                            >{{ guest }}</span
                                        >
                                        <button
                                            type="button"
                                            class="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors duration-200 hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            :aria-label="`Remove guest ${guest}`"
                                            @click="
                                                form.guests.splice(index, 1)
                                            "
                                        >
                                            <X
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <Button
                            type="submit"
                            size="lg"
                            class="mt-8 w-full cursor-pointer font-semibold sm:w-auto"
                            :disabled="form.processing"
                            data-test="booking-submit"
                        >
                            <Check
                                v-if="!form.processing"
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{
                                form.processing
                                    ? 'Scheduling…'
                                    : isRescheduling
                                      ? 'Confirm new time'
                                      : 'Schedule event'
                            }}
                        </Button>
                    </form>
                </div>
            </div>

            <p class="mt-6 text-center text-xs text-muted-foreground">
                Powered by {{ page.name }}
            </p>
        </div>
    </div>
</template>

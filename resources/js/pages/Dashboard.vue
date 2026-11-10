<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarClock,
    CalendarDays,
    Check,
    Clock,
    Copy,
    ExternalLink,
    History,
    LayoutList,
    Plus,
    QrCode,
    UserPlus,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import BookingQrCodeDialog from '@/components/scheduling/BookingQrCodeDialog.vue';
import ScopePicker from '@/components/scheduling/ScopePicker.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useCurrentTeam } from '@/composables/useCurrentTeam';
import { dashboard } from '@/routes';
import { index as activityIndex } from '@/routes/activity';
import { index as availabilityIndex } from '@/routes/availability';
import { index as meetingsIndex } from '@/routes/meetings';
import { index as schedulingIndex } from '@/routes/scheduling';
import { edit as teamSettings } from '@/routes/teams';
import type { DashboardInvitation } from '@/types';

type ScopeOption = { value: string; label: string; initial: string | null };

type UpcomingMeeting = {
    uid: string;
    name: string;
    hostName: string | null;
    eventTypeName: string | null;
    color: string | null;
    startsAt: string;
    dayLabel: string;
    timeLabel: string;
    endTimeLabel: string;
    durationMinutes: number;
    status: string;
    statusLabel: string;
    meetingUrl: string | null;
    isToday: boolean;
};

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    greetingName: string;
    bookingUrl: string | null;
    scope: string;
    scopeOptions: {
        primary: ScopeOption[];
        groups: ScopeOption[];
        users: ScopeOption[];
    };
    showsOthers: boolean;
    stats?: { upcoming: number; thisWeek: number; eventTypes: number };
    upcoming?: UpcomingMeeting[];
}>();

const { teamSlug } = useCurrentTeam();
const page = usePage();

setLayoutProps({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: teamSlug.value ? dashboard(teamSlug.value) : '/',
        },
    ],
});

/** Only owners and admins get more than "My CoreSched" to choose from. */
const canChooseScope = computed(() => props.scopeOptions.primary.length > 1);

const changeScope = (scope: string) => {
    if (!teamSlug.value) {
        return;
    }

    router.get(
        dashboard(teamSlug.value).url,
        { scope },
        { preserveScroll: true, preserveState: true },
    );
};

const greeting = computed(() => {
    const hour = new Date().getHours();

    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 18 ? 'Good afternoon' : 'Good evening';
});

const heroMeeting = computed(() => props.upcoming?.[0]);
const todayMeetings = computed(() =>
    (props.upcoming ?? []).filter((meeting) => meeting.isToday),
);

/** A ticking clock so the countdown stays honest while the tab sits open. */
const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 60_000);
});

onUnmounted(() => clearInterval(clock));

const countdown = computed(() => {
    const hero = heroMeeting.value;

    if (!hero) {
        return '';
    }

    const minutes = Math.round(
        (Date.parse(hero.startsAt) - now.value) / 60_000,
    );

    if (minutes < 1) {
        return 'starting now';
    }

    if (minutes < 60) {
        return `in ${minutes} min`;
    }

    if (minutes < 60 * 24) {
        return `in ${Math.round(minutes / 60)} h`;
    }

    const days = Math.round(minutes / (60 * 24));

    return days === 1 ? 'in 1 day' : `in ${days} days`;
});

const copied = ref(false);

const copyBookingLink = async () => {
    if (!props.bookingUrl) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.bookingUrl);
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Clipboard access can be denied; the link stays visible and selectable.
        copied.value = false;
    }
};

const showingQrCode = ref(false);

const displayUrl = computed(
    () => props.bookingUrl?.replace(/^https?:\/\//, '') ?? '',
);

const nextContext = computed(() => {
    if (props.upcoming === undefined) {
        return undefined;
    }

    const hero = heroMeeting.value;

    if (!hero) {
        return 'Nothing scheduled';
    }

    return `Next: ${hero.isToday ? 'Today' : hero.dayLabel} at ${hero.timeLabel}`;
});

const statCards = computed(() => [
    {
        key: 'upcoming',
        label: 'Upcoming meetings',
        value: props.stats?.upcoming,
        context: nextContext.value,
        icon: CalendarClock,
        href: teamSlug.value ? meetingsIndex(teamSlug.value).url : undefined,
    },
    {
        key: 'thisWeek',
        label: 'Booked this week',
        value: props.stats?.thisWeek,
        context: 'Booked since Monday',
        icon: CalendarDays,
        href: teamSlug.value ? meetingsIndex(teamSlug.value).url : undefined,
    },
    {
        key: 'eventTypes',
        label: 'Active event types',
        value: props.stats?.eventTypes,
        context: 'Currently active',
        icon: LayoutList,
        href: teamSlug.value ? schedulingIndex(teamSlug.value).url : undefined,
    },
]);

const quickActions = computed(() =>
    teamSlug.value
        ? [
              {
                  key: 'event-type',
                  label: 'New event type',
                  href: schedulingIndex(teamSlug.value),
                  icon: Plus,
              },
              {
                  key: 'availability',
                  label: 'Edit availability',
                  href: availabilityIndex(teamSlug.value),
                  icon: Clock,
              },
              {
                  key: 'invite',
                  label: 'Invite a member',
                  href: teamSettings(teamSlug.value),
                  icon: UserPlus,
              },
              ...(page.props.canViewActivity
                  ? [
                        {
                            key: 'activity',
                            label: 'View activity',
                            href: activityIndex(teamSlug.value),
                            icon: History,
                        },
                    ]
                  : []),
          ]
        : [],
);
</script>

<template>
    <Head title="Dashboard" />

    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            :description="
                showsOthers
                    ? 'Here\'s what\'s happening across your organization today.'
                    : 'Here\'s what\'s happening with your schedule today.'
            "
        >
            <template #title>
                {{ greeting }}, {{ greetingName }}
                <span aria-hidden="true">👋</span>
            </template>
            <template #actions>
                <Button
                    v-if="teamSlug"
                    class="cursor-pointer font-medium"
                    as-child
                >
                    <Link :href="schedulingIndex(teamSlug)">
                        <Plus class="size-4" aria-hidden="true" />
                        Create event type
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div v-if="canChooseScope" class="flex flex-wrap items-center gap-2">
            <ScopePicker
                :model-value="scope"
                :options="scopeOptions"
                @update:model-value="changeScope"
            />
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Main column: what's next, then the rest of today. -->
            <div class="flex flex-col gap-6 lg:col-span-2">
                <!-- Next meeting -->
                <section aria-labelledby="next-meeting-heading">
                    <h2 id="next-meeting-heading" class="sr-only">
                        Next meeting
                    </h2>

                    <div v-if="upcoming === undefined">
                        <Skeleton class="h-48 w-full rounded-xl" />
                        <span class="sr-only">Loading your next meeting</span>
                    </div>

                    <div
                        v-else-if="heroMeeting"
                        class="relative overflow-hidden rounded-xl border border-border bg-card p-6 shadow-flat"
                    >
                        <span
                            class="absolute inset-y-0 left-0 w-1.5 bg-primary"
                            :style="
                                heroMeeting.color
                                    ? { backgroundColor: heroMeeting.color }
                                    : undefined
                            "
                            aria-hidden="true"
                        />
                        <div class="flex flex-wrap items-center gap-2 pl-2">
                            <Badge variant="secondary">
                                {{
                                    heroMeeting.isToday
                                        ? 'Today'
                                        : heroMeeting.dayLabel
                                }}
                            </Badge>
                            <Badge
                                v-if="heroMeeting.status === 'pending'"
                                variant="secondary"
                            >
                                <Clock class="size-3" aria-hidden="true" />
                                Pending
                            </Badge>
                            <span
                                class="text-sm text-muted-foreground"
                                data-numeric
                            >
                                {{ countdown }}
                            </span>
                        </div>
                        <p
                            class="mt-3 pl-2 text-3xl font-semibold tracking-tight"
                            data-numeric
                        >
                            {{ heroMeeting.timeLabel }}
                            <span
                                class="text-lg font-normal text-muted-foreground"
                            >
                                – {{ heroMeeting.endTimeLabel }}
                            </span>
                        </p>
                        <p
                            class="mt-2 pl-2 text-lg font-semibold"
                            data-wrap-anywhere
                        >
                            {{ heroMeeting.eventTypeName }}
                        </p>
                        <p class="mt-1 pl-2 text-sm text-muted-foreground">
                            with {{ heroMeeting.name }} ·
                            {{ heroMeeting.durationMinutes }} min
                            <template
                                v-if="showsOthers && heroMeeting.hostName"
                            >
                                · Host {{ heroMeeting.hostName }}
                            </template>
                        </p>
                        <div class="mt-5 flex flex-wrap gap-2 pl-2">
                            <Button
                                v-if="heroMeeting.meetingUrl"
                                class="cursor-pointer font-medium"
                                as-child
                            >
                                <a
                                    :href="heroMeeting.meetingUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Join meeting
                                    <ExternalLink
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </a>
                            </Button>
                            <Button
                                v-if="teamSlug"
                                variant="outline"
                                class="cursor-pointer bg-card font-medium"
                                as-child
                            >
                                <Link :href="meetingsIndex(teamSlug)">
                                    View meeting
                                </Link>
                            </Button>
                        </div>
                    </div>

                    <div
                        v-else
                        class="rounded-xl border border-dashed border-border p-10 text-center"
                    >
                        <CalendarClock
                            class="mx-auto size-6 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p class="mt-3 font-medium">No upcoming meetings</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Your schedule is clear. Share your booking link to
                            get the first one.
                        </p>
                        <Button
                            v-if="teamSlug"
                            class="mt-5 cursor-pointer font-semibold"
                            as-child
                        >
                            <Link :href="schedulingIndex(teamSlug)">
                                Set up an event type
                            </Link>
                        </Button>
                    </div>
                </section>

                <!-- Today's schedule -->
                <section
                    class="rounded-xl border border-border bg-card shadow-flat"
                    aria-labelledby="today-heading"
                >
                    <div
                        class="flex items-center justify-between gap-3 border-b border-border p-5"
                    >
                        <h2 id="today-heading" class="text-base font-semibold">
                            Today's schedule
                        </h2>
                        <Button
                            v-if="teamSlug"
                            variant="ghost"
                            size="sm"
                            class="cursor-pointer font-medium"
                            as-child
                        >
                            <Link :href="meetingsIndex(teamSlug)">
                                View all
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Link>
                        </Button>
                    </div>

                    <div v-if="upcoming === undefined" class="space-y-3 p-5">
                        <Skeleton
                            v-for="index in 3"
                            :key="index"
                            class="h-16 w-full rounded-lg"
                        />
                        <span class="sr-only">Loading today's schedule</span>
                    </div>

                    <ul
                        v-else-if="todayMeetings.length"
                        class="flex flex-col gap-3 p-5"
                    >
                        <li
                            v-for="meeting in todayMeetings"
                            :key="meeting.uid"
                            :class="[
                                'flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border p-3 transition-colors',
                                meeting.uid === heroMeeting?.uid
                                    ? 'border-primary/40 bg-accent/30'
                                    : 'bg-background hover:bg-accent/30',
                            ]"
                        >
                            <span class="w-20 shrink-0">
                                <span
                                    class="block text-sm font-medium"
                                    data-numeric
                                >
                                    {{ meeting.timeLabel }}
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    data-numeric
                                >
                                    {{ meeting.endTimeLabel }}
                                </span>
                            </span>
                            <span
                                class="size-2.5 shrink-0 rounded-full bg-primary"
                                :style="
                                    meeting.color
                                        ? { backgroundColor: meeting.color }
                                        : undefined
                                "
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-semibold"
                                    data-wrap-anywhere
                                >
                                    {{ meeting.eventTypeName }}
                                </span>
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ meeting.name }} ·
                                    {{ meeting.durationMinutes }} min
                                    <template
                                        v-if="showsOthers && meeting.hostName"
                                    >
                                        · {{ meeting.hostName }}
                                    </template>
                                </span>
                            </span>
                            <Badge
                                v-if="meeting.status === 'pending'"
                                variant="secondary"
                            >
                                <Clock class="size-3" aria-hidden="true" />
                                Pending
                            </Badge>
                            <Badge
                                v-else-if="meeting.uid === heroMeeting?.uid"
                                variant="secondary"
                            >
                                Up next
                            </Badge>
                        </li>
                    </ul>

                    <div
                        v-else
                        class="m-5 rounded-lg border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
                    >
                        No more meetings today. Enjoy the free time.
                    </div>
                </section>
            </div>

            <!-- Rail: stats, quick actions, booking link. -->
            <div class="flex flex-col gap-6">
                <section aria-labelledby="stats-heading">
                    <h2 id="stats-heading" class="sr-only">At a glance</h2>
                    <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-1">
                        <component
                            :is="card.href ? Link : 'div'"
                            v-for="card in statCards"
                            :key="card.key"
                            :href="card.href"
                            class="flex items-center gap-4 rounded-xl border border-border bg-card p-5 shadow-flat transition-colors duration-200"
                            :class="
                                card.href
                                    ? 'cursor-pointer hover:border-primary/40 hover:bg-accent/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none'
                                    : ''
                            "
                        >
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground"
                            >
                                <component
                                    :is="card.icon"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block text-sm font-medium text-muted-foreground"
                                >
                                    {{ card.label }}
                                </span>
                                <Skeleton
                                    v-if="card.value === undefined"
                                    class="mt-1 h-8 w-12"
                                />
                                <span
                                    v-else
                                    class="block text-3xl font-semibold tracking-tight"
                                    data-numeric
                                >
                                    {{ card.value }}
                                </span>
                                <Skeleton
                                    v-if="card.context === undefined"
                                    class="mt-1 h-3 w-24"
                                />
                                <span
                                    v-else
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ card.context }}
                                </span>
                            </span>
                        </component>
                    </div>
                </section>

                <section
                    v-if="quickActions.length"
                    class="rounded-xl border border-border bg-card p-5 shadow-flat"
                    aria-labelledby="quick-actions-heading"
                >
                    <h2
                        id="quick-actions-heading"
                        class="text-base font-semibold"
                    >
                        Quick actions
                    </h2>
                    <nav
                        class="mt-3 flex flex-col gap-1"
                        aria-label="Quick actions"
                    >
                        <Link
                            v-for="action in quickActions"
                            :key="action.key"
                            :href="action.href"
                            class="group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors hover:bg-accent/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <component
                                :is="action.icon"
                                class="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-foreground"
                                aria-hidden="true"
                            />
                            <span>{{ action.label }}</span>
                            <ArrowRight
                                class="ml-auto size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                                aria-hidden="true"
                            />
                        </Link>
                    </nav>
                </section>

                <section
                    v-if="bookingUrl"
                    class="rounded-xl border border-border bg-card p-5 shadow-flat"
                    aria-labelledby="share-heading"
                >
                    <h2
                        id="share-heading"
                        class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Your booking page
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Anyone with this link can book time with you.
                    </p>
                    <code
                        class="mt-3 block rounded-lg border border-border bg-muted/50 px-3 py-2 font-mono text-sm"
                        data-wrap-anywhere
                    >
                        {{ displayUrl }}
                    </code>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            class="cursor-pointer bg-card font-medium"
                            @click="copyBookingLink"
                        >
                            <Check
                                v-if="copied"
                                class="size-4 text-success"
                                aria-hidden="true"
                            />
                            <Copy v-else class="size-4" aria-hidden="true" />
                            {{ copied ? 'Copied!' : 'Copy' }}
                        </Button>
                        <Button
                            variant="outline"
                            class="cursor-pointer bg-card font-medium"
                            data-test="booking-page-qr-code"
                            @click="showingQrCode = true"
                        >
                            <QrCode class="size-4" aria-hidden="true" />
                            QR code
                        </Button>
                        <Button
                            variant="ghost"
                            class="cursor-pointer font-medium"
                            as-child
                        >
                            <a
                                :href="bookingUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Preview booking page
                                <ExternalLink
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </a>
                        </Button>
                    </div>
                    <!-- Announced without moving focus when the copy succeeds. -->
                    <p aria-live="polite" class="sr-only">
                        {{ copied ? 'Booking link copied to clipboard' : '' }}
                    </p>
                    <BookingQrCodeDialog
                        :url="showingQrCode ? bookingUrl : null"
                        name="your booking page"
                        @close="showingQrCode = false"
                    />
                </section>
            </div>
        </div>
    </div>
</template>

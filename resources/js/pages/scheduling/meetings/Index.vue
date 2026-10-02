<script setup lang="ts">
import { Head, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import {
    Check,
    CircleCheck,
    Clock,
    Plane,
    Plus,
    RotateCcw,
    Search,
    SlidersHorizontal,
    UserX,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import MeetingDetailPanel from '@/components/scheduling/MeetingDetailPanel.vue';
import ScopePicker from '@/components/scheduling/ScopePicker.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useCurrentTeam } from '@/composables/useCurrentTeam';
import { decline, destroy, handOffAll, index } from '@/routes/meetings';

type Meeting = {
    uid: string;
    status: string;
    statusLabel: string;
    startsAt: string;
    endsAt: string;
    dateKey: string;
    dateLabel: string;
    dayLabel: string;
    dayDateLabel: string;
    isToday: boolean;
    fullDateLabel: string;
    timeLabel: string;
    timeWithZone: string;
    inviteeInitials: string;
    inviteePhone: string | null;
    hostNotes: string | null;
    rescheduleUrl: string;
    reassignableHosts: Array<{ id: number; name: string }>;
    hostIds: number[];
    inviteeName: string;
    inviteeEmail: string;
    inviteeTimezone: string;
    notes: string | null;
    meetingUrl: string | null;
    locationDetail: string | null;
    locationLabel: string;
    cancellationReason: string | null;
    eventTypeName: string;
    color: string;
    hostNames: string[];
    guests: string[];
    answers: Array<{ label: string; answer: string | null }>;
    timeline: Array<{
        label: string;
        detail: string | null;
        at: string;
        atLabel: string;
    }>;
    canCancel: boolean;
    canApprove: boolean;
    noShow: boolean;
    canMarkNoShow: boolean;
    awayHostNames: string[];
};

type Option = { value: string; label: string; initial: string | null };

type Props = {
    meetings: Meeting[];
    total: number;
    hostAwayCount: number;
    page: number;
    hasMore: boolean;
    filter: string;
    ranges: Array<{ value: string; label: string }>;
    status: string;
    scope: string;
    scopeOptions: { primary: Option[]; groups: Option[]; users: Option[] };
    search: string;
    eventTypeId: number | null;
    eventTypeOptions: Array<{ value: number; label: string }>;
    viewerTimezone: string;
    bookableEventTypes: Array<{
        id: number;
        name: string;
        url: string;
        hosts: Array<{ id: number; name: string }>;
    }>;
};

const props = defineProps<Props>();

const { teamSlug } = useCurrentTeam();

const search = ref(props.search);
const selected = ref<Meeting | null>(null);
const canceling = ref<Meeting | null>(null);
const cancelForm = useForm({ reason: '' });
const declining = ref<Meeting | null>(null);
const declineForm = useForm({ reason: '' });
const rescheduling = ref<Meeting | null>(null);
const rescheduleHostId = ref<string>('');

/**
 * "In progress" is answered against the reader's own clock, which keeps
 * ticking while the page sits open, so the badge appears and clears on its
 * own rather than only on a reload.
 */
const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | null = null;
let searchTimer: ReturnType<typeof setTimeout> | undefined;

onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 30000);
});

onUnmounted(() => {
    if (clock) {
        clearInterval(clock);
    }

    // A pending search would otherwise pull the reader back from the next page.
    clearTimeout(searchTimer);
});

const isInProgress = (meeting: Meeting) =>
    meeting.status === 'confirmed' &&
    new Date(meeting.startsAt).getTime() <= now.value &&
    now.value < new Date(meeting.endsAt).getTime();

/** Whether the meeting's end has passed, whatever became of it. */
const isPast = (meeting: Meeting) =>
    new Date(meeting.endsAt).getTime() <= now.value;

/** A confirmed meeting whose end has passed: it took place. */
const isDone = (meeting: Meeting) =>
    meeting.status === 'confirmed' && isPast(meeting);

/**
 * The detail panel and these dialogs are each modal layers, and two open at
 * once fight over the focus trap, which leaves the dialog's fields untypeable.
 * Opening a dialog hands off from the panel; dismissing it hands back.
 */
const askToCancel = () => {
    canceling.value = selected.value;
    selected.value = null;
};

const askToDecline = () => {
    declining.value = selected.value;
    selected.value = null;
};

const askToReschedule = () => {
    rescheduling.value = selected.value;
    rescheduleHostId.value = String(selected.value?.hostIds[0] ?? '');
    selected.value = null;
};

const dismissReschedule = () => {
    selected.value = rescheduling.value;
    rescheduling.value = null;
};

/**
 * Picking a time happens on the booking page, like the invitee's own
 * reschedule link, but with its times narrowed to the chosen host.
 */
const continueReschedule = () => {
    if (!rescheduling.value) {
        return;
    }

    const url = new URL(rescheduling.value.rescheduleUrl, window.location.href);
    url.searchParams.set('host', rescheduleHostId.value);

    window.open(url.toString(), '_blank');

    rescheduling.value = null;
};

const dismissCancel = () => {
    selected.value = canceling.value;
    canceling.value = null;
    cancelForm.reset();
};

const dismissDecline = () => {
    selected.value = declining.value;
    declining.value = null;
    declineForm.reset();
};

/** Meetings under their day heading, the way the list reads down the page. */
const days = computed(() => {
    const sections = new Map<
        string,
        {
            label: string;
            day: string;
            date: string;
            isToday: boolean;
            meetings: Meeting[];
        }
    >();

    for (const meeting of props.meetings) {
        const section = sections.get(meeting.dateKey) ?? {
            label: meeting.dateLabel,
            day: meeting.dayLabel,
            date: meeting.dayDateLabel,
            isToday: meeting.isToday,
            meetings: [],
        };

        section.meetings.push(meeting);
        sections.set(meeting.dateKey, section);
    }

    return [...sections.values()];
});

const reload = (data: Record<string, string | number | null>) => {
    router.get(
        index(teamSlug.value).url,
        {
            scope: props.scope,
            filter: props.filter,
            search: search.value || null,
            event_type: props.eventTypeId,
            status: props.status,
            ...data,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reload({ page: 1 }), 300);
});

const loadMore = () => {
    router.reload({
        data: {
            scope: props.scope,
            filter: props.filter,
            search: search.value || null,
            event_type: props.eventTypeId,
            status: props.status,
            page: props.page + 1,
        },
        only: ['meetings', 'page', 'hasMore'],
    });
};

const creating = ref(false);
const newMeetingEventTypeId = ref('');
const newMeetingHostId = ref('any');

const newMeetingEventType = computed(() =>
    props.bookableEventTypes.find(
        (eventType) => String(eventType.id) === newMeetingEventTypeId.value,
    ),
);

watch(newMeetingEventTypeId, () => (newMeetingHostId.value = 'any'));

const openNewMeeting = () => {
    newMeetingEventTypeId.value = String(props.bookableEventTypes[0]?.id ?? '');
    newMeetingHostId.value = 'any';
    creating.value = true;
};

/**
 * Staff book on the same page invitees use, signed in, so the invitee still
 * gets the confirmation and the history records who made the booking.
 */
const continueNewMeeting = () => {
    if (!newMeetingEventType.value) {
        return;
    }

    const url = new URL(newMeetingEventType.value.url, window.location.href);

    if (newMeetingHostId.value !== 'any') {
        url.searchParams.set('host', newMeetingHostId.value);
    }

    window.open(url.toString(), '_blank');

    creating.value = false;
};

const movingAll = ref(false);

const moveAllAwayMeetings = () => {
    router.post(
        handOffAll(teamSlug.value).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (movingAll.value = true),
            onFinish: () => (movingAll.value = false),
        },
    );
};

const confirmCancel = () => {
    if (!canceling.value) {
        return;
    }

    cancelForm.delete(
        destroy({ current_team: teamSlug.value, booking: canceling.value.uid })
            .url,
        {
            preserveScroll: true,
            onSuccess: () => {
                canceling.value = null;
                selected.value = null;
                cancelForm.reset();
            },
        },
    );
};

const confirmDecline = () => {
    if (!declining.value) {
        return;
    }

    declineForm.post(
        decline({ current_team: teamSlug.value, booking: declining.value.uid })
            .url,
        {
            preserveScroll: true,
            onSuccess: () => {
                declining.value = null;
                selected.value = null;
                declineForm.reset();
            },
        },
    );
};

setLayoutProps({
    breadcrumbs: [{ title: 'Meetings', href: index(teamSlug.value) }],
});
</script>

<template>
    <Head title="Meetings" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Meetings"
            description="Everything booked with you and your organization."
        >
            <template v-if="bookableEventTypes.length" #actions>
                <Button data-test="new-meeting" @click="openNewMeeting">
                    <Plus class="size-4" /> New meeting
                </Button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <ScopePicker
                :model-value="scope"
                :options="scopeOptions"
                @update:model-value="
                    (value) => reload({ scope: value, page: 1 })
                "
            />

            <div class="relative min-w-56 flex-1">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    class="pl-9"
                    placeholder="Search meetings"
                    data-test="search-meetings"
                />
            </div>

            <Popover>
                <PopoverTrigger as-child>
                    <Button variant="outline" data-test="meetings-filter">
                        <SlidersHorizontal class="size-4 opacity-70" /> Filter
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="end" class="w-72 space-y-4">
                    <div class="grid gap-2">
                        <Label for="event-type-filter">Event type</Label>
                        <Select
                            :model-value="eventTypeId ?? 'all'"
                            @update:model-value="
                                (value) =>
                                    reload({
                                        event_type:
                                            value === 'all'
                                                ? null
                                                : Number(value),
                                        page: 1,
                                    })
                            "
                        >
                            <SelectTrigger
                                id="event-type-filter"
                                class="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all"
                                    >All event types</SelectItem
                                >
                                <SelectItem
                                    v-for="option in eventTypeOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-2">
                        <Label for="status-filter">Status</Label>
                        <Select
                            :model-value="status"
                            @update:model-value="
                                (value) =>
                                    reload({ status: String(value), page: 1 })
                            "
                        >
                            <SelectTrigger
                                id="status-filter"
                                class="w-full"
                                data-test="status-filter"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="pending">
                                    Pending approval
                                </SelectItem>
                                <SelectItem value="host_away">
                                    Host away
                                </SelectItem>
                                <SelectItem value="canceled">
                                    Cancelled &amp; rescheduled
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </PopoverContent>
            </Popover>
        </div>

        <div
            v-if="hostAwayCount > 0"
            class="flex flex-wrap items-center gap-3 rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm"
            data-test="host-away-banner"
        >
            <Plane class="size-4 shrink-0 text-destructive" />
            <p class="min-w-0 flex-1">
                {{ hostAwayCount }} upcoming
                {{ hostAwayCount === 1 ? 'meeting falls' : 'meetings fall' }}
                while the host is out of office.
            </p>
            <Button
                v-if="status !== 'host_away'"
                variant="outline"
                size="sm"
                data-test="show-host-away"
                @click="reload({ status: 'host_away', page: 1 })"
            >
                Show them
            </Button>
            <Button
                size="sm"
                :disabled="movingAll"
                data-test="move-all-host-away"
                @click="moveAllAwayMeetings"
            >
                Move to free hosts
            </Button>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="range in ranges"
                    :key="range.value"
                    size="sm"
                    class="rounded-full"
                    :variant="filter === range.value ? 'default' : 'outline'"
                    :data-test="`filter-${range.value}`"
                    @click="reload({ filter: range.value, page: 1 })"
                >
                    <Check v-if="filter === range.value" class="size-3.5" />
                    {{ range.label }}
                </Button>
            </div>

            <p class="text-sm text-muted-foreground">
                Displaying {{ total }}
                {{ total === 1 ? 'meeting' : 'meetings' }}
            </p>
        </div>

        <div v-if="days.length" class="flex flex-col gap-6">
            <section v-for="day in days" :key="day.label">
                <h2 class="mb-2 flex items-center gap-2 text-sm">
                    <span class="font-semibold">{{ day.day }}</span>
                    <span class="text-muted-foreground">{{ day.date }}</span>
                    <Badge v-if="day.isToday" variant="secondary">Today</Badge>
                </h2>

                <div class="flex flex-col gap-3">
                    <button
                        v-for="meeting in day.meetings"
                        :key="meeting.uid"
                        type="button"
                        data-test="meeting-row"
                        :aria-pressed="selected?.uid === meeting.uid"
                        :class="[
                            'flex w-full cursor-pointer flex-wrap items-center gap-x-6 gap-y-2 rounded-lg border p-4 text-left transition-colors',
                            selected?.uid === meeting.uid
                                ? 'border-primary bg-accent/50'
                                : isPast(meeting)
                                  ? 'bg-muted hover:bg-accent/40'
                                  : 'bg-card shadow-flat hover:bg-accent/30 hover:shadow-raised',
                        ]"
                        :data-past="isPast(meeting) || undefined"
                        @click="selected = meeting"
                    >
                        <span class="w-40 shrink-0">
                            <Badge
                                v-if="isInProgress(meeting)"
                                class="gap-1.5 border-transparent bg-success-muted text-success-muted-foreground"
                                data-test="in-progress-badge"
                            >
                                <span
                                    class="size-1.5 animate-pulse rounded-full bg-success"
                                    aria-hidden="true"
                                />
                                In progress
                            </Badge>
                            <Badge
                                v-else-if="meeting.awayHostNames.length"
                                variant="destructive"
                                data-test="host-away-badge"
                            >
                                <Plane class="size-3" /> Host away
                            </Badge>
                            <Badge
                                v-else-if="meeting.noShow"
                                variant="destructive"
                                data-test="no-show-badge"
                            >
                                <UserX class="size-3" /> No-show
                            </Badge>
                            <span
                                v-else-if="isDone(meeting)"
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                                data-test="done-label"
                            >
                                <CircleCheck class="size-3" /> Done
                            </span>
                            <Badge
                                v-else-if="meeting.status === 'pending'"
                                variant="secondary"
                                data-test="pending-badge"
                            >
                                <Clock class="size-3" /> Pending
                            </Badge>
                            <span
                                v-else-if="meeting.status !== 'confirmed'"
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <RotateCcw class="size-3" />
                                {{ meeting.statusLabel }}
                            </span>
                            <span class="block text-sm text-muted-foreground">
                                {{ meeting.timeLabel }}
                            </span>
                        </span>

                        <span class="flex min-w-0 flex-1 items-center gap-2">
                            <span
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{ backgroundColor: meeting.color }"
                            />
                            <span class="min-w-0">
                                <span class="font-semibold">
                                    {{ meeting.eventTypeName }}
                                </span>
                                <span class="text-muted-foreground">
                                    hosted by
                                    {{ meeting.hostNames.join(', ') }} with
                                    {{ meeting.inviteeName }}
                                </span>
                            </span>
                        </span>
                    </button>
                </div>
            </section>

            <div v-if="hasMore" class="flex justify-center">
                <Button
                    variant="outline"
                    class="rounded-full"
                    data-test="view-more-meetings"
                    @click="loadMore"
                >
                    View more meetings
                </Button>
            </div>
        </div>

        <div
            v-else
            class="rounded-lg border border-dashed p-12 text-center text-muted-foreground"
        >
            No meetings here.
        </div>
    </div>

    <MeetingDetailPanel
        :meeting="selected"
        :team-slug="teamSlug"
        @close="selected = null"
        @cancel="askToCancel"
        @decline="askToDecline"
        @reschedule="askToReschedule"
    />

    <Dialog v-model:open="creating">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Book a meeting for someone</DialogTitle>
                <DialogDescription>
                    Pick what to book and who hosts it, then choose a time and
                    enter the invitee's details.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="new-meeting-event-type">Event type</Label>
                    <Select v-model="newMeetingEventTypeId">
                        <SelectTrigger
                            id="new-meeting-event-type"
                            class="w-full"
                            data-test="new-meeting-event-type"
                        >
                            <SelectValue placeholder="Choose an event type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="eventType in bookableEventTypes"
                                :key="eventType.id"
                                :value="String(eventType.id)"
                            >
                                {{ eventType.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div
                    v-if="newMeetingEventType?.hosts.length"
                    class="grid gap-2"
                >
                    <Label for="new-meeting-host">Host</Label>
                    <Select v-model="newMeetingHostId">
                        <SelectTrigger
                            id="new-meeting-host"
                            class="w-full"
                            data-test="new-meeting-host"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="any">
                                Whoever is free (automatic)
                            </SelectItem>
                            <SelectItem
                                v-for="host in newMeetingEventType.hosts"
                                :key="host.id"
                                :value="String(host.id)"
                            >
                                {{ host.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="creating = false">
                    Cancel
                </Button>
                <Button
                    :disabled="!newMeetingEventType"
                    data-test="continue-new-meeting"
                    @click="continueNewMeeting"
                >
                    Pick a time
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog :open="rescheduling !== null" @update:open="dismissReschedule">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Reschedule this meeting</DialogTitle>
                <DialogDescription>
                    Choose who should host it, then pick a time they are free.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label for="reschedule-host">Host</Label>
                <Select v-model="rescheduleHostId">
                    <SelectTrigger
                        id="reschedule-host"
                        class="w-full"
                        data-test="reschedule-host"
                    >
                        <SelectValue placeholder="Choose a host" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="host in rescheduling?.reassignableHosts ??
                            []"
                            :key="host.id"
                            :value="String(host.id)"
                        >
                            {{ host.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="dismissReschedule">
                    Back
                </Button>
                <Button
                    :disabled="!rescheduleHostId"
                    data-test="continue-reschedule"
                    @click="continueReschedule"
                >
                    Pick a time
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog :open="declining !== null" @update:open="dismissDecline">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Decline this request?</DialogTitle>
                <DialogDescription>
                    {{ declining?.inviteeName }} will be emailed that the time
                    doesn't work.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label for="decline-reason">Reason (optional)</Label>
                <Textarea id="decline-reason" v-model="declineForm.reason" />
            </div>

            <DialogFooter>
                <Button variant="outline" @click="dismissDecline">
                    Keep it
                </Button>
                <Button
                    variant="destructive"
                    data-test="confirm-decline-booking"
                    @click="confirmDecline"
                >
                    Decline request
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog :open="canceling !== null" @update:open="dismissCancel">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Cancel this meeting?</DialogTitle>
                <DialogDescription>
                    {{ canceling?.inviteeName }} will be emailed and the event
                    is removed from the connected calendars.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label for="cancel-reason">Reason (optional)</Label>
                <Textarea id="cancel-reason" v-model="cancelForm.reason" />
            </div>

            <DialogFooter>
                <Button variant="outline" @click="dismissCancel">
                    Keep it
                </Button>
                <Button
                    variant="destructive"
                    data-test="confirm-cancel-booking"
                    @click="confirmCancel"
                >
                    Cancel meeting
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

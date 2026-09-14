<script setup lang="ts">
import { Head, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import {
    Check,
    Clock,
    RotateCcw,
    Search,
    SlidersHorizontal,
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
import { decline, destroy, index } from '@/routes/meetings';

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
};

type Option = { value: string; label: string; initial: string | null };

type Props = {
    meetings: Meeting[];
    total: number;
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
};

const props = defineProps<Props>();

const { teamSlug } = useCurrentTeam();

const search = ref(props.search);
const selected = ref<Meeting | null>(null);
const canceling = ref<Meeting | null>(null);
const cancelForm = useForm({ reason: '' });
const declining = ref<Meeting | null>(null);
const declineForm = useForm({ reason: '' });

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
        />

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
                                <SelectItem value="canceled">
                                    Cancelled &amp; rescheduled
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </PopoverContent>
            </Popover>
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
                                : 'bg-card shadow-flat hover:bg-accent/30 hover:shadow-raised',
                        ]"
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
    />

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

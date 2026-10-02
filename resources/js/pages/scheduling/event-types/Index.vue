<script setup lang="ts">
import { Head, Link, router, setLayoutProps, useHttp } from '@inertiajs/vue3';
import {
    CalendarPlus,
    Check,
    ChevronDown,
    Copy,
    CopyPlus,
    Crown,
    ExternalLink,
    Link2,
    EyeOff,
    MoreVertical,
    Pencil,
    Plus,
    Power,
    QrCode,
    Search,
    SearchX,
    SlidersHorizontal,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import MonthCalendar from '@/components/booking/MonthCalendar.vue';
import PageHeader from '@/components/PageHeader.vue';
import BookingQrCodeDialog from '@/components/scheduling/BookingQrCodeDialog.vue';
import CreateEventTypePanel from '@/components/scheduling/CreateEventTypePanel.vue';
import EventTypeDetailPanel from '@/components/scheduling/EventTypeDetailPanel.vue';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCurrentTeam } from '@/composables/useCurrentTeam';
import { index as meetingsIndex } from '@/routes/meetings';
import {
    destroy,
    duplicate as duplicateRoute,
    edit,
    index,
} from '@/routes/scheduling';
import { update as updateActive } from '@/routes/scheduling/active';
import { store as storeSingleUseLink } from '@/routes/scheduling/single-use-links';

type EventTypeRow = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    color: string;
    kind: string;
    kindLabel: string;
    durationMinutes: number;
    availabilitySummary: string;
    isShared: boolean;
    locationLabel: string;
    locationDetail: string | null;
    isActive: boolean;
    canMakeSingleUseLink: boolean;
    isHidden: boolean;
    upcomingBookings: number;
    ownerName: string;
    ownerLandingUrl: string | null;
    groupName: string | null;
    hosts: Array<{ id: number; name: string; initial: string }>;
    publicUrl: string;
    bufferBeforeMinutes: number;
    bufferAfterMinutes: number;
    slotIntervalMinutes: number | null;
    dateRangeType: string;
    rollingDays: number | null;
    rangeStartsOn: string | null;
    rangeEndsOn: string | null;
    seatsPerSlot: number | null;
    dailyBookingLimit: number | null;
    requiresConfirmation: boolean;
    scheduleName: string | null;
    canUpdate: boolean;
    canDelete: boolean;
};

type Kind = {
    value: string;
    label: string;
    flow: string;
    description: string;
    requiresTeam: boolean;
};

type CalendarBooking = {
    uid: string;
    name: string;
    eventTypeName: string | null;
    color: string | null;
    timeLabel: string;
    endTimeLabel: string;
    status: string;
    statusLabel: string;
};

type Props = {
    eventTypes: EventTypeRow[];
    canCreate: boolean;
    kinds: Kind[];
    locationTypes: any[];
    schedules: any[];
    teamMembers: any[];
    isSoloOrganization: boolean;
    currentUser: { id: number; name: string };
    groups: Array<{ id: number; name: string; memberNames: string[] }>;
    canAssignOwner: boolean;
    scope: string;
    scopeOptions: {
        primary: Array<{
            value: string;
            label: string;
            initial: string | null;
        }>;
        groups: Array<{ value: string; label: string; initial: string | null }>;
        users: Array<{ value: string; label: string; initial: string | null }>;
    };
    calendarMonth: string;
    /** Deferred; undefined until the month's meetings stream in. */
    calendarBookings?: Record<string, CalendarBooking[]>;
};

const props = defineProps<Props>();

const { teamSlug } = useCurrentTeam();

const deleting = ref<EventTypeRow | null>(null);
/** The row whose detail panel is open. */
const selected = ref<EventTypeRow | null>(null);
const search = ref('');
const kindFilter = ref('all');

/**
 * The detail panel and the delete dialog are each modal layers, and two open at
 * once fight over the focus trap, which leaves the dialog's buttons unreachable.
 * Opening the dialog hands off from the panel; dismissing it hands back.
 */
const askToDelete = () => {
    deleting.value = selected.value;
    selected.value = null;
};

const dismissDelete = () => {
    selected.value = deleting.value;
    deleting.value = null;
};

const filtered = computed(() =>
    props.eventTypes.filter((eventType) => {
        const term = search.value.trim().toLowerCase();
        const matchesTerm =
            term === '' ||
            eventType.name.toLowerCase().includes(term) ||
            eventType.ownerName.toLowerCase().includes(term);

        const matchesKind =
            kindFilter.value === 'all' || eventType.kind === kindFilter.value;

        return matchesTerm && matchesKind;
    }),
);

/** Event types listed under whoever owns them, the way Calendly groups them. */
const grouped = computed(() => {
    const sections = new Map<
        string,
        { name: string; landingUrl: string | null; eventTypes: EventTypeRow[] }
    >();

    for (const eventType of filtered.value) {
        const section = sections.get(eventType.ownerName) ?? {
            name: eventType.ownerName,
            landingUrl: eventType.ownerLandingUrl,
            eventTypes: [],
        };

        section.eventTypes.push(eventType);
        sections.set(eventType.ownerName, section);
    }

    return [...sections.values()].sort((a, b) => a.name.localeCompare(b.name));
});

const activeCount = (group: { eventTypes: EventTypeRow[] }) =>
    group.eventTypes.filter((eventType) => eventType.isActive).length;

const applyScope = (value: string) => {
    router.get(
        index(teamSlug.value).url,
        { scope: value },
        { preserveState: true, preserveScroll: true },
    );
};

const hasActiveFilters = computed(
    () => search.value.trim() !== '' || kindFilter.value !== 'all',
);

const resetFilters = () => {
    search.value = '';
    kindFilter.value = 'all';
};

/** The kind being created, which also drives the side panel's visibility. */
const creatingKind = ref<string | null>(null);

/*
  Only offer a filter for a kind you could actually be looking at: the ones
  creatable here, plus any kind already on the list. A personal organization
  cannot make round robin or collective event types, so filtering by them
  would always come back empty.
*/
const filterableKinds = computed(() => {
    const present = new Set(
        props.eventTypes.map((eventType) => eventType.kind),
    );

    return props.kinds.filter(
        (kind) =>
            present.has(kind.value) ||
            !kind.requiresTeam ||
            !props.isSoloOrganization,
    );
});

const calendarReloading = ref(false);
const calendarLoading = computed(
    () => props.calendarBookings === undefined || calendarReloading.value,
);
const calendarDates = computed(() => Object.keys(props.calendarBookings ?? {}));
const selectedCalendarDate = ref<string | null>(null);

const selectedDayBookings = computed(() =>
    selectedCalendarDate.value
        ? (props.calendarBookings?.[selectedCalendarDate.value] ?? [])
        : [],
);

const selectedCalendarDateLabel = computed(() =>
    selectedCalendarDate.value
        ? new Date(
              `${selectedCalendarDate.value}T00:00:00Z`,
          ).toLocaleDateString('en-US', {
              weekday: 'long',
              month: 'long',
              day: 'numeric',
              timeZone: 'UTC',
          })
        : '',
);

// 'en-CA' renders the local date as YYYY-MM-DD, matching the payload keys.
const localToday = new Date().toLocaleDateString('en-CA');

// Once the deferred meetings arrive, open on today when it has any.
watch(calendarDates, (dates) => {
    if (!selectedCalendarDate.value && dates.includes(localToday)) {
        selectedCalendarDate.value = localToday;
    }
});

const changeCalendarMonth = (month: string) => {
    selectedCalendarDate.value = null;

    router.reload({
        data: { calendarMonth: month },
        only: ['calendarBookings', 'calendarMonth'],
        onStart: () => (calendarReloading.value = true),
        onFinish: () => (calendarReloading.value = false),
    });
};

/** The booking link whose QR code is open, if any. */
const qrCode = ref<{ url: string; name: string } | null>(null);

const copiedSlug = ref<string | null>(null);
let copyTimer: number | undefined;

const copyLink = async (eventType: EventTypeRow) => {
    try {
        await navigator.clipboard.writeText(eventType.publicUrl);
        copiedSlug.value = eventType.slug;
        window.clearTimeout(copyTimer);
        copyTimer = window.setTimeout(() => (copiedSlug.value = null), 2000);
    } catch {
        toast.error('Could not copy the link');
    }
};

const http = useHttp();

/**
 * The link is made on the server, so the clipboard write waits on the
 * request. Handing the clipboard a promise keeps Safari treating it as part
 * of the click; elsewhere the plain write after the response is fine.
 */
const copySingleUseLink = async (eventType: EventTypeRow) => {
    const request = http.submit(
        storeSingleUseLink({
            current_team: teamSlug.value,
            event_type: eventType.slug,
        }),
    ) as Promise<{ url: string }>;

    try {
        if (typeof ClipboardItem !== 'undefined' && navigator.clipboard.write) {
            await navigator.clipboard.write([
                new ClipboardItem({
                    'text/plain': request.then(
                        ({ url }) => new Blob([url], { type: 'text/plain' }),
                    ),
                }),
            ]);
        } else {
            const { url } = await request;
            await navigator.clipboard.writeText(url);
        }

        toast.success(
            'Single-use link copied. It stops working after one booking.',
        );
    } catch {
        toast.error('Could not make a single-use link');
    }
};

const displayUrl = (url: string) => url.replace(/^https?:\/\//, '');

const toggleActive = (eventType: EventTypeRow) => {
    router.patch(
        updateActive({
            current_team: teamSlug.value,
            event_type: eventType.slug,
        }).url,
        { is_active: !eventType.isActive },
        { preserveScroll: true },
    );
};

const duplicateEventType = (eventType: EventTypeRow) => {
    router.post(
        duplicateRoute({
            current_team: teamSlug.value,
            event_type: eventType.slug,
        }).url,
        {},
        { preserveScroll: true },
    );
};

const confirmDelete = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(
        destroy({
            current_team: teamSlug.value,
            event_type: deleting.value.slug,
        }).url,
        {
            onFinish: () => (deleting.value = null),
        },
    );
};

setLayoutProps({
    breadcrumbs: [{ title: 'Scheduling', href: index(teamSlug.value) }],
});
</script>

<template>
    <Head title="Scheduling" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Scheduling"
            description="Create and manage the event types people can book with you."
        >
            <template #actions>
                <DropdownMenu v-if="canCreate">
                    <DropdownMenuTrigger as-child>
                        <Button
                            data-test="new-event-type"
                            class="cursor-pointer"
                        >
                            <Plus /> Create
                            <ChevronDown class="size-4 opacity-70" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-72">
                        <DropdownMenuLabel class="text-muted-foreground">
                            Event type
                        </DropdownMenuLabel>
                        <!--
                      Team-only kinds stay listed but disabled on a personal
                      organization: hiding them just raises the question of why
                      only two of the four are here.
                    -->
                        <DropdownMenuItem
                            v-for="kind in kinds"
                            :key="kind.value"
                            class="flex-col items-start gap-0.5 py-2.5"
                            :disabled="kind.requiresTeam && isSoloOrganization"
                            :data-test="`new-event-type-${kind.value}`"
                            @select="creatingKind = kind.value"
                        >
                            <span class="font-medium text-primary">
                                {{ kind.label }}
                            </span>
                            <span class="text-sm">{{ kind.flow }}</span>
                            <span class="text-xs text-muted-foreground">
                                {{
                                    kind.requiresTeam && isSoloOrganization
                                        ? 'Needs a shared organization with more than one member.'
                                        : kind.description
                                }}
                            </span>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-6 xl:flex-row xl:items-start">
            <div class="flex min-w-0 flex-1 flex-col gap-6">
                <div class="flex flex-wrap items-center gap-2">
                    <ScopePicker
                        :model-value="scope"
                        :options="scopeOptions"
                        @update:model-value="applyScope"
                    />

                    <div class="relative min-w-56 flex-1">
                        <Search
                            class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="search"
                            class="pl-9"
                            placeholder="Search event types..."
                            data-test="search-event-types"
                        />
                    </div>

                    <Select v-model="kindFilter">
                        <SelectTrigger
                            class="w-44 cursor-pointer"
                            :class="
                                kindFilter !== 'all'
                                    ? 'border-primary text-primary'
                                    : ''
                            "
                            data-test="filter-event-types"
                        >
                            <SlidersHorizontal class="size-4 opacity-70" />
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            <SelectItem
                                v-for="kind in filterableKinds"
                                :key="kind.value"
                                :value="kind.value"
                            >
                                {{ kind.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Button
                        v-if="hasActiveFilters"
                        variant="ghost"
                        size="sm"
                        class="cursor-pointer text-muted-foreground"
                        data-test="reset-filters"
                        @click="resetFilters"
                    >
                        <X class="size-4" /> Reset
                    </Button>
                </div>

                <TooltipProvider>
                    <div v-if="grouped.length" class="flex flex-col gap-8">
                        <section v-for="group in grouped" :key="group.name">
                            <header
                                class="mb-3 flex flex-wrap items-center justify-between gap-3"
                            >
                                <div class="flex items-center gap-3">
                                    <span
                                        class="flex size-8 items-center justify-center rounded-full bg-muted text-xs font-medium"
                                    >
                                        {{ group.name.charAt(0) }}
                                    </span>
                                    <div>
                                        <p class="text-sm font-semibold">
                                            {{ group.name }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ group.eventTypes.length }} event
                                            {{
                                                group.eventTypes.length === 1
                                                    ? 'type'
                                                    : 'types'
                                            }}
                                            &middot;
                                            {{ activeCount(group) }} active
                                        </p>
                                    </div>
                                </div>

                                <!--
                                  Only a section that stands for a real public
                                  page offers the link: a person's own page or a
                                  team's. "Shared" stands for none.
                                -->
                                <div
                                    v-if="group.landingUrl"
                                    class="flex items-center gap-3"
                                >
                                    <Button
                                        variant="link"
                                        size="sm"
                                        class="h-auto cursor-pointer p-0"
                                        @click="
                                            qrCode = {
                                                url: group.landingUrl,
                                                name: group.name,
                                            }
                                        "
                                    >
                                        <QrCode class="size-3.5" />
                                        QR code
                                    </Button>
                                    <Button
                                        variant="link"
                                        size="sm"
                                        class="h-auto p-0"
                                        as-child
                                    >
                                        <a
                                            :href="group.landingUrl"
                                            target="_blank"
                                        >
                                            View landing page
                                            <ExternalLink class="size-3.5" />
                                        </a>
                                    </Button>
                                </div>
                            </header>

                            <div class="flex flex-col gap-3">
                                <article
                                    v-for="eventType in group.eventTypes"
                                    :key="eventType.id"
                                    data-test="event-type-row"
                                    class="relative flex flex-wrap items-center overflow-hidden rounded-lg border shadow-flat transition-shadow sm:flex-nowrap"
                                    :class="
                                        selected?.id === eventType.id
                                            ? 'border-primary bg-accent/50'
                                            : 'bg-card hover:shadow-raised'
                                    "
                                >
                                    <span
                                        class="absolute inset-y-0 left-0 w-1.5"
                                        :style="{
                                            backgroundColor: eventType.color,
                                        }"
                                        aria-hidden="true"
                                    />

                                    <!--
                                      The row carries its own buttons and menu,
                                      so the click target sits underneath them
                                      rather than wrapping them.
                                    -->
                                    <button
                                        type="button"
                                        class="absolute inset-0 z-0 cursor-pointer"
                                        :aria-label="`View ${eventType.name}`"
                                        :aria-pressed="
                                            selected?.id === eventType.id
                                        "
                                        :data-test="`open-event-type-${eventType.slug}`"
                                        @click="selected = eventType"
                                    />

                                    <div
                                        class="pointer-events-none relative z-10 min-w-0 flex-1 basis-full py-3.5 pr-4 pl-5 sm:basis-auto"
                                    >
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <span class="font-semibold">
                                                {{ eventType.name }}
                                            </span>
                                            <Crown
                                                v-if="eventType.isShared"
                                                class="size-3.5 text-muted-foreground"
                                                aria-label="Organization event type"
                                            />
                                            <Badge
                                                variant="outline"
                                                class="gap-1.5 font-normal text-muted-foreground"
                                            >
                                                <span
                                                    class="size-1.5 rounded-full"
                                                    :class="
                                                        eventType.isActive
                                                            ? 'bg-success'
                                                            : 'bg-muted-foreground/40'
                                                    "
                                                    aria-hidden="true"
                                                />
                                                {{
                                                    eventType.isActive
                                                        ? 'Active'
                                                        : 'Inactive'
                                                }}
                                            </Badge>
                                            <EyeOff
                                                v-if="eventType.isHidden"
                                                class="size-3.5 text-muted-foreground"
                                                aria-label="Hidden from the booking page"
                                            />
                                        </div>

                                        <p
                                            class="mt-1 text-sm text-muted-foreground"
                                        >
                                            {{ eventType.durationMinutes }} min
                                            &middot;
                                            {{ eventType.locationLabel }}
                                            &middot;
                                            {{ eventType.kindLabel }}
                                            <template
                                                v-if="
                                                    eventType.upcomingBookings
                                                "
                                            >
                                                &middot;
                                                {{ eventType.upcomingBookings }}
                                                upcoming
                                            </template>
                                        </p>
                                        <p
                                            class="mt-0.5 text-sm text-muted-foreground"
                                        >
                                            {{ eventType.availabilitySummary }}
                                        </p>
                                        <p
                                            class="mt-1 truncate text-xs text-muted-foreground/80"
                                            :title="eventType.publicUrl"
                                        >
                                            {{
                                                displayUrl(eventType.publicUrl)
                                            }}
                                        </p>
                                    </div>

                                    <div
                                        class="pointer-events-none relative z-10 hidden shrink-0 -space-x-2 pr-2 sm:flex"
                                    >
                                        <span
                                            v-for="host in eventType.hosts.slice(
                                                0,
                                                3,
                                            )"
                                            :key="host.id"
                                            :title="host.name"
                                            class="flex size-7 items-center justify-center rounded-full border-2 border-background bg-muted text-xs font-medium"
                                        >
                                            {{ host.initial }}
                                        </span>
                                        <span
                                            v-if="eventType.hosts.length > 3"
                                            class="flex size-7 items-center justify-center rounded-full border-2 border-background bg-muted text-xs font-medium"
                                        >
                                            +{{ eventType.hosts.length - 3 }}
                                        </span>
                                    </div>

                                    <div
                                        class="relative z-10 flex w-full shrink-0 items-center justify-end gap-1 border-t px-3 py-2 sm:w-auto sm:border-t-0 sm:px-0 sm:py-0 sm:pr-4"
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="cursor-pointer rounded-full"
                                            @click="copyLink(eventType)"
                                        >
                                            <Check
                                                v-if="
                                                    copiedSlug ===
                                                    eventType.slug
                                                "
                                                class="size-3.5 text-success"
                                                aria-hidden="true"
                                            />
                                            <Copy
                                                v-else
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {{
                                                copiedSlug === eventType.slug
                                                    ? 'Copied!'
                                                    : 'Copy link'
                                            }}
                                        </Button>

                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    class="cursor-pointer"
                                                    :aria-label="`Preview ${eventType.name}`"
                                                    as-child
                                                >
                                                    <a
                                                        :href="
                                                            eventType.publicUrl
                                                        "
                                                        target="_blank"
                                                        rel="noopener"
                                                    >
                                                        <ExternalLink
                                                            class="size-4"
                                                        />
                                                    </a>
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                <p>Open booking page</p>
                                            </TooltipContent>
                                        </Tooltip>

                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    class="cursor-pointer"
                                                    :aria-label="`More actions for ${eventType.name}`"
                                                    :data-test="`event-type-menu-${eventType.slug}`"
                                                >
                                                    <MoreVertical
                                                        class="size-4"
                                                    />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                class="w-44"
                                            >
                                                <DropdownMenuItem as-child>
                                                    <Link
                                                        :href="
                                                            edit({
                                                                current_team:
                                                                    teamSlug,
                                                                event_type:
                                                                    eventType.slug,
                                                            })
                                                        "
                                                    >
                                                        <Pencil
                                                            class="size-4"
                                                        />
                                                        Edit
                                                    </Link>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    v-if="
                                                        eventType.canMakeSingleUseLink
                                                    "
                                                    :data-test="`single-use-link-${eventType.slug}`"
                                                    @select="
                                                        copySingleUseLink(
                                                            eventType,
                                                        )
                                                    "
                                                >
                                                    <Link2 class="size-4" />
                                                    Single-use link
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    :data-test="`qr-code-${eventType.slug}`"
                                                    @select="
                                                        qrCode = {
                                                            url: eventType.publicUrl,
                                                            name: eventType.name,
                                                        }
                                                    "
                                                >
                                                    <QrCode class="size-4" />
                                                    QR code
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    v-if="canCreate"
                                                    :data-test="`duplicate-event-type-${eventType.slug}`"
                                                    @select="
                                                        duplicateEventType(
                                                            eventType,
                                                        )
                                                    "
                                                >
                                                    <CopyPlus class="size-4" />
                                                    Duplicate
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    :data-test="`toggle-event-type-${eventType.slug}`"
                                                    @select="
                                                        toggleActive(eventType)
                                                    "
                                                >
                                                    <Power class="size-4" />
                                                    {{
                                                        eventType.isActive
                                                            ? 'Disable'
                                                            : 'Enable'
                                                    }}
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    @select="
                                                        deleting = eventType
                                                    "
                                                >
                                                    <Trash2 class="size-4" />
                                                    Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </article>
                            </div>
                        </section>
                    </div>

                    <div
                        v-else-if="eventTypes.length"
                        class="flex flex-col items-center gap-3 rounded-lg border border-dashed px-6 py-16 text-center"
                    >
                        <span
                            class="flex size-10 items-center justify-center rounded-full bg-muted"
                        >
                            <SearchX class="size-5 text-muted-foreground" />
                        </span>
                        <div>
                            <p class="font-medium">No matching event types</p>
                            <p class="text-sm text-muted-foreground">
                                Try a different search, or clear the filters.
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            class="cursor-pointer"
                            @click="resetFilters"
                        >
                            Clear filters
                        </Button>
                    </div>

                    <div
                        v-else
                        class="flex flex-col items-center gap-4 rounded-lg border border-dashed px-6 py-20 text-center"
                    >
                        <span
                            class="flex size-12 items-center justify-center rounded-full bg-muted"
                        >
                            <CalendarPlus
                                class="size-6 text-muted-foreground"
                            />
                        </span>
                        <div>
                            <p class="text-lg font-semibold">
                                Create your first event type
                            </p>
                            <p
                                class="mx-auto mt-1 max-w-sm text-sm text-muted-foreground"
                            >
                                Set up an event people can book with you, and
                                share one link instead of trading emails.
                            </p>
                        </div>
                        <Button
                            v-if="canCreate"
                            class="cursor-pointer"
                            data-test="create-first-event-type"
                            @click="creatingKind = 'one_on_one'"
                        >
                            <Plus /> New event type
                        </Button>
                    </div>
                </TooltipProvider>
            </div>

            <aside
                class="w-full shrink-0 xl:sticky xl:top-6 xl:w-80"
                aria-label="Meetings calendar"
            >
                <section
                    class="rounded-lg border bg-card p-4 shadow-flat sm:p-5"
                >
                    <div class="mb-4 flex items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold">Your meetings</h2>
                        <Button
                            variant="link"
                            size="sm"
                            class="h-auto p-0 text-xs"
                            as-child
                        >
                            <Link :href="meetingsIndex(teamSlug)"
                                >View all</Link
                            >
                        </Button>
                    </div>

                    <MonthCalendar
                        :month="calendarMonth"
                        :available-dates="calendarDates"
                        :selected-date="selectedCalendarDate"
                        :loading="calendarLoading"
                        grid-label="Days with meetings"
                        marked-day-label="has meetings"
                        unmarked-day-label="no meetings"
                        @update:month="changeCalendarMonth"
                        @select="(date) => (selectedCalendarDate = date)"
                    />

                    <div class="mt-4 border-t pt-4">
                        <div v-if="calendarLoading" class="space-y-2">
                            <Skeleton
                                v-for="index in 3"
                                :key="index"
                                class="h-9 w-full animate-pulse rounded-md"
                            />
                            <span class="sr-only">Loading meetings</span>
                        </div>

                        <p
                            v-else-if="!calendarDates.length"
                            class="text-sm text-muted-foreground"
                        >
                            No meetings this month.
                        </p>

                        <template v-else-if="selectedCalendarDate">
                            <h3
                                class="text-xs font-medium text-muted-foreground"
                                aria-live="polite"
                            >
                                {{ selectedCalendarDateLabel }}
                            </h3>
                            <ul class="mt-2 space-y-2">
                                <li
                                    v-for="booking in selectedDayBookings"
                                    :key="booking.uid"
                                    class="flex items-start gap-2.5 rounded-md border px-3 py-2"
                                    :data-test="`calendar-meeting-${booking.uid}`"
                                >
                                    <span
                                        class="mt-1.5 size-2 shrink-0 rounded-full"
                                        :class="
                                            booking.color
                                                ? ''
                                                : 'bg-muted-foreground/40'
                                        "
                                        :style="
                                            booking.color
                                                ? {
                                                      backgroundColor:
                                                          booking.color,
                                                  }
                                                : undefined
                                        "
                                        aria-hidden="true"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium">
                                            {{
                                                booking.eventTypeName ??
                                                'Meeting'
                                            }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                            data-numeric
                                        >
                                            {{ booking.timeLabel }} &ndash;
                                            {{ booking.endTimeLabel }} &middot;
                                            {{ booking.name }}
                                        </p>
                                    </div>
                                    <Badge
                                        v-if="booking.status === 'pending'"
                                        variant="outline"
                                        class="shrink-0 font-normal text-muted-foreground"
                                    >
                                        {{ booking.statusLabel }}
                                    </Badge>
                                </li>
                            </ul>
                        </template>

                        <p v-else class="text-sm text-muted-foreground">
                            Days with a dot have meetings &mdash; pick one to
                            see them.
                        </p>
                    </div>
                </section>
            </aside>
        </div>

        <!-- Announced without moving focus when a copy succeeds. -->
        <p aria-live="polite" class="sr-only">
            {{ copiedSlug ? 'Booking link copied to clipboard' : '' }}
        </p>
    </div>

    <CreateEventTypePanel
        :kind="creatingKind"
        :team-slug="teamSlug"
        :kinds="kinds"
        :location-types="locationTypes"
        :schedules="schedules"
        :team-members="teamMembers"
        :current-user="currentUser"
        :groups="groups"
        :can-assign-owner="canAssignOwner"
        @close="creatingKind = null"
    />

    <EventTypeDetailPanel
        :event-type="selected"
        :team-slug="teamSlug"
        :can-create="canCreate"
        :copied="copiedSlug !== null && copiedSlug === selected?.slug"
        @close="selected = null"
        @copy="selected && copyLink(selected)"
        @qr-code="
            selected &&
            (qrCode = { url: selected.publicUrl, name: selected.name })
        "
        @duplicate="selected && duplicateEventType(selected)"
        @toggle-active="selected && toggleActive(selected)"
        @delete="askToDelete"
    />

    <BookingQrCodeDialog
        :url="qrCode?.url ?? null"
        :name="qrCode?.name ?? ''"
        @close="qrCode = null"
    />

    <Dialog :open="deleting !== null" @update:open="dismissDelete">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete {{ deleting?.name }}?</DialogTitle>
                <DialogDescription>
                    The booking link stops working immediately. Existing
                    bookings are kept.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="dismissDelete">
                    Keep it
                </Button>
                <Button
                    variant="destructive"
                    data-test="confirm-delete-event-type"
                    @click="confirmDelete"
                >
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

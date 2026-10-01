<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CalendarRange,
    Check,
    Clock,
    Copy,
    CopyPlus,
    ExternalLink,
    Eye,
    EyeOff,
    MapPin,
    Pencil,
    Power,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { edit } from '@/routes/scheduling';

type EventType = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    color: string;
    kindLabel: string;
    durationMinutes: number;
    availabilitySummary: string;
    locationLabel: string;
    locationDetail: string | null;
    isActive: boolean;
    isHidden: boolean;
    upcomingBookings: number;
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
    requiresConfirmation: boolean;
    scheduleName: string | null;
    canUpdate: boolean;
    canDelete: boolean;
};

type Props = {
    eventType: EventType | null;
    teamSlug: string;
    canCreate: boolean;
    copied: boolean;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'copy'): void;
    (e: 'duplicate'): void;
    (e: 'toggle-active'): void;
    (e: 'delete'): void;
}>();

/** Render a minute count the way someone would say it out loud. */
const describeMinutes = (minutes: number): string => {
    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = minutes / 60;

    if (Number.isInteger(hours)) {
        return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
    }

    return `${Math.floor(hours)} hr ${minutes % 60} min`;
};

const dateRangeLabel = computed(() => {
    const eventType = props.eventType;

    if (!eventType) {
        return '';
    }

    if (eventType.dateRangeType === 'rolling_days') {
        const days = eventType.rollingDays ?? 0;

        return `${days} ${days === 1 ? 'day' : 'days'} into the future`;
    }

    if (eventType.dateRangeType === 'fixed_range') {
        return [eventType.rangeStartsOn, eventType.rangeEndsOn]
            .filter(Boolean)
            .join(' – ');
    }

    return 'Indefinitely into the future';
});

const displayUrl = (url: string) => url.replace(/^https?:\/\//, '');
</script>

<template>
    <Sheet
        :open="eventType !== null"
        @update:open="(value) => !value && emit('close')"
    >
        <SheetContent
            v-if="eventType"
            side="right"
            class="w-full gap-0 overflow-y-auto sm:max-w-md"
            data-test="event-type-detail-panel"
        >
            <SheetHeader class="gap-1">
                <p class="text-sm text-muted-foreground">Event type</p>

                <SheetTitle class="flex items-center gap-2">
                    <span
                        class="size-2.5 shrink-0 rounded-full"
                        :style="{ backgroundColor: eventType.color }"
                        aria-hidden="true"
                    />
                    {{ eventType.name }}
                </SheetTitle>

                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm text-muted-foreground">
                        {{ eventType.kindLabel }}
                    </p>
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
                        {{ eventType.isActive ? 'Active' : 'Inactive' }}
                    </Badge>
                </div>

                <p
                    v-if="eventType.description"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    {{ eventType.description }}
                </p>

                <div class="mt-3 flex flex-wrap gap-2">
                    <Button
                        v-if="eventType.canUpdate"
                        size="sm"
                        class="cursor-pointer rounded-full"
                        data-test="panel-edit"
                        as-child
                    >
                        <Link
                            :href="
                                edit({
                                    current_team: teamSlug,
                                    event_type: eventType.slug,
                                })
                            "
                        >
                            <Pencil class="size-3.5" /> Edit
                        </Link>
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="cursor-pointer rounded-full"
                        data-test="panel-copy-link"
                        @click="emit('copy')"
                    >
                        <Check
                            v-if="copied"
                            class="size-3.5 text-success"
                            aria-hidden="true"
                        />
                        <Copy v-else class="size-3.5" aria-hidden="true" />
                        {{ copied ? 'Copied!' : 'Copy link' }}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="cursor-pointer rounded-full"
                        as-child
                    >
                        <a
                            :href="eventType.publicUrl"
                            target="_blank"
                            rel="noopener"
                        >
                            <ExternalLink class="size-3.5" /> Preview
                        </a>
                    </Button>
                </div>
            </SheetHeader>

            <div class="flex flex-col gap-6 px-4 py-5">
                <section>
                    <h3 class="mb-3 font-semibold">Details</h3>

                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-center gap-2.5">
                            <Clock
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            {{ eventType.durationMinutes }} min
                        </div>
                        <div class="flex items-start gap-2.5">
                            <MapPin
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            />
                            <span class="min-w-0">
                                {{ eventType.locationLabel
                                }}<template v-if="eventType.locationDetail">
                                    &middot; {{ eventType.locationDetail }}
                                </template>
                            </span>
                        </div>
                        <!-- One seat is the default, so it says nothing worth a row. -->
                        <div
                            v-if="
                                eventType.seatsPerSlot &&
                                eventType.seatsPerSlot > 1
                            "
                            class="flex items-center gap-2.5"
                        >
                            <Users
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            {{ eventType.seatsPerSlot }} seats per slot
                        </div>
                        <div class="flex items-center gap-2.5">
                            <component
                                :is="eventType.isHidden ? EyeOff : Eye"
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            {{
                                eventType.isHidden
                                    ? 'Hidden from the booking page'
                                    : 'Listed on the booking page'
                            }}
                        </div>
                        <div
                            v-if="eventType.requiresConfirmation"
                            class="flex items-center gap-2.5"
                        >
                            <Check
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            Bookings need your approval
                        </div>
                        <div
                            v-if="eventType.upcomingBookings"
                            class="flex items-center gap-2.5"
                        >
                            <CalendarRange
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            {{ eventType.upcomingBookings }} upcoming
                        </div>
                    </dl>
                </section>

                <section class="border-t pt-5">
                    <h3 class="mb-3 font-semibold">Availability</h3>

                    <p class="text-sm">
                        Invitees can schedule
                        <span class="font-medium">{{ dateRangeLabel }}</span
                        >.
                    </p>

                    <p class="mt-3 text-sm text-muted-foreground">
                        {{ eventType.availabilitySummary }}
                    </p>

                    <dl class="mt-3 space-y-2 text-sm text-muted-foreground">
                        <div v-if="eventType.scheduleName">
                            Schedule:
                            <span class="text-foreground">
                                {{ eventType.scheduleName }}
                            </span>
                        </div>
                        <div v-if="eventType.bufferBeforeMinutes">
                            Buffer before:
                            <span class="text-foreground">
                                {{
                                    describeMinutes(
                                        eventType.bufferBeforeMinutes,
                                    )
                                }}
                            </span>
                        </div>
                        <div v-if="eventType.bufferAfterMinutes">
                            Buffer after:
                            <span class="text-foreground">
                                {{
                                    describeMinutes(
                                        eventType.bufferAfterMinutes,
                                    )
                                }}
                            </span>
                        </div>
                        <div v-if="eventType.slotIntervalMinutes">
                            Slot interval:
                            <span class="text-foreground">
                                {{
                                    describeMinutes(
                                        eventType.slotIntervalMinutes,
                                    )
                                }}
                            </span>
                        </div>
                    </dl>
                </section>

                <section v-if="eventType.hosts.length" class="border-t pt-5">
                    <h3 class="mb-3 font-semibold">Hosts</h3>

                    <ul class="space-y-2 text-sm">
                        <li
                            v-for="host in eventType.hosts"
                            :key="host.id"
                            class="flex items-center gap-2.5"
                        >
                            <span
                                class="flex size-8 items-center justify-center rounded-full bg-muted text-xs font-medium"
                            >
                                {{ host.initial }}
                            </span>
                            {{ host.name }}
                        </li>
                    </ul>
                </section>

                <section class="border-t pt-5">
                    <h3 class="mb-3 font-semibold">Booking link</h3>

                    <a
                        :href="eventType.publicUrl"
                        target="_blank"
                        rel="noopener"
                        class="text-sm break-all text-primary underline"
                    >
                        {{ displayUrl(eventType.publicUrl) }}
                    </a>
                </section>

                <section
                    v-if="eventType.canUpdate || eventType.canDelete"
                    class="flex flex-wrap gap-2 border-t pt-5"
                >
                    <Button
                        v-if="canCreate"
                        variant="outline"
                        size="sm"
                        class="cursor-pointer"
                        data-test="panel-duplicate"
                        @click="emit('duplicate')"
                    >
                        <CopyPlus class="size-3.5" /> Duplicate
                    </Button>
                    <Button
                        v-if="eventType.canUpdate"
                        variant="outline"
                        size="sm"
                        class="cursor-pointer"
                        data-test="panel-toggle-active"
                        @click="emit('toggle-active')"
                    >
                        <Power class="size-3.5" />
                        {{ eventType.isActive ? 'Disable' : 'Enable' }}
                    </Button>
                    <Button
                        v-if="eventType.canDelete"
                        variant="outline"
                        size="sm"
                        class="cursor-pointer text-destructive hover:text-destructive"
                        data-test="panel-delete"
                        @click="emit('delete')"
                    >
                        <Trash2 class="size-3.5" /> Delete
                    </Button>
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Clock, Users } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import HostPriorityList from '@/components/scheduling/HostPriorityList.vue';
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { locationIcon } from '@/lib/locationIcons';
import { store } from '@/routes/scheduling';

type Kind = {
    value: string;
    label: string;
    description: string;
    requiresTeam: boolean;
};

type LocationType = {
    value: string;
    label: string;
    shortLabel: string;
    isGenerated: boolean;
    requiresHostDetail: boolean;
    detailLabel: string | null;
};

type Schedule = {
    id: number;
    name: string;
    timezone: string;
    isDefault: boolean;
    summary: string | null;
};

type Group = { id: number; name: string; memberNames: string[] };

type Props = {
    /** The kind being created, or null when the panel is closed. */
    kind: string | null;
    teamSlug: string;
    kinds: Kind[];
    locationTypes: LocationType[];
    schedules: Schedule[];
    teamMembers: Array<{ id: number; name: string; email: string }>;
    currentUser: { id: number; name: string };
    groups: Group[];
    canAssignOwner: boolean;
};

const props = defineProps<Props>();

const emit = defineEmits<{ (e: 'close'): void }>();

/** The handful of locations offered up front, before "All options". */
const quickLocations = ['microsoft_teams', 'google_meet', 'phone', 'in_person'];

const durationPresets = [15, 30, 45, 60];

const form = useForm({
    name: 'New Meeting',
    slug: 'new-meeting',
    description: '',
    kind: 'one_on_one',
    color: '#0f766e',
    duration_minutes: 30,
    slot_interval_minutes: null as number | null,
    minimum_notice_minutes: 15,
    buffer_before_minutes: 0,
    buffer_after_minutes: 0,
    daily_booking_limit: null as number | null,
    seats_per_slot: 1,
    date_range_type: 'rolling_days',
    rolling_days: 60,
    range_starts_on: null as string | null,
    range_ends_on: null as string | null,
    location_type: 'microsoft_teams',
    location_detail: '',
    availability_schedule_id: null as number | null,
    user_id: null as number | null,
    group_id: null as number | null,
    is_active: true,
    is_hidden: false,
    host_ids: [] as number[],
    questions: [] as any[],
});

const showAllLocations = defineModel<boolean>('showAllLocations', {
    default: false,
});

const isOpen = computed(() => props.kind !== null);

const selectedKind = computed(() =>
    props.kinds.find((kind) => kind.value === form.kind),
);

const needsHostPool = computed(() => selectedKind.value?.requiresTeam ?? false);

const selectedGroup = computed(() =>
    props.groups.find((group) => group.id === form.group_id),
);

const selectedLocation = computed(() =>
    props.locationTypes.find(
        (location) => location.value === form.location_type,
    ),
);

const visibleLocations = computed(() =>
    showAllLocations.value
        ? props.locationTypes
        : props.locationTypes.filter((location) =>
              quickLocations.includes(location.value),
          ),
);

const selectedSchedule = computed(
    () =>
        props.schedules.find(
            (schedule) => schedule.id === form.availability_schedule_id,
        ) ??
        props.schedules.find((schedule) => schedule.isDefault) ??
        props.schedules[0],
);

const slugify = (value: string) =>
    value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

watch(
    () => form.name,
    (value) => {
        form.slug = slugify(value ?? '');
    },
);

/**
 * Who a new event type should belong to by default.
 *
 * Normally the person creating it, but a super admin belongs to no
 * organization, so defaulting to them submits a user_id that is not in
 * team_members and the save fails with "The selected user id is invalid".
 * Fall back to the first real member instead.
 */
const defaultHostId = computed(
    () =>
        props.teamMembers.find((member) => member.id === props.currentUser.id)
            ?.id ??
        props.teamMembers[0]?.id ??
        props.currentUser.id,
);

// Reset each time the panel is opened for a freshly chosen kind.
watch(
    () => props.kind,
    (kind) => {
        if (kind === null) {
            return;
        }

        form.reset();
        form.clearErrors();
        form.kind = kind;
        form.user_id = defaultHostId.value;
        form.group_id = null;
        form.seats_per_slot = kind === 'group' ? 5 : 1;
        form.host_ids = props.kinds.find((k) => k.value === kind)?.requiresTeam
            ? [defaultHostId.value]
            : [];
        form.availability_schedule_id =
            props.schedules.find((schedule) => schedule.isDefault)?.id ??
            props.schedules[0]?.id ??
            null;
        showAllLocations.value = false;
    },
);

const submit = () => {
    form.post(store(props.teamSlug).url, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <Sheet :open="isOpen" @update:open="(value) => !value && emit('close')">
        <SheetContent
            side="right"
            class="w-full gap-0 overflow-y-auto sm:max-w-md"
            data-test="create-event-type-panel"
        >
            <SheetHeader>
                <SheetTitle>Event type</SheetTitle>
                <SheetDescription>
                    {{ selectedKind?.description }}
                </SheetDescription>
            </SheetHeader>

            <form
                class="flex flex-col gap-6 px-4 pb-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-2">
                    <Label for="panel-name">Name</Label>
                    <Input
                        id="panel-name"
                        v-model="form.name"
                        data-test="panel-name"
                        required
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ selectedKind?.label }}
                    </p>
                    <InputError :message="form.errors.name" />
                    <InputError :message="form.errors.slug" />
                </div>

                <div class="grid gap-2">
                    <Label class="flex items-center gap-1.5">
                        <Clock class="size-3.5" /> Duration
                    </Label>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="preset in durationPresets"
                            :key="preset"
                            type="button"
                            size="sm"
                            :variant="
                                form.duration_minutes === preset
                                    ? 'default'
                                    : 'outline'
                            "
                            :data-test="`panel-duration-${preset}`"
                            @click="form.duration_minutes = preset"
                        >
                            {{ preset }} min
                        </Button>
                        <Input
                            v-model.number="form.duration_minutes"
                            type="number"
                            min="5"
                            class="w-24"
                            aria-label="Custom duration in minutes"
                        />
                    </div>
                    <InputError :message="form.errors.duration_minutes" />
                </div>

                <div v-if="form.kind === 'group'" class="grid gap-2">
                    <Label for="panel-seats">Invitees per slot</Label>
                    <Input
                        id="panel-seats"
                        v-model.number="form.seats_per_slot"
                        type="number"
                        min="1"
                        class="w-24"
                        data-test="panel-seats"
                    />
                    <p class="text-xs text-muted-foreground">
                        How many people can book the same time.
                    </p>
                    <InputError :message="form.errors.seats_per_slot" />
                </div>

                <div class="grid gap-2">
                    <Label>Location</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="location in visibleLocations"
                            :key="location.value"
                            type="button"
                            :data-test="`panel-location-${location.value}`"
                            :class="[
                                'inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition-colors',
                                form.location_type === location.value
                                    ? 'border-primary bg-accent font-medium'
                                    : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                            ]"
                            @click="form.location_type = location.value"
                        >
                            <component
                                :is="locationIcon(location.value)"
                                class="size-3.5"
                            />
                            {{ location.shortLabel }}
                        </button>

                        <button
                            v-if="!showAllLocations"
                            type="button"
                            class="inline-flex cursor-pointer items-center rounded-full px-3 py-1.5 text-sm text-primary underline"
                            data-test="panel-all-locations"
                            @click="showAllLocations = true"
                        >
                            All options
                        </button>
                    </div>

                    <div
                        v-if="selectedLocation?.requiresHostDetail"
                        class="grid gap-2"
                    >
                        <Label for="panel-location-detail">
                            {{ selectedLocation.detailLabel }}
                        </Label>
                        <Input
                            id="panel-location-detail"
                            v-model="form.location_detail"
                            data-test="panel-location-detail"
                        />
                        <InputError :message="form.errors.location_detail" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="panel-schedule">Availability</Label>
                    <Select
                        v-if="schedules.length"
                        v-model="form.availability_schedule_id"
                    >
                        <SelectTrigger
                            id="panel-schedule"
                            class="w-full"
                            data-test="panel-schedule"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="schedule in schedules"
                                :key="schedule.id"
                                :value="schedule.id"
                            >
                                {{ schedule.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        {{
                            selectedSchedule?.summary ??
                            'No available days or times.'
                        }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label class="flex items-center gap-1.5">
                        <Users class="size-3.5" />
                        {{ needsHostPool ? 'Hosts' : 'Host' }}
                    </Label>

                    <template v-if="!needsHostPool">
                        <Select
                            v-if="canAssignOwner && teamMembers.length > 1"
                            v-model="form.user_id"
                        >
                            <SelectTrigger
                                class="w-full"
                                data-test="panel-owner"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="member in teamMembers"
                                    :key="member.id"
                                    :value="member.id"
                                >
                                    {{ member.name }}
                                    <span v-if="member.id === currentUser.id">
                                        (you)
                                    </span>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <div v-else class="text-sm">
                            {{ currentUser.name }}
                            <span class="text-muted-foreground">(you)</span>
                        </div>
                        <InputError :message="form.errors.user_id" />
                    </template>

                    <template v-else>
                        <Select
                            v-if="groups.length"
                            :model-value="form.group_id ?? 'individual'"
                            @update:model-value="
                                (value) =>
                                    (form.group_id =
                                        value === 'individual'
                                            ? null
                                            : Number(value))
                            "
                        >
                            <SelectTrigger
                                class="w-full"
                                data-test="panel-group"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="individual">
                                    Pick members individually
                                </SelectItem>
                                <SelectItem
                                    v-for="group in groups"
                                    :key="group.id"
                                    :value="group.id"
                                >
                                    {{ group.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <p
                            v-if="selectedGroup"
                            class="text-xs text-muted-foreground"
                        >
                            Everyone in {{ selectedGroup.name }} hosts this, in
                            that team's order:
                            {{ selectedGroup.memberNames.join(', ') }}.
                        </p>

                        <HostPriorityList
                            v-else
                            :members="teamMembers"
                            :model-value="form.host_ids"
                            @update:model-value="(ids) => (form.host_ids = ids)"
                        />
                        <InputError :message="form.errors.host_ids" />
                    </template>
                </div>

                <div class="grid gap-2">
                    <Label for="panel-description">Description</Label>
                    <Textarea
                        id="panel-description"
                        v-model="form.description"
                        placeholder="What is this meeting about?"
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <SheetFooter class="flex-row justify-end gap-2 px-0">
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('close')"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="panel-submit"
                    >
                        Create
                    </Button>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>

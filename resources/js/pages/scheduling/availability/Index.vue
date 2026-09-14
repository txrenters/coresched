<script setup lang="ts">
import { Head, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import {
    CalendarDays,
    CalendarPlus,
    ChevronDown,
    Copy,
    MoreVertical,
    PauseCircle,
    PlayCircle,
    Plus,
    Repeat,
    Star,
    Users2,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import AvailabilityTabs from '@/components/scheduling/AvailabilityTabs.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCurrentTeam } from '@/composables/useCurrentTeam';
import { destroy, index, store, update } from '@/routes/availability';

type Rule = { day_of_week: number; starts_at: string; ends_at: string };

type Override = {
    date: string;
    is_unavailable: boolean;
    starts_at: string | null;
    ends_at: string | null;
};

type Schedule = {
    id: number;
    name: string;
    timezone: string;
    isDefault: boolean;
    isActive: boolean;
    isShared: boolean;
    eventTypeCount: number;
    summary: string | null;
    rules: Rule[];
    overrides: Override[];
};

type Props = {
    schedules: Schedule[];
    /** Hours the organization keeps, which govern every host of an event type. */
    sharedSchedules: Schedule[];
    canManageShared: boolean;
    timezones: string[];
};

const props = defineProps<Props>();

const { teamSlug } = useCurrentTeam();

/** Sunday first, matching how the week reads on the schedule card. */
const days = [
    { value: 0, initial: 'S', name: 'Sunday' },
    { value: 1, initial: 'M', name: 'Monday' },
    { value: 2, initial: 'T', name: 'Tuesday' },
    { value: 3, initial: 'W', name: 'Wednesday' },
    { value: 4, initial: 'T', name: 'Thursday' },
    { value: 5, initial: 'F', name: 'Friday' },
    { value: 6, initial: 'S', name: 'Saturday' },
];

/** Personal hours first, then the organization's, in one switcher. */
const allSchedules = computed(() => [
    ...props.schedules,
    ...props.sharedSchedules,
]);

const selectedId = ref<number | null>(props.schedules[0]?.id ?? null);
const creating = ref(false);
const creatingShared = ref(false);
const deleting = ref(false);

const selected = computed(
    () =>
        allSchedules.value.find(
            (schedule) => schedule.id === selectedId.value,
        ) ?? null,
);

/**
 * Accounts made by `user:super-admin` start with no schedule at all, and the
 * switcher renders a blank label when nothing is selected, leaving no visible
 * way to make the first one. Show a real empty state instead.
 */
const isEmpty = computed(
    () => allSchedules.value.length === 0 && !creating.value,
);

const buildForm = (schedule: Schedule | null) =>
    useForm({
        name: schedule?.name ?? 'Working hours',
        timezone:
            schedule?.timezone ??
            Intl.DateTimeFormat().resolvedOptions().timeZone ??
            'UTC',
        is_default: schedule?.isDefault ?? props.schedules.length === 0,
        is_active: schedule?.isActive ?? true,
        is_shared: schedule?.isShared ?? creatingShared.value,
        rules: schedule ? schedule.rules.map((rule) => ({ ...rule })) : [],
        overrides: schedule
            ? schedule.overrides.map((override) => ({ ...override }))
            : [],
    });

const form = ref(buildForm(selected.value));

const selectSchedule = (schedule: Schedule) => {
    creating.value = false;
    selectedId.value = schedule.id;
    form.value = buildForm(schedule);
};

const startCreating = (shared = false) => {
    creating.value = true;
    creatingShared.value = shared;
    selectedId.value = null;
    form.value = buildForm(null);
};

const rulesForDay = (day: number) =>
    form.value.rules.filter((rule) => rule.day_of_week === day);

const addBlock = (day: number) => {
    const existing = rulesForDay(day);

    form.value.rules.push({
        day_of_week: day,
        starts_at: existing.length ? '13:00' : '09:00',
        ends_at: existing.length ? '17:00' : '12:00',
    });
};

const removeRule = (rule: Rule) => {
    form.value.rules.splice(form.value.rules.indexOf(rule), 1);
};

/** Copy a day's hours onto every other day of the week. */
const copyToAllDays = (day: number) => {
    const source = rulesForDay(day);

    if (source.length === 0) {
        return;
    }

    form.value.rules = days.flatMap((target) =>
        source.map((rule) => ({ ...rule, day_of_week: target.value })),
    );
};

const addOverride = () => {
    form.value.overrides.push({
        // Today in the schedule's own timezone, not UTC, which is already
        // tomorrow on an American evening. 'en-CA' renders YYYY-MM-DD.
        date: new Date().toLocaleDateString('en-CA', {
            timeZone: form.value.timezone,
        }),
        is_unavailable: true,
        starts_at: null,
        ends_at: null,
    });
};

/**
 * Every message the server sent back, whether or not the field it belongs to
 * is rendered. An end time before its start used to bounce the save with the
 * error attached to a field nothing displayed, so pressing Save appeared to
 * do nothing at all.
 */
const errorMessages = computed(() =>
    Object.values(form.value.errors as Record<string, string>).filter(Boolean),
);

const errorFor = (key: string) =>
    (form.value.errors as Record<string, string>)[key];

const submit = () => {
    if (selected.value) {
        form.value.patch(
            update({
                current_team: teamSlug.value,
                availability: selected.value.id,
            }).url,
            { preserveScroll: true },
        );

        return;
    }

    form.value.post(store(teamSlug.value).url, {
        preserveScroll: true,
        onSuccess: () => (creating.value = false),
    });
};

const makeDefault = () => {
    form.value.is_default = true;
    submit();
};

/**
 * Switch a schedule off without deleting it. The resolver treats a disabled
 * schedule as no availability rather than falling back to another one, so
 * every event type it governs stops offering slots.
 */
const toggleActive = () => {
    form.value.is_active = !form.value.is_active;
    submit();
};

const confirmDelete = () => {
    if (!selected.value) {
        return;
    }

    router.delete(
        destroy({
            current_team: teamSlug.value,
            availability: selected.value.id,
        }).url,
        {
            onSuccess: () => {
                deleting.value = false;
                selectedId.value = props.schedules[0]?.id ?? null;
                form.value = buildForm(selected.value);
            },
        },
    );
};

setLayoutProps({
    breadcrumbs: [{ title: 'Availability', href: index(teamSlug.value) }],
});
</script>

<template>
    <Head title="Availability" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Availability"
            description="When you can be booked, and which calendars are consulted."
        />

        <AvailabilityTabs />

        <div class="rounded-xl border border-border bg-card shadow-flat">
            <div
                v-if="isEmpty"
                class="flex flex-col items-center gap-3 p-12 text-center"
            >
                <CalendarDays class="size-8 text-muted-foreground" />
                <div>
                    <h2 class="font-semibold">No availability yet</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Create a schedule to say when you can be booked. Your
                        event types stay unbookable until you do.
                    </p>
                </div>
                <Button
                    data-test="create-first-schedule"
                    @click="startCreating"
                >
                    <Plus class="size-4" /> Create schedule
                </Button>
            </div>

            <!-- Which schedule is being edited. -->
            <div
                v-if="!isEmpty"
                class="flex flex-wrap items-start justify-between gap-4 border-b p-6"
            >
                <div class="min-w-0">
                    <p class="text-sm font-medium text-muted-foreground">
                        Schedule
                    </p>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="mt-1 flex cursor-pointer items-center gap-1.5 text-lg font-semibold text-primary"
                                data-test="schedule-switcher"
                            >
                                {{ creating ? 'New schedule' : selected?.name }}
                                <span
                                    v-if="selected?.isDefault && !creating"
                                    class="font-normal"
                                >
                                    (default)
                                </span>
                                <span
                                    v-if="
                                        selected &&
                                        !selected.isActive &&
                                        !creating
                                    "
                                    class="rounded-md bg-warning-muted px-2 py-0.5 text-xs font-semibold text-warning-muted-foreground uppercase"
                                >
                                    Disabled
                                </span>
                                <span
                                    v-if="
                                        (creating && creatingShared) ||
                                        (selected?.isShared && !creating)
                                    "
                                    class="rounded-md bg-muted px-2 py-0.5 text-xs font-semibold text-muted-foreground uppercase"
                                >
                                    Shared
                                </span>
                                <ChevronDown class="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="w-64">
                            <DropdownMenuItem
                                v-for="schedule in schedules"
                                :key="schedule.id"
                                :data-test="`schedule-${schedule.id}`"
                                @select="selectSchedule(schedule)"
                            >
                                <Star
                                    v-if="schedule.isDefault"
                                    class="size-3.5 fill-current text-primary"
                                />
                                {{ schedule.name }}
                            </DropdownMenuItem>
                            <template v-if="sharedSchedules.length">
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel
                                    class="text-xs text-muted-foreground"
                                >
                                    Shared by the organization
                                </DropdownMenuLabel>
                                <DropdownMenuItem
                                    v-for="schedule in sharedSchedules"
                                    :key="schedule.id"
                                    :data-test="`schedule-${schedule.id}`"
                                    @select="selectSchedule(schedule)"
                                >
                                    <Users2 class="size-3.5" />
                                    {{ schedule.name }}
                                </DropdownMenuItem>
                            </template>

                            <DropdownMenuSeparator v-if="allSchedules.length" />
                            <DropdownMenuItem
                                data-test="new-schedule"
                                @select="startCreating(false)"
                            >
                                <Plus class="size-4" /> New schedule
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="canManageShared"
                                data-test="new-shared-schedule"
                                @select="startCreating(true)"
                            >
                                <Users2 class="size-4" /> New shared schedule
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <p class="mt-2 text-sm text-muted-foreground">
                        Active on:
                        <span class="font-medium text-foreground">
                            {{ selected?.eventTypeCount ?? 0 }} event
                            {{
                                (selected?.eventTypeCount ?? 0) === 1
                                    ? 'type'
                                    : 'types'
                            }}
                        </span>
                    </p>
                </div>

                <DropdownMenu v-if="selected">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Schedule actions"
                            data-test="schedule-actions"
                        >
                            <MoreVertical class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            :disabled="selected.isDefault"
                            @select="makeDefault"
                        >
                            <Star class="size-4" /> Set as default
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            data-test="toggle-schedule-active"
                            @select="toggleActive"
                        >
                            <component
                                :is="
                                    selected.isActive ? PauseCircle : PlayCircle
                                "
                                class="size-4"
                            />
                            {{ selected.isActive ? 'Disable' : 'Enable' }}
                            schedule
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="schedules.length > 1"
                            variant="destructive"
                            @select="deleting = true"
                        >
                            <Trash2 class="size-4" /> Delete
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <form v-if="!isEmpty" class="p-6" @submit.prevent="submit">
                <div
                    v-if="errorMessages.length"
                    class="mb-6 rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm"
                    role="alert"
                    data-test="availability-errors"
                >
                    <p class="font-medium">This schedule was not saved.</p>
                    <ul
                        class="mt-1 list-disc space-y-0.5 pl-4 text-muted-foreground"
                    >
                        <li v-for="message in errorMessages" :key="message">
                            {{ message }}
                        </li>
                    </ul>
                </div>

                <div class="grid gap-8 lg:grid-cols-2">
                    <!-- Weekly hours -->
                    <section>
                        <header class="mb-4">
                            <h2 class="flex items-center gap-2 font-semibold">
                                <Repeat class="size-4" /> Weekly hours
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Set when you are typically available for
                                meetings
                            </p>
                        </header>

                        <div class="flex flex-col gap-3">
                            <div
                                v-for="day in days"
                                :key="day.value"
                                class="flex items-start gap-3"
                            >
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground"
                                >
                                    <span aria-hidden="true">{{
                                        day.initial
                                    }}</span>
                                    <span class="sr-only">{{ day.name }}</span>
                                </span>

                                <div class="min-w-0 flex-1 space-y-2">
                                    <p
                                        v-if="
                                            rulesForDay(day.value).length === 0
                                        "
                                        class="py-1.5 text-sm text-muted-foreground"
                                    >
                                        Unavailable
                                    </p>

                                    <div
                                        v-for="rule in rulesForDay(day.value)"
                                        :key="`${day.value}-${form.rules.indexOf(rule)}`"
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <Input
                                            v-model="rule.starts_at"
                                            type="time"
                                            class="w-36"
                                            :data-test="`day-${day.value}-start`"
                                        />
                                        <span class="text-muted-foreground"
                                            >-</span
                                        >
                                        <Input
                                            v-model="rule.ends_at"
                                            type="time"
                                            class="w-36"
                                            :data-test="`day-${day.value}-end`"
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Remove hours"
                                            @click="removeRule(rule)"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                        <InputError
                                            :message="
                                                errorFor(
                                                    `rules.${form.rules.indexOf(rule)}.starts_at`,
                                                ) ??
                                                errorFor(
                                                    `rules.${form.rules.indexOf(rule)}.ends_at`,
                                                )
                                            "
                                        />
                                    </div>
                                </div>

                                <div class="flex shrink-0 gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Add hours on ${day.name}`"
                                        :data-test="`add-hours-${day.value}`"
                                        @click="addBlock(day.value)"
                                    >
                                        <Plus class="size-4" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Copy ${day.name} to all days`"
                                        :disabled="
                                            !rulesForDay(day.value).length
                                        "
                                        @click="copyToAllDays(day.value)"
                                    >
                                        <Copy class="size-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 max-w-xs">
                            <Select v-model="form.timezone">
                                <SelectTrigger
                                    class="w-full"
                                    data-test="schedule-timezone"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent class="max-h-72">
                                    <SelectItem
                                        v-for="zone in timezones"
                                        :key="zone"
                                        :value="zone"
                                    >
                                        {{ zone }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.timezone" />
                        </div>
                    </section>

                    <!-- Date-specific hours -->
                    <section>
                        <header
                            class="mb-4 flex flex-wrap items-start justify-between gap-3"
                        >
                            <div>
                                <h2
                                    class="flex items-center gap-2 font-semibold"
                                >
                                    <CalendarDays class="size-4" />
                                    Date-specific hours
                                </h2>
                                <p class="text-sm text-muted-foreground">
                                    Adjust hours for specific days
                                </p>
                            </div>

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="rounded-full"
                                data-test="add-override"
                                @click="addOverride"
                            >
                                <CalendarPlus class="size-3.5" /> Hours
                            </Button>
                        </header>

                        <p
                            v-if="!form.overrides.length"
                            class="text-sm text-muted-foreground"
                        >
                            No date-specific hours yet.
                        </p>

                        <div class="flex flex-col gap-3">
                            <div
                                v-for="(override, index) in form.overrides"
                                :key="index"
                                class="flex flex-wrap items-center gap-2 rounded-lg border p-3"
                            >
                                <Input
                                    v-model="override.date"
                                    type="date"
                                    class="w-40"
                                    :data-test="`override-date-${index}`"
                                />

                                <label class="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        v-model="override.is_unavailable"
                                    />
                                    Unavailable
                                </label>

                                <template v-if="!override.is_unavailable">
                                    <Input
                                        :model-value="override.starts_at ?? ''"
                                        type="time"
                                        class="w-36"
                                        @update:model-value="
                                            (value) =>
                                                (override.starts_at =
                                                    String(value))
                                        "
                                    />
                                    <span class="text-muted-foreground">-</span>
                                    <Input
                                        :model-value="override.ends_at ?? ''"
                                        type="time"
                                        class="w-36"
                                        @update:model-value="
                                            (value) =>
                                                (override.ends_at =
                                                    String(value))
                                        "
                                    />
                                </template>

                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Remove override"
                                    @click="form.overrides.splice(index, 1)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>
                    </section>
                </div>

                <div
                    class="mt-8 flex flex-wrap items-center justify-between gap-3 border-t pt-6"
                >
                    <div class="grid max-w-xs flex-1 gap-2">
                        <Label for="schedule-name">Schedule name</Label>
                        <Input
                            id="schedule-name"
                            v-model="form.name"
                            data-test="schedule-name"
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="save-schedule"
                    >
                        {{ creating ? 'Create schedule' : 'Save availability' }}
                    </Button>
                </div>
            </form>
        </div>
    </div>

    <Dialog v-model:open="deleting">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete {{ selected?.name }}?</DialogTitle>
                <DialogDescription>
                    Event types using this schedule fall back to your default
                    one.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="deleting = false">
                    Keep it
                </Button>
                <Button
                    variant="destructive"
                    data-test="confirm-delete-schedule"
                    @click="confirmDelete"
                >
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

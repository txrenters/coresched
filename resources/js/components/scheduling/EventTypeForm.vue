<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import HostPriorityList from '@/components/scheduling/HostPriorityList.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

type Option = { value: string; label: string };

type Props = {
    form: Record<string, any>;
    kinds: Array<Option & { description: string; requiresTeam: boolean }>;
    locationTypes: Array<
        Option & {
            isGenerated: boolean;
            requiresHostDetail: boolean;
            detailLabel: string | null;
        }
    >;
    questionTypes: Array<Option & { hasOptions: boolean }>;
    dateRangeTypes: Option[];
    schedules: Array<{
        id: number;
        name: string;
        timezone: string;
        isDefault: boolean;
    }>;
    teamMembers: Array<{ id: number; name: string; email: string }>;
    isSoloOrganization: boolean;
    groups: Array<{ id: number; name: string; memberNames: string[] }>;
    canAssignOwner: boolean;
    currentUser: { id: number; name: string };
    isEditing?: boolean;
};

const props = withDefaults(defineProps<Props>(), { isEditing: false });

/**
 * The parent's Inertia form object. It is shared by reference on purpose: this
 * component edits the same form the parent submits.
 */
const fields = props.form;

const selectedKind = computed(() =>
    props.kinds.find((kind) => kind.value === fields.kind),
);

const selectedLocation = computed(() =>
    props.locationTypes.find(
        (location) => location.value === fields.location_type,
    ),
);

const needsHostPool = computed(() => selectedKind.value?.requiresTeam ?? false);

const selectedGroup = computed(() =>
    props.groups.find((group) => group.id === fields.group_id),
);

const slugify = (value: string) =>
    value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

// Keep the slug in step with the name until the event type exists.
watch(
    () => fields.name,
    (value) => {
        if (!props.isEditing) {
            fields.slug = slugify(value ?? '');
        }
    },
);

const addQuestion = () => {
    fields.questions = [
        ...(fields.questions ?? []),
        {
            type: 'text',
            label: '',
            help_text: '',
            options: [],
            is_required: false,
        },
    ];
};

/**
 * Options are edited one field at a time rather than as lines of text: a
 * newline-separated box hides how many there are and loses one to a stray
 * blank line.
 */
const addOption = (question: Record<string, any>) => {
    question.options = [...(question.options ?? []), ''];
};

const setOption = (
    question: Record<string, any>,
    index: number,
    value: string,
) => {
    const options = [...(question.options ?? [])];
    options[index] = value;
    question.options = options;
};

const removeOption = (question: Record<string, any>, index: number) => {
    question.options = (question.options ?? []).filter(
        (_: string, at: number) => at !== index,
    );
};
</script>

<template>
    <div class="space-y-8">
        <section class="space-y-4">
            <h2 class="font-medium">Basics</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        v-model="fields.name"
                        data-test="event-type-name"
                        required
                    />
                    <InputError :message="fields.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="slug">Link</Label>
                    <Input
                        id="slug"
                        v-model="fields.slug"
                        data-test="event-type-slug"
                        required
                    />
                    <InputError :message="fields.errors.slug" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="description">Description</Label>
                <Textarea id="description" v-model="fields.description" />
                <InputError :message="fields.errors.description" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="kind">Type</Label>
                    <Select v-model="fields.kind" :disabled="isEditing">
                        <SelectTrigger
                            id="kind"
                            class="w-full"
                            data-test="event-type-kind"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="kind in kinds"
                                :key="kind.value"
                                :value="kind.value"
                                :disabled="
                                    kind.requiresTeam && isSoloOrganization
                                "
                            >
                                {{ kind.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ selectedKind?.description }}
                    </p>
                    <InputError :message="fields.errors.kind" />
                </div>

                <div class="grid gap-2">
                    <Label for="duration">Duration (minutes)</Label>
                    <Input
                        id="duration"
                        v-model.number="fields.duration_minutes"
                        type="number"
                        min="5"
                        data-test="event-type-duration"
                    />
                    <InputError :message="fields.errors.duration_minutes" />
                </div>

                <div class="grid gap-2">
                    <Label for="color">Colour</Label>
                    <Input
                        id="color"
                        v-model="fields.color"
                        type="color"
                        class="h-9 p-1"
                    />
                    <InputError :message="fields.errors.color" />
                </div>
            </div>
        </section>

        <section class="space-y-4 border-t pt-8">
            <div>
                <h2 class="font-medium">
                    {{ needsHostPool ? 'Hosts' : 'Host' }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        needsHostPool
                            ? fields.kind === 'collective'
                                ? 'Every host must be free, and all of them attend.'
                                : 'Bookings are shared out across these hosts.'
                            : 'Whose calendar this event type books against.'
                    }}
                </p>
            </div>

            <div v-if="!needsHostPool" class="grid gap-2 sm:max-w-sm">
                <Select
                    v-if="canAssignOwner && teamMembers.length > 1"
                    v-model="fields.user_id"
                >
                    <SelectTrigger class="w-full" data-test="event-type-owner">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="member in teamMembers"
                            :key="member.id"
                            :value="member.id"
                        >
                            {{ member.name }}
                            <span v-if="member.id === currentUser.id"
                                >(you)</span
                            >
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p v-else class="text-sm">
                    {{ currentUser.name }}
                    <span class="text-muted-foreground">(you)</span>
                </p>
                <InputError :message="fields.errors.user_id" />
            </div>

            <template v-else>
                <div v-if="groups.length" class="grid gap-2 sm:max-w-sm">
                    <Label for="host-group">Host from</Label>
                    <Select
                        id="host-group"
                        :model-value="fields.group_id ?? 'individual'"
                        @update:model-value="
                            (value) =>
                                (fields.group_id =
                                    value === 'individual'
                                        ? null
                                        : Number(value))
                        "
                    >
                        <SelectTrigger
                            class="w-full"
                            data-test="event-type-group"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="individual">
                                Pick people individually
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
                        Everyone in {{ selectedGroup.name }} hosts this event
                        type, in that team's priority order:
                        {{ selectedGroup.memberNames.join(', ') }}. Editing the
                        team updates every event type pointed at it.
                    </p>
                    <InputError :message="fields.errors.group_id" />
                </div>

                <div v-if="!selectedGroup">
                    <HostPriorityList
                        :members="teamMembers"
                        :model-value="fields.host_ids ?? []"
                        @update:model-value="(ids) => (fields.host_ids = ids)"
                    />
                </div>
                <InputError :message="fields.errors.host_ids" />
            </template>
        </section>

        <section class="space-y-4 border-t pt-8">
            <h2 class="font-medium">Location</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="location_type">How do you meet?</Label>
                    <Select v-model="fields.location_type">
                        <SelectTrigger
                            id="location_type"
                            class="w-full"
                            data-test="event-type-location"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="location in locationTypes"
                                :key="location.value"
                                :value="location.value"
                            >
                                {{ location.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="selectedLocation?.isGenerated"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        A joining link is created automatically when the booking
                        is written to the connected calendar.
                    </p>
                    <InputError :message="fields.errors.location_type" />
                </div>

                <div
                    v-if="selectedLocation?.requiresHostDetail"
                    class="grid gap-2"
                >
                    <Label for="location_detail">
                        {{ selectedLocation.detailLabel }}
                    </Label>
                    <Input
                        id="location_detail"
                        v-model="fields.location_detail"
                        data-test="event-type-location-detail"
                    />
                    <InputError :message="fields.errors.location_detail" />
                </div>
            </div>
        </section>

        <section class="space-y-4 border-t pt-8">
            <h2 class="font-medium">Availability</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="schedule">Schedule</Label>
                    <Select v-model="fields.availability_schedule_id">
                        <SelectTrigger id="schedule" class="w-full">
                            <SelectValue placeholder="My default schedule" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="schedule in schedules"
                                :key="schedule.id"
                                :value="schedule.id"
                            >
                                {{ schedule.name }} ({{ schedule.timezone }})
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError
                        :message="fields.errors.availability_schedule_id"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="minimum_notice">Minimum notice (minutes)</Label>
                    <Input
                        id="minimum_notice"
                        v-model.number="fields.minimum_notice_minutes"
                        type="number"
                        min="0"
                    />
                    <InputError
                        :message="fields.errors.minimum_notice_minutes"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="buffer_before">Buffer before (minutes)</Label>
                    <Input
                        id="buffer_before"
                        v-model.number="fields.buffer_before_minutes"
                        type="number"
                        min="0"
                    />
                    <InputError
                        :message="fields.errors.buffer_before_minutes"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="buffer_after">Buffer after (minutes)</Label>
                    <Input
                        id="buffer_after"
                        v-model.number="fields.buffer_after_minutes"
                        type="number"
                        min="0"
                    />
                    <InputError :message="fields.errors.buffer_after_minutes" />
                </div>

                <div class="grid gap-2">
                    <Label for="slot_interval"
                        >Start times every (minutes)</Label
                    >
                    <Input
                        id="slot_interval"
                        v-model.number="fields.slot_interval_minutes"
                        type="number"
                        min="5"
                        placeholder="Same as duration"
                    />
                    <InputError
                        :message="fields.errors.slot_interval_minutes"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="daily_limit">Bookings per day</Label>
                    <Input
                        id="daily_limit"
                        v-model.number="fields.daily_booking_limit"
                        type="number"
                        min="1"
                        placeholder="No limit"
                    />
                    <InputError :message="fields.errors.daily_booking_limit" />
                </div>

                <div v-if="fields.kind === 'group'" class="grid gap-2">
                    <Label for="seats">Seats per slot</Label>
                    <Input
                        id="seats"
                        v-model.number="fields.seats_per_slot"
                        type="number"
                        min="1"
                        data-test="event-type-seats"
                    />
                    <InputError :message="fields.errors.seats_per_slot" />
                </div>

                <div class="grid gap-2">
                    <Label for="date_range_type">How far ahead?</Label>
                    <Select v-model="fields.date_range_type">
                        <SelectTrigger id="date_range_type" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="range in dateRangeTypes"
                                :key="range.value"
                                :value="range.value"
                            >
                                {{ range.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="fields.errors.date_range_type" />
                </div>

                <div
                    v-if="fields.date_range_type === 'rolling_days'"
                    class="grid gap-2"
                >
                    <Label for="rolling_days">Days ahead</Label>
                    <Input
                        id="rolling_days"
                        v-model.number="fields.rolling_days"
                        type="number"
                        min="1"
                    />
                    <InputError :message="fields.errors.rolling_days" />
                </div>

                <template v-if="fields.date_range_type === 'fixed_range'">
                    <div class="grid gap-2">
                        <Label for="range_starts_on">From</Label>
                        <Input
                            id="range_starts_on"
                            v-model="fields.range_starts_on"
                            type="date"
                        />
                        <InputError :message="fields.errors.range_starts_on" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="range_ends_on">Until</Label>
                        <Input
                            id="range_ends_on"
                            v-model="fields.range_ends_on"
                            type="date"
                        />
                        <InputError :message="fields.errors.range_ends_on" />
                    </div>
                </template>
            </div>
        </section>

        <section class="space-y-4 border-t pt-8">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-medium">Booking questions</h2>
                    <p class="text-sm text-muted-foreground">
                        Name and email are always asked.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="add-question"
                    @click="addQuestion"
                >
                    <Plus class="size-3.5" /> Add question
                </Button>
            </div>
            <InputError :message="fields.errors.questions" />

            <div
                v-for="(question, index) in fields.questions ?? []"
                :key="index"
                class="space-y-3 rounded-lg border border-border bg-card p-4 shadow-flat"
            >
                <div class="grid gap-3 sm:grid-cols-[1fr_12rem]">
                    <div class="grid gap-2">
                        <Label :for="`question-label-${index}`">Question</Label>
                        <Input
                            :id="`question-label-${index}`"
                            v-model="question.label"
                            :data-test="`question-label-${index}`"
                        />
                        <InputError
                            :message="fields.errors[`questions.${index}.label`]"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`question-type-${index}`">Answer</Label>
                        <Select v-model="question.type">
                            <SelectTrigger
                                :id="`question-type-${index}`"
                                class="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="type in questionTypes"
                                    :key="type.value"
                                    :value="type.value"
                                >
                                    {{ type.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            :message="fields.errors[`questions.${index}.type`]"
                        />
                    </div>
                </div>

                <div
                    v-if="
                        questionTypes.find(
                            (type) => type.value === question.type,
                        )?.hasOptions
                    "
                    class="grid gap-2"
                >
                    <Label>Options</Label>
                    <div
                        v-for="(option, optionIndex) in question.options ?? []"
                        :key="optionIndex"
                        class="flex items-center gap-2"
                    >
                        <Input
                            :model-value="option"
                            :placeholder="`Option ${optionIndex + 1}`"
                            :data-test="`question-option-${index}-${optionIndex}`"
                            @update:model-value="
                                (value) =>
                                    setOption(
                                        question,
                                        optionIndex,
                                        String(value),
                                    )
                            "
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label="Remove option"
                            :data-test="`remove-option-${index}-${optionIndex}`"
                            @click="removeOption(question, optionIndex)"
                        >
                            <Trash2 class="size-3.5" />
                        </Button>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="justify-self-start"
                        :data-test="`add-option-${index}`"
                        @click="addOption(question)"
                    >
                        <Plus class="size-3.5" /> Add option
                    </Button>
                    <InputError
                        :message="fields.errors[`questions.${index}.options`]"
                    />
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model="question.is_required" />
                        Required
                    </label>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="fields.questions.splice(index, 1)"
                    >
                        <Trash2 class="size-3.5" /> Remove
                    </Button>
                </div>
            </div>
        </section>

        <section class="space-y-4 border-t pt-8">
            <h2 class="font-medium">Visibility</h2>

            <label class="flex items-center justify-between gap-4">
                <span class="text-sm">
                    Accepting bookings
                    <span class="block text-muted-foreground">
                        Turn off to pause this event type.
                    </span>
                </span>
                <Switch
                    v-model="fields.is_active"
                    data-test="event-type-active"
                />
            </label>

            <label class="flex items-center justify-between gap-4">
                <span class="text-sm">
                    Hidden from the booking page
                    <span class="block text-muted-foreground">
                        Only people with the direct link can book.
                    </span>
                </span>
                <Switch v-model="fields.is_hidden" />
            </label>

            <label class="flex items-center justify-between gap-4">
                <span class="text-sm">
                    Require confirmation
                    <span class="block text-muted-foreground">
                        New bookings wait as requests until a host approves
                        them.
                    </span>
                </span>
                <Switch
                    v-model="fields.requires_confirmation"
                    data-test="event-type-requires-confirmation"
                />
            </label>
        </section>
    </div>
</template>

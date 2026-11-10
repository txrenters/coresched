<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
import { Switch } from '@/components/ui/switch';
import { edit, index } from '@/routes/teams';
import { edit as editAdvanced, update } from '@/routes/teams/advanced';
import {
    destroy as destroyClosure,
    store as storeClosure,
} from '@/routes/teams/closures';
import type { Team } from '@/types';

type Holiday = {
    key: string;
    label: string;
    date: string;
    isObserved: boolean;
};

type Closure = {
    id: number;
    name: string;
    startsOn: string;
    endsOn: string;
};

type Props = {
    team: Team;
    /** Only admins change these; everyone else sees what applies to them. */
    canUpdate: boolean;
    holidayCountry: string | null;
    countries: Array<{ value: string; label: string }>;
    holidays: Holiday[];
    requiredHolidays: string[];
    closures: Closure[];
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: { team: Team }) => ({
        breadcrumbs: [
            { title: 'Organizations', href: index() },
            { title: props.team.name, href: edit(props.team.slug) },
            { title: 'Advanced settings', href: editAdvanced(props.team.slug) },
        ],
    }),
});

const holidayForm = useForm({
    holiday_country: props.holidayCountry,
    holidays: [...props.requiredHolidays],
});

const closureForm = useForm({
    name: '',
    starts_on: '',
    ends_on: '',
});

const toggleHoliday = (key: string, enabled: boolean) => {
    const keys = new Set(holidayForm.holidays);

    if (enabled) {
        keys.add(key);
    } else {
        keys.delete(key);
    }

    holidayForm.holidays = [...keys];
};

const saveHolidays = () => {
    holidayForm.patch(update(props.team.slug).url, { preserveScroll: true });
};

const addClosure = () => {
    closureForm.post(storeClosure(props.team.slug).url, {
        preserveScroll: true,
        onSuccess: () => closureForm.reset(),
    });
};

const removeClosure = (closure: Closure) => {
    router.delete(
        destroyClosure({ team: props.team.slug, closure: closure.id }).url,
        { preserveScroll: true },
    );
};

const formatDate = (date: string) =>
    new Date(`${date}T00:00:00Z`).toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    });

const formatRange = (closure: Closure) =>
    closure.startsOn === closure.endsOn
        ? formatDate(closure.startsOn)
        : `${formatDate(closure.startsOn)} – ${formatDate(closure.endsOn)}`;
</script>

<template>
    <Head :title="`Advanced settings · ${team.name}`" />

    <h1 class="sr-only">Advanced settings</h1>

    <div class="flex flex-col space-y-10">
        <div class="space-y-6">
            <Heading
                variant="small"
                title="Blocked dates"
                description="Days nobody in the organization can be booked, such as a company outing."
            />

            <form
                v-if="canUpdate"
                class="grid gap-4 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end"
                @submit.prevent="addClosure"
            >
                <div class="grid gap-2">
                    <Label for="closure-name">What is it?</Label>
                    <Input
                        id="closure-name"
                        v-model="closureForm.name"
                        placeholder="Company outing"
                        maxlength="120"
                        required
                        data-test="closure-name"
                    />
                    <InputError :message="closureForm.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="closure-starts-on">First day</Label>
                    <Input
                        id="closure-starts-on"
                        v-model="closureForm.starts_on"
                        type="date"
                        required
                        data-test="closure-starts-on"
                    />
                    <InputError :message="closureForm.errors.starts_on" />
                </div>
                <div class="grid gap-2">
                    <Label for="closure-ends-on">Last day (optional)</Label>
                    <Input
                        id="closure-ends-on"
                        v-model="closureForm.ends_on"
                        type="date"
                        :min="closureForm.starts_on || undefined"
                        data-test="closure-ends-on"
                    />
                    <InputError :message="closureForm.errors.ends_on" />
                </div>
                <Button
                    type="submit"
                    :disabled="closureForm.processing"
                    data-test="closure-add"
                >
                    Block dates
                </Button>
            </form>

            <p v-if="!closures.length" class="text-sm text-muted-foreground">
                No upcoming blocked dates.
            </p>

            <ul
                v-else
                class="divide-y divide-border rounded-xl border border-border"
            >
                <li
                    v-for="closure in closures"
                    :key="closure.id"
                    class="flex items-center justify-between gap-4 px-4 py-3"
                    data-test="closure-row"
                >
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium">
                            {{ closure.name }}
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            {{ formatRange(closure) }}
                        </span>
                    </span>
                    <Button
                        v-if="canUpdate"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Unblock ${closure.name}`"
                        @click="removeClosure(closure)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </li>
            </ul>
        </div>

        <div class="space-y-6">
            <Heading
                variant="small"
                title="Holidays"
                description="Everyone in the organization is unavailable on the holidays switched on here."
            />

            <form class="space-y-4" @submit.prevent="saveHolidays">
                <div class="grid max-w-xs gap-2">
                    <Label for="holiday-country">Country for holidays</Label>
                    <Select
                        :model-value="holidayForm.holiday_country ?? 'none'"
                        :disabled="!canUpdate"
                        @update:model-value="
                            (value) =>
                                (holidayForm.holiday_country =
                                    value === 'none' ? null : String(value))
                        "
                    >
                        <SelectTrigger
                            id="holiday-country"
                            class="w-full"
                            data-test="holiday-country"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">No holidays</SelectItem>
                            <SelectItem
                                v-for="country in countries"
                                :key="country.value"
                                :value="country.value"
                            >
                                {{ country.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="holidayForm.errors.holiday_country" />
                </div>

                <p
                    v-if="!holidays.length"
                    class="text-sm text-muted-foreground"
                >
                    Choose a country, then save, to pick the holidays.
                </p>

                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="holiday in holidays"
                        :key="holiday.key"
                        class="flex items-center justify-between gap-4 py-3"
                    >
                        <span class="min-w-0">
                            <span class="block text-sm font-medium">
                                {{ holiday.label }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                Next: {{ formatDate(holiday.date) }}
                                <template v-if="holiday.isObserved">
                                    (observed)
                                </template>
                            </span>
                        </span>

                        <Switch
                            :model-value="
                                holidayForm.holidays.includes(holiday.key)
                            "
                            :disabled="!canUpdate"
                            :data-test="`holiday-${holiday.key}`"
                            @update:model-value="
                                (value) =>
                                    toggleHoliday(holiday.key, Boolean(value))
                            "
                        />
                    </li>
                </ul>
                <InputError :message="holidayForm.errors.holidays" />

                <Button
                    v-if="canUpdate"
                    type="submit"
                    :disabled="holidayForm.processing"
                    data-test="save-holidays"
                >
                    Save holidays
                </Button>
            </form>
        </div>
    </div>
</template>

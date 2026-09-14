<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';

type Props = {
    /** The visible month as YYYY-MM. */
    month: string;
    /** Dates carrying the marker dot, as YYYY-MM-DD. */
    availableDates: string[];
    selectedDate: string | null;
    loading?: boolean;
    minMonth?: string;
    /** Today as YYYY-MM-DD in the calendar's timezone; defaults to the viewer's local date. */
    today?: string;
    /** Grid aria-label prefix; the month name is appended. */
    gridLabel?: string;
    /** Announced after the date on days carrying the marker. */
    markedDayLabel?: string;
    /** Announced after the date on days without it. */
    unmarkedDayLabel?: string;
};

const props = withDefaults(defineProps<Props>(), {
    loading: false,
    minMonth: undefined,
    // 'en-CA' renders the local date as YYYY-MM-DD.
    today: () => new Date().toLocaleDateString('en-CA'),
    gridLabel: 'Available dates',
    markedDayLabel: 'times available',
    unmarkedDayLabel: 'no times available',
});

const emit = defineEmits<{
    (e: 'update:month', month: string): void;
    (e: 'select', date: string): void;
}>();

const weekdayLabels = [
    { short: 'Sun', full: 'Sunday' },
    { short: 'Mon', full: 'Monday' },
    { short: 'Tue', full: 'Tuesday' },
    { short: 'Wed', full: 'Wednesday' },
    { short: 'Thu', full: 'Thursday' },
    { short: 'Fri', full: 'Friday' },
    { short: 'Sat', full: 'Saturday' },
];

const available = computed(() => new Set(props.availableDates));

const monthStart = computed(() => {
    const [y, m] = props.month.split('-').map((part) => Number(part));

    return new Date(Date.UTC(y, m - 1, 1));
});

const monthLabel = computed(() =>
    monthStart.value.toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }),
);

/** The calendar grid, padded so the month always starts on a Sunday. */
const cells = computed(() => {
    const start = monthStart.value;
    const daysInMonth = new Date(
        Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + 1, 0),
    ).getUTCDate();

    // getUTCDay() is already 0 for Sunday, which is the first column.
    const leading = start.getUTCDay();
    const result: Array<{ date: string; day: number } | null> =
        Array(leading).fill(null);

    for (let day = 1; day <= daysInMonth; day++) {
        const date = `${start.getUTCFullYear()}-${String(start.getUTCMonth() + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        result.push({ date, day });
    }

    return result;
});

const dayLabel = (date: string) =>
    new Date(`${date}T00:00:00Z`).toLocaleDateString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    });

const canGoBack = computed(
    () => !props.minMonth || props.month > props.minMonth,
);

const shiftMonth = (delta: number) => {
    if (delta < 0 && !canGoBack.value) {
        return;
    }

    const start = monthStart.value;
    const shifted = new Date(
        Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + delta, 1),
    );
    const next = `${shifted.getUTCFullYear()}-${String(shifted.getUTCMonth() + 1).padStart(2, '0')}`;

    emit('update:month', next);
};

/*
  Roving tabindex: the grid holds a single tab stop and the arrow keys move
  between days, so a keyboard user reaches the times in a few keystrokes
  instead of tabbing past every day in the month.
*/
const focusedDate = ref<string | null>(null);

const dayButtons = ref<Record<string, HTMLButtonElement | null>>({});

const setDayButton = (date: string, el: unknown) => {
    dayButtons.value[date] = (el as HTMLButtonElement | null) ?? null;
};

const monthDates = computed(() =>
    cells.value.filter((cell) => cell !== null).map((cell) => cell!.date),
);

/** The day that owns the grid's tab stop: selection, else first open day, else the 1st. */
const tabStopDate = computed(() => {
    if (
        focusedDate.value &&
        monthDates.value.includes(focusedDate.value) &&
        available.value.has(focusedDate.value)
    ) {
        return focusedDate.value;
    }

    if (props.selectedDate && monthDates.value.includes(props.selectedDate)) {
        return props.selectedDate;
    }

    return (
        monthDates.value.find((date) => available.value.has(date)) ??
        monthDates.value[0] ??
        null
    );
});

/** Set when the keyboard paged the month, so focus follows into the new grid. */
const refocusAfterMonthChange = ref(false);

const focusDate = async (date: string) => {
    focusedDate.value = date;
    await nextTick();
    dayButtons.value[date]?.focus();
};

// A new month renders a new set of days, so the old focus target is gone.
watch(
    () => props.month,
    async () => {
        focusedDate.value = null;
        dayButtons.value = {};

        if (!refocusAfterMonthChange.value) {
            return;
        }

        refocusAfterMonthChange.value = false;
        await nextTick();

        if (tabStopDate.value) {
            dayButtons.value[tabStopDate.value]?.focus();
        }
    },
);

/*
  Days without times are disabled buttons and cannot take focus, so the arrow
  keys step over them to the next open day in that direction.
*/
const moveFocus = async (from: string, delta: number) => {
    let index = monthDates.value.indexOf(from);

    if (index === -1) {
        return;
    }

    for (;;) {
        index += delta;
        const next = monthDates.value[index];

        if (!next) {
            // Stepping past either edge advances the month rather than dead-ending.
            if (delta < 0 && !canGoBack.value) {
                return;
            }

            refocusAfterMonthChange.value = true;
            shiftMonth(delta > 0 ? 1 : -1);

            return;
        }

        if (available.value.has(next)) {
            await focusDate(next);

            return;
        }
    }
};

const onKeydown = (event: KeyboardEvent, date: string) => {
    const deltas: Record<string, number> = {
        ArrowLeft: -1,
        ArrowRight: 1,
        ArrowUp: -7,
        ArrowDown: 7,
    };

    if (event.key in deltas) {
        event.preventDefault();
        void moveFocus(date, deltas[event.key]);

        return;
    }

    if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        const openDates = monthDates.value.filter((day) =>
            available.value.has(day),
        );
        const target =
            event.key === 'Home'
                ? openDates[0]
                : openDates[openDates.length - 1];

        if (target) {
            void focusDate(target);
        }
    }
};
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between gap-2">
            <span
                class="text-base font-semibold tracking-tight"
                aria-live="polite"
            >
                {{ monthLabel }}
            </span>
            <div class="flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-9 cursor-pointer rounded-full disabled:cursor-not-allowed"
                    :disabled="!canGoBack"
                    :aria-label="`Previous month, ${monthLabel}`"
                    data-test="calendar-previous-month"
                    @click="shiftMonth(-1)"
                >
                    <ChevronLeft class="size-4" aria-hidden="true" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-9 cursor-pointer rounded-full"
                    :aria-label="`Next month, ${monthLabel}`"
                    data-test="calendar-next-month"
                    @click="shiftMonth(1)"
                >
                    <ChevronRight class="size-4" aria-hidden="true" />
                </Button>
            </div>
        </div>

        <div
            role="grid"
            :aria-label="`${gridLabel} in ${monthLabel}`"
            :aria-busy="loading"
        >
            <div
                role="row"
                class="mb-1 grid grid-cols-7 gap-1 text-center text-xs font-medium text-muted-foreground"
            >
                <span
                    v-for="label in weekdayLabels"
                    :key="label.short"
                    role="columnheader"
                    :aria-label="label.full"
                >
                    {{ label.short }}
                </span>
            </div>

            <div class="grid grid-cols-7 gap-1">
                <template v-for="(cell, index) in cells" :key="index">
                    <div v-if="cell === null" role="gridcell" />
                    <div v-else role="gridcell" class="relative">
                        <button
                            :ref="(el) => setDayButton(cell.date, el)"
                            type="button"
                            :disabled="loading || !available.has(cell.date)"
                            :tabindex="tabStopDate === cell.date ? 0 : -1"
                            :aria-pressed="selectedDate === cell.date"
                            :aria-label="
                                available.has(cell.date)
                                    ? `${dayLabel(cell.date)}, ${markedDayLabel}`
                                    : `${dayLabel(cell.date)}, ${unmarkedDayLabel}`
                            "
                            :data-test="`calendar-day-${cell.date}`"
                            :class="[
                                'flex aspect-square w-full cursor-pointer items-center justify-center rounded-full text-sm transition-colors duration-200',
                                'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card focus-visible:outline-none',
                                selectedDate === cell.date
                                    ? 'bg-primary font-semibold text-primary-foreground'
                                    : available.has(cell.date)
                                      ? 'bg-accent font-semibold text-accent-foreground hover:bg-primary hover:text-primary-foreground'
                                      : 'cursor-not-allowed font-normal text-muted-foreground/60',
                                cell.date === today &&
                                    selectedDate !== cell.date &&
                                    'ring-1 ring-primary/40 ring-inset',
                            ]"
                            @keydown="onKeydown($event, cell.date)"
                            @click="emit('select', cell.date)"
                        >
                            <span data-numeric>{{ cell.day }}</span>
                        </button>
                        <!--
                          A dot repeats the "has times" state that colour alone
                          would otherwise carry.
                        -->
                        <span
                            v-if="
                                available.has(cell.date) &&
                                selectedDate !== cell.date
                            "
                            class="pointer-events-none absolute bottom-1 left-1/2 size-1 -translate-x-1/2 rounded-full bg-primary"
                            aria-hidden="true"
                        />
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

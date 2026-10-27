<script setup lang="ts">
import { computed } from 'vue';

/*
  A ranked list of horizontal bars: one hue, value at the tip in text colour.
  Few enough rows that every value is labelled, so it needs no tooltip and no
  separate table; the list is the table.
*/
const props = defineProps<{
    items: Array<{ label: string; value: number }>;
    /** When set, bars are a share of this and the tip reads "3 of 5". */
    total?: number;
}>();

const max = computed(
    () => props.total ?? Math.max(1, ...props.items.map((item) => item.value)),
);

const width = (value: number) =>
    max.value > 0 ? `${Math.max(0, (value / max.value) * 100)}%` : '0%';
</script>

<template>
    <ul class="grid gap-3">
        <li
            v-for="item in items"
            :key="item.label"
            class="grid grid-cols-[9rem_1fr_auto] items-center gap-3 text-sm"
        >
            <span class="truncate text-muted-foreground">{{ item.label }}</span>
            <span
                class="h-2 overflow-hidden rounded-full bg-muted"
                role="presentation"
            >
                <span
                    class="block h-full rounded-full bg-chart-1 transition-[width] duration-500"
                    :style="{ width: width(item.value) }"
                />
            </span>
            <span class="text-right font-medium tabular-nums">
                {{ item.value.toLocaleString() }}
                <span
                    v-if="total !== undefined"
                    class="font-normal text-muted-foreground"
                    >of {{ total.toLocaleString() }}</span
                >
            </span>
        </li>
    </ul>
</template>

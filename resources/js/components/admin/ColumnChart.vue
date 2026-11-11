<script setup lang="ts">
import { StackedBar } from '@unovis/ts';
import { VisAxis, VisStackedBar, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';
import type { ChartConfig } from '@/components/ui/chart';
import { ChartContainer, ChartTooltip } from '@/components/ui/chart';

type Point = { label: string; value: number };

/** What Unovis 1.7 binds to each stacked-bar element: the row is wrapped. */
type BarRecord = { datum: Point; index: number };

const props = defineProps<{
    data: Point[];
    /** What one bar counts, e.g. "Bookings"; shown in the tooltip and table. */
    seriesLabel: string;
}>();

/*
  One series, so no legend: the card title names it. --chart-1 is the brand
  blue in light mode and its lighter step in dark (resources/css/app.css),
  checked against each mode's surface with the dataviz palette validator.
*/
const config = computed<ChartConfig>(() => ({
    value: { label: props.seriesLabel, color: 'var(--chart-1)' },
}));

const x = (_: Point, index: number) => index;
const y = (point: Point) => point.value;

/**
 * At most six x labels, always on whole bars, so a 30-day series doesn't
 * crowd its ticks into wrapped two-line labels.
 */
const tickValues = computed(() => {
    const step = Math.max(1, Math.ceil(props.data.length / 6));

    return props.data
        .map((_, index) => index)
        .filter((index) => index % step === 0);
});

const tickFormat = (index: number) => props.data[index]?.label ?? '';

const escapeHtml = (text: string) =>
    text.replace(
        /[&<>"']/g,
        (character) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[character] ?? character,
    );

/**
 * Tooltip markup for one bar. Built here rather than with the shadcn
 * componentToString helper: that helper reads label/value off the top level
 * (Unovis 1.7 wraps the row in `datum`, so it rendered empty) and calls useId()
 * on the client only, which shifted every chart id after hydration and left
 * the bars without their --color-value.
 */
const tooltipTemplate = (record: BarRecord | Point) => {
    const point = 'datum' in record ? record.datum : record;

    return `<div class="grid min-w-32 gap-1.5 rounded-lg border border-border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow-overlay">
        <div class="font-medium">${escapeHtml(point.label)}</div>
        <div class="flex items-center gap-2">
            <span class="size-2.5 shrink-0 rounded-xs bg-(--color-value)"></span>
            <span class="flex-1 text-muted-foreground">${escapeHtml(props.seriesLabel)}</span>
            <span class="font-mono font-medium tabular-nums">${point.value.toLocaleString()}</span>
        </div>
    </div>`;
};

/** Whole-number y ticks: these are counts. */
const yTickFormat = (value: number) =>
    Number.isInteger(value) ? value.toLocaleString() : '';

const max = computed(() =>
    Math.max(0, ...props.data.map((point) => point.value)),
);
</script>

<template>
    <div>
        <ChartContainer :config="config" class="aspect-auto h-56">
            <VisXYContainer
                :data="data"
                :margin="{ left: 4, right: 4 }"
                :y-domain="[0, Math.max(4, max)]"
            >
                <VisStackedBar
                    :x="x"
                    :y="y"
                    color="var(--color-value)"
                    :bar-max-width="24"
                    :rounded-corners="4"
                    :bar-padding="0.25"
                />
                <VisAxis
                    type="x"
                    :x="x"
                    :tick-line="false"
                    :domain-line="false"
                    :grid-line="false"
                    :tick-values="tickValues"
                    :tick-format="tickFormat"
                />
                <VisAxis
                    type="y"
                    :num-ticks="4"
                    :tick-line="false"
                    :domain-line="false"
                    :tick-format="yTickFormat"
                />
                <ChartTooltip
                    :triggers="{ [StackedBar.selectors.bar]: tooltipTemplate }"
                />
            </VisXYContainer>
        </ChartContainer>

        <!-- The same numbers as text, for screen readers and anyone who prefers a table. -->
        <details class="mt-2 text-xs text-muted-foreground">
            <summary class="cursor-pointer select-none">View as table</summary>
            <table class="mt-2 w-full">
                <thead>
                    <tr class="text-left">
                        <th class="py-1 font-medium">Period</th>
                        <th class="py-1 text-right font-medium">
                            {{ seriesLabel }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="point in data"
                        :key="point.label"
                        class="border-t border-border"
                    >
                        <td class="py-1">{{ point.label }}</td>
                        <td class="py-1 text-right tabular-nums">
                            {{ point.value.toLocaleString() }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </details>
    </div>
</template>

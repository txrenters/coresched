<script setup lang="ts">
import { StackedBar } from '@unovis/ts';
import { VisAxis, VisStackedBar, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';
import type { ChartConfig } from '@/components/ui/chart';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
} from '@/components/ui/chart';

type Point = { label: string; value: number };

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

/** Label every other bar when there are many, so ticks never collide. */
const tickFormat = (index: number) => {
    const step = props.data.length > 8 ? 2 : 1;

    return index % step === 0 ? (props.data[index]?.label ?? '') : '';
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
                    :num-ticks="data.length"
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
                    :triggers="{
                        [StackedBar.selectors.bar]: componentToString(
                            config,
                            ChartTooltipContent,
                            { labelKey: 'label' },
                        ),
                    }"
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

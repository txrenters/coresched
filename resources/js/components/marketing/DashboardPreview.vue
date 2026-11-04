<script setup lang="ts">
/** Illustrative only — a sample day of meetings. */
const meetings = [
    {
        time: '9:00',
        title: 'Product demo',
        who: 'Dana Lee · Northwind',
        status: 'Confirmed',
    },
    {
        time: '10:30',
        title: 'Onboarding session',
        who: 'Sam Ortiz · Fabrikam',
        status: 'Confirmed',
    },
    {
        time: '1:00',
        title: 'Account review',
        who: 'Priya N. · Contoso',
        status: 'Needs approval',
    },
    {
        time: '3:30',
        title: 'Discovery call',
        who: 'Lee Chen · Tailspin',
        status: 'Confirmed',
    },
];

const stats = [
    { label: 'Today', value: '4' },
    { label: 'This week', value: '27' },
    { label: 'No-shows', value: '1' },
];
</script>

<template>
    <div class="rounded-xl border border-border bg-background p-4">
        <div class="grid grid-cols-3 gap-2">
            <div
                v-for="stat in stats"
                :key="stat.label"
                class="rounded-lg bg-muted px-3 py-2"
            >
                <p class="text-[10px] text-muted-foreground">
                    {{ stat.label }}
                </p>
                <p class="text-lg font-bold tracking-tight">
                    {{ stat.value }}
                </p>
            </div>
        </div>
        <ul class="mt-3 divide-y divide-border text-xs">
            <li
                v-for="meeting in meetings"
                :key="meeting.time"
                class="flex items-center gap-3 py-2"
            >
                <span class="w-10 font-semibold tabular-nums">{{
                    meeting.time
                }}</span>
                <span class="flex-1 truncate">
                    <span class="block truncate font-medium">{{
                        meeting.title
                    }}</span>
                    <span class="block truncate text-muted-foreground">{{
                        meeting.who
                    }}</span>
                </span>
                <span
                    :class="[
                        'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold',
                        meeting.status === 'Confirmed'
                            ? 'bg-success-muted text-success-muted-foreground'
                            : 'bg-accent text-accent-foreground',
                    ]"
                >
                    {{ meeting.status }}
                </span>
            </li>
        </ul>
    </div>
</template>

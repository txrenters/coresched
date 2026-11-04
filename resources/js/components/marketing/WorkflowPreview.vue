<script setup lang="ts">
import { CalendarCheck, Mail, MessageSquare, UserX } from '@lucide/vue';

/** Illustrative only — a sample workflow timeline. */
const steps = [
    { icon: CalendarCheck, when: 'When booked', what: 'Email confirmation' },
    { icon: MessageSquare, when: '24 hours before', what: 'SMS reminder' },
    { icon: Mail, when: '1 hour after', what: 'Follow-up email' },
    { icon: UserX, when: 'On no-show', what: 'Reschedule link' },
];
</script>

<template>
    <ol
        class="relative grid gap-3 rounded-xl border border-border bg-background p-4"
    >
        <li
            v-for="(step, index) in steps"
            :key="step.what"
            class="relative flex items-center gap-3 text-xs"
        >
            <span
                v-if="index < steps.length - 1"
                class="absolute top-8 left-3.5 h-3 w-px bg-border"
            />
            <span
                class="flex size-7 shrink-0 items-center justify-center rounded-full bg-success-muted text-success-muted-foreground"
            >
                <component :is="step.icon" class="size-3.5" />
            </span>
            <span class="flex-1">
                <span class="block font-semibold">{{ step.what }}</span>
                <span class="text-muted-foreground">{{ step.when }}</span>
            </span>
        </li>
    </ol>
</template>

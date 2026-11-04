<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import type { SetupStep } from '@/types/admin';

const props = defineProps<{
    organizationId: number;
    setup: Record<SetupStep, boolean>;
}>();

const steps: Array<{ key: SetupStep; label: string }> = [
    { key: 'calendar', label: 'Calendar connected' },
    { key: 'eventTypes', label: 'Event types' },
    { key: 'twilio', label: 'Twilio' },
    { key: 'smsNumber', label: 'SMS number' },
    { key: 'booked', label: 'First booking' },
];

const done = computed(
    () => steps.filter((step) => props.setup[step.key]).length,
);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <span class="mr-1 text-sm font-medium">
            Setup {{ done }}/{{ steps.length }}
        </span>
        <span
            v-for="step in steps"
            :key="step.key"
            :class="[
                'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium',
                setup[step.key]
                    ? 'border-transparent bg-success-muted text-success-muted-foreground'
                    : 'border-dashed border-border text-muted-foreground',
            ]"
            :data-test="`setup-${step.key}-${organizationId}`"
        >
            <Check v-if="setup[step.key]" class="size-3" aria-hidden="true" />
            {{ step.label }}
            <span class="sr-only">{{
                setup[step.key] ? 'done' : 'not done'
            }}</span>
        </span>
    </div>
</template>

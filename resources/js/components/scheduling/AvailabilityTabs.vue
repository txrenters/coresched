<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useCurrentTeam } from '@/composables/useCurrentTeam';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { calendars, index } from '@/routes/availability';

const { teamSlug } = useCurrentTeam();
const { isCurrentUrl } = useCurrentUrl();

const tabs = [
    { label: 'Schedules', href: () => index(teamSlug.value) },
    { label: 'Calendar settings', href: () => calendars(teamSlug.value) },
];
</script>

<template>
    <nav class="flex gap-6 border-b" aria-label="Availability">
        <Link
            v-for="tab in tabs"
            :key="tab.label"
            :href="tab.href()"
            :data-test="`availability-tab-${tab.label.split(' ')[0].toLowerCase()}`"
            :class="[
                'border-b-2 pb-3 text-sm transition-colors',
                isCurrentUrl(tab.href())
                    ? 'border-primary font-medium text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground',
            ]"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>

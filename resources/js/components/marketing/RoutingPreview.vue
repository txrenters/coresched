<script setup lang="ts">
import { Shuffle } from '@lucide/vue';

/** Illustrative only — sample hosts in a round-robin pool. */
const hosts = [
    { initials: 'MR', name: 'Maria R.', load: 4, next: false },
    { initials: 'JT', name: 'James T.', load: 2, next: true },
    { initials: 'AK', name: 'Aisha K.', load: 3, next: false },
];
</script>

<template>
    <div class="rounded-xl border border-border bg-background p-4">
        <div class="flex items-center justify-between text-xs">
            <span class="font-semibold">Discovery call</span>
            <span
                class="inline-flex items-center gap-1 rounded-full bg-accent px-2 py-0.5 font-medium text-accent-foreground"
            >
                <Shuffle class="size-3" />
                Round-robin
            </span>
        </div>
        <ul class="mt-3 grid gap-2">
            <li
                v-for="host in hosts"
                :key="host.name"
                :class="[
                    'flex items-center gap-3 rounded-lg border px-3 py-2 text-xs',
                    host.next
                        ? 'border-primary/50 bg-primary/5'
                        : 'border-border',
                ]"
            >
                <span
                    class="flex size-7 items-center justify-center rounded-full bg-muted text-[10px] font-semibold"
                >
                    {{ host.initials }}
                </span>
                <span class="flex-1 font-medium">{{ host.name }}</span>
                <span class="text-muted-foreground"
                    >{{ host.load }} this week</span
                >
                <span
                    v-if="host.next"
                    class="rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold text-primary-foreground"
                >
                    Next up
                </span>
            </li>
        </ul>
    </div>
</template>

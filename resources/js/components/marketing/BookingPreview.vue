<script setup lang="ts">
import { Video } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

/** Illustrative only — a static rendering of the public booking page. */
const weekdays = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
const openDays = [4, 5, 11, 18, 19, 25];
const slots = ['9:00 am', '9:30 am', '10:00 am', '11:30 am'];
</script>

<template>
    <div
        class="rounded-2xl border border-border bg-card p-5 shadow-overlay sm:p-6"
    >
        <div class="flex items-center gap-3 border-b border-border pb-4">
            <AppLogoIcon class="size-9" />
            <div>
                <p class="text-sm font-semibold">Product demo</p>
                <p
                    class="flex items-center gap-1 text-xs text-muted-foreground"
                >
                    <Video class="size-3.5" />
                    30 min · Microsoft Teams
                </p>
            </div>
        </div>

        <div class="mt-5 grid gap-5 sm:grid-cols-[1fr_auto]">
            <div>
                <p class="mb-3 text-xs font-semibold">March 2026</p>
                <div class="grid grid-cols-7 gap-1 text-center text-[11px]">
                    <span
                        v-for="(day, index) in weekdays"
                        :key="index"
                        class="py-1 font-medium text-muted-foreground"
                    >
                        {{ day }}
                    </span>
                    <span
                        v-for="day in 28"
                        :key="day"
                        :class="[
                            'flex aspect-square items-center justify-center rounded-full',
                            day === 12
                                ? 'bg-primary font-semibold text-primary-foreground'
                                : openDays.includes(day)
                                  ? 'bg-accent font-semibold text-accent-foreground'
                                  : 'text-muted-foreground/50',
                        ]"
                    >
                        {{ day }}
                    </span>
                </div>
            </div>

            <div class="w-full sm:w-28">
                <p class="mb-3 text-xs font-semibold">Thu 12</p>
                <div class="grid gap-2">
                    <span
                        v-for="(slot, index) in slots"
                        :key="slot"
                        :class="[
                            'rounded-lg border py-2 text-center text-xs font-semibold',
                            index === 1
                                ? 'border-foreground bg-foreground text-background'
                                : 'border-primary/40 text-primary',
                        ]"
                    >
                        {{ slot }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

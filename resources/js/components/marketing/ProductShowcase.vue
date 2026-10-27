<script setup lang="ts">
import {
    CalendarDays,
    CalendarRange,
    LayoutDashboard,
    ListChecks,
} from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Real screenshots of the app, captured from a demo organization in both
 * themes (public/screenshots). Both variants are in the DOM and the .dark
 * class picks one, the same way AppLogoIcon swaps the logo.
 */
const tabs = [
    {
        key: 'booking',
        label: 'Booking page',
        icon: CalendarDays,
        caption:
            'What invitees see: open times from every host in the pool, in their own timezone.',
        width: 1440,
        height: 680,
    },
    {
        key: 'dashboard',
        label: 'Dashboard',
        icon: LayoutDashboard,
        caption:
            'Your next meeting, the rest of today, and what is waiting on your approval.',
        width: 1440,
        height: 900,
    },
    {
        key: 'meetings',
        label: 'Meetings',
        icon: ListChecks,
        caption:
            'Everything booked across the organization, filtered by person, team or date.',
        width: 1440,
        height: 900,
    },
    {
        key: 'scheduling',
        label: 'Event types',
        icon: CalendarRange,
        caption:
            'Every meeting your teams offer, with its link, QR code and who hosts it.',
        width: 1440,
        height: 900,
    },
] as const;

/** How long each tab shows before the next; matches --tab-duration below. */
const tabDurationMs = 6000;

const active = ref(0);
const autoplay = ref(false);
const paused = ref(false);

let timer: ReturnType<typeof setInterval> | undefined;

function select(index: number): void {
    active.value = (index + tabs.length) % tabs.length;
    // A deliberate choice stops the rotation for good.
    stopAutoplay();
}

function stopAutoplay(): void {
    autoplay.value = false;
    clearInterval(timer);
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
        event.preventDefault();
        select(active.value + (event.key === 'ArrowRight' ? 1 : -1));
        document
            .getElementById(`showcase-tab-${tabs[active.value].key}`)
            ?.focus();
    }
}

onMounted(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    autoplay.value = true;
    timer = setInterval(() => {
        if (!paused.value) {
            active.value = (active.value + 1) % tabs.length;
        }
    }, tabDurationMs);
});

onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div
        @mouseenter="paused = true"
        @mouseleave="paused = false"
        @focusin="paused = true"
        @focusout="paused = false"
    >
        <div
            role="tablist"
            aria-label="Product screens"
            class="mx-auto flex w-fit max-w-full gap-1 overflow-x-auto rounded-full border border-border bg-card p-1 shadow-flat"
            @keydown="onKeydown"
        >
            <button
                v-for="(tab, index) in tabs"
                :id="`showcase-tab-${tab.key}`"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="active === index"
                :aria-controls="`showcase-panel-${tab.key}`"
                :tabindex="active === index ? 0 : -1"
                :class="[
                    'relative inline-flex shrink-0 cursor-pointer items-center gap-2 overflow-hidden rounded-full px-4 py-2 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    active === index
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                @click="select(index)"
            >
                <component :is="tab.icon" class="size-4" aria-hidden="true" />
                {{ tab.label }}
                <span
                    v-if="autoplay && active === index"
                    :key="`progress-${active}`"
                    class="absolute inset-x-3 bottom-1 h-0.5 origin-left animate-tab-progress rounded-full bg-primary-foreground/60"
                    :class="{ '[animation-play-state:paused]': paused }"
                    :style="{ '--tab-duration': `${tabDurationMs}ms` }"
                    aria-hidden="true"
                />
            </button>
        </div>

        <div class="relative mt-8">
            <div
                class="pointer-events-none absolute -inset-x-8 -inset-y-6 -z-10 rounded-[2rem] bg-linear-to-b from-primary/15 to-transparent blur-2xl"
                aria-hidden="true"
            />

            <!-- Browser-window frame -->
            <div
                class="overflow-hidden rounded-2xl border border-border bg-card shadow-overlay"
            >
                <div
                    class="flex items-center gap-2 border-b border-border bg-muted/60 px-4 py-2.5"
                    aria-hidden="true"
                >
                    <span
                        class="size-2.5 rounded-full bg-muted-foreground/30"
                    />
                    <span
                        class="size-2.5 rounded-full bg-muted-foreground/30"
                    />
                    <span
                        class="size-2.5 rounded-full bg-muted-foreground/30"
                    />
                </div>

                <Transition
                    mode="out-in"
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="opacity-0 translate-y-2"
                    leave-active-class="transition duration-150 ease-in"
                    leave-to-class="opacity-0"
                >
                    <div
                        :id="`showcase-panel-${tabs[active].key}`"
                        :key="tabs[active].key"
                        role="tabpanel"
                        :aria-labelledby="`showcase-tab-${tabs[active].key}`"
                    >
                        <img
                            :src="`/screenshots/${tabs[active].key}-light.webp`"
                            :alt="`${tabs[active].label}: ${tabs[active].caption}`"
                            :width="tabs[active].width"
                            :height="tabs[active].height"
                            loading="lazy"
                            decoding="async"
                            class="block h-auto w-full dark:hidden"
                        />
                        <img
                            :src="`/screenshots/${tabs[active].key}-dark.webp`"
                            :alt="`${tabs[active].label}: ${tabs[active].caption}`"
                            :width="tabs[active].width"
                            :height="tabs[active].height"
                            loading="lazy"
                            decoding="async"
                            class="hidden h-auto w-full dark:block"
                        />
                    </div>
                </Transition>
            </div>
        </div>

        <p
            class="mx-auto mt-6 max-w-xl text-center text-sm text-muted-foreground"
        >
            {{ tabs[active].caption }}
        </p>
    </div>
</template>

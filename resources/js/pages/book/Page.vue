<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    CalendarClock,
    Clock,
    User,
    Users,
} from '@lucide/vue';
import { locationIcon } from '@/lib/locationIcons';

type Props = {
    page: {
        slug: string;
        name: string;
        welcomeMessage: string | null;
        isTeam: boolean;
        logoUrl: string | null;
        websiteUrl: string | null;
        websiteName: string | null;
    };
    eventTypes: Array<{
        slug: string;
        name: string;
        description: string | null;
        color: string;
        durationMinutes: number;
        kind: string;
        kindLabel: string;
        /** Hosted by a pool (round robin, collective) rather than one person. */
        isShared: boolean;
        locationType: string;
        locationLabel: string;
        url: string;
    }>;
    /**
     * Set only when this is one team's landing page, and used to offer a way
     * back up to the organization that owns it.
     */
    parent?: {
        name: string;
        url: string;
    } | null;
};

withDefaults(defineProps<Props>(), {
    parent: null,
});
</script>

<template>
    <Head :title="page.name" />

    <div class="min-h-svh bg-background px-4 py-12 sm:py-20">
        <div class="mx-auto w-full max-w-2xl">
            <p v-if="parent" class="mb-6 text-center text-sm">
                <a
                    :href="parent.url"
                    data-test="back-to-organization"
                    class="inline-flex items-center gap-1.5 rounded-md font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    <ArrowLeft class="size-3.5" aria-hidden="true" />
                    {{ parent.name }}
                </a>
            </p>

            <header class="text-center">
                <!--
                  Decorative: the organisation name is rendered right below it,
                  so the logo would only repeat the same information.
                -->
                <img
                    v-if="page.logoUrl"
                    :src="page.logoUrl"
                    alt=""
                    class="mx-auto mb-5 h-16 w-auto max-w-52 object-contain"
                />
                <h1
                    class="text-3xl font-bold tracking-tight text-balance sm:text-4xl"
                    data-wrap-anywhere
                >
                    {{ page.name }}
                </h1>
                <p
                    class="mx-auto mt-3 max-w-prose leading-relaxed text-muted-foreground"
                >
                    {{
                        page.welcomeMessage ??
                        `Pick a meeting below to see when ${page.isTeam ? 'we are' : 'I am'} free.`
                    }}
                </p>
            </header>

            <ul v-if="eventTypes.length" class="mt-10 space-y-3">
                <li v-for="eventType in eventTypes" :key="eventType.url">
                    <a
                        :href="eventType.url"
                        :data-test="`event-type-${eventType.slug}`"
                        class="group flex items-stretch gap-4 rounded-xl border border-border bg-card p-5 shadow-flat transition-colors duration-200 hover:border-primary hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        <!-- The organiser's own colour, so it stays a raw value. -->
                        <span
                            class="w-1.5 shrink-0 rounded-full"
                            :style="{ backgroundColor: eventType.color }"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1">
                            <span
                                class="block font-semibold"
                                data-wrap-anywhere
                            >
                                {{ eventType.name }}
                            </span>
                            <span
                                v-if="eventType.description"
                                class="mt-1 block text-sm leading-relaxed text-muted-foreground"
                                data-wrap-anywhere
                            >
                                {{ eventType.description }}
                            </span>
                            <span
                                class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs font-medium text-muted-foreground"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <Clock
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <span data-numeric>
                                        {{ eventType.durationMinutes }} min
                                    </span>
                                </span>
                                <span
                                    class="inline-flex items-center gap-1.5"
                                    :data-test="`event-type-kind-${eventType.slug}`"
                                >
                                    <component
                                        :is="eventType.isShared ? Users : User"
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{ eventType.kindLabel }}
                                </span>
                                <span class="inline-flex items-center gap-1.5">
                                    <component
                                        :is="
                                            locationIcon(eventType.locationType)
                                        "
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{ eventType.locationLabel }}
                                </span>
                            </span>
                        </span>
                        <ArrowRight
                            class="mt-0.5 size-4 shrink-0 self-center text-muted-foreground transition-colors duration-200 group-hover:text-primary"
                            aria-hidden="true"
                        />
                    </a>
                </li>
            </ul>

            <div
                v-else
                class="mt-10 rounded-xl border border-dashed border-border px-6 py-12 text-center"
            >
                <CalendarClock
                    class="mx-auto size-6 text-muted-foreground"
                    aria-hidden="true"
                />
                <p class="mt-3 font-medium">Nothing available right now</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    There is nothing open to book on this page yet. Check back
                    soon.
                </p>
            </div>
            <p v-if="page.websiteUrl" class="mt-10 text-center text-sm">
                <a
                    :href="page.websiteUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rounded-md font-medium text-primary underline underline-offset-2 hover:no-underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    {{ page.websiteName ?? page.name }} website
                </a>
            </p>
        </div>
    </div>
</template>

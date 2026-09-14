<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarX,
    Hourglass,
    LayoutDashboard,
    House,
    RotateCw,
    SearchX,
    ServerCrash,
    ShieldAlert,
    Wrench,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home } from '@/routes';

const props = defineProps<{
    status: number;
}>();

type ErrorCopy = {
    icon: Component;
    title: string;
    description: string;
};

const copy: Record<number, ErrorCopy> = {
    403: {
        icon: ShieldAlert,
        title: 'You don’t have access to this page',
        description:
            'Your account isn’t allowed to open this. If you think it should be, ask an admin of your organization.',
    },
    404: {
        icon: SearchX,
        title: 'We couldn’t find that page',
        description:
            'The link may be broken, or the page may have been moved or removed. Booking links stop working when a meeting type is turned off.',
    },
    410: {
        icon: CalendarX,
        title: 'This can no longer be changed',
        description:
            'The meeting has already been moved, canceled, or its meeting type was removed. Check your latest confirmation email for the current details.',
    },
    429: {
        icon: Hourglass,
        title: 'Too many requests',
        description:
            'You’ve made a lot of requests in a short time. Wait a minute, then try again.',
    },
    500: {
        icon: ServerCrash,
        title: 'Something went wrong on our end',
        description:
            'The error has been logged and we’ll look into it. Try again in a moment.',
    },
    503: {
        icon: Wrench,
        title: 'Down for maintenance',
        description:
            'We’re making some improvements and will be back shortly. Thanks for your patience.',
    },
};

const error = computed(() => copy[props.status] ?? copy[500]);

/*
 * Server errors render with only the app name, not the shared props (see
 * AppServiceProvider::renderErrorPages), so auth and currentTeam may be absent.
 */
const page = usePage();

const appName = computed(() => page.props.name);

const dashboardUrl = computed(() =>
    page.props.auth?.user && page.props.currentTeam
        ? dashboard(page.props.currentTeam.slug).url
        : null,
);

const isRetryable = computed(() => [429, 500, 503].includes(props.status));

function goBack(): void {
    window.history.back();
}

function reload(): void {
    window.location.reload();
}
</script>

<template>
    <Head :title="error.title" />

    <div
        class="flex min-h-svh flex-col items-center justify-center bg-background p-6 md:p-10"
    >
        <div class="w-full max-w-lg">
            <a
                :href="home().url"
                class="mx-auto flex w-fit items-center gap-2.5 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                <AppLogoIcon class="size-9" />
                <span class="font-bold tracking-tight">{{ appName }}</span>
            </a>

            <main
                class="mt-8 rounded-2xl border border-border bg-card p-6 text-center shadow-raised sm:p-10"
            >
                <div
                    class="mx-auto flex size-14 items-center justify-center rounded-full bg-accent text-primary"
                >
                    <component
                        :is="error.icon"
                        class="size-7"
                        aria-hidden="true"
                    />
                </div>

                <p
                    class="mt-6 text-sm font-semibold tracking-widest text-primary uppercase"
                >
                    Error {{ status }}
                </p>

                <h1
                    class="mt-2 text-2xl font-bold tracking-tight text-balance sm:text-3xl"
                >
                    {{ error.title }}
                </h1>

                <p
                    class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-pretty text-muted-foreground sm:text-base"
                >
                    {{ error.description }}
                </p>

                <div
                    class="mt-8 flex flex-col-reverse justify-center gap-3 sm:flex-row"
                >
                    <Button
                        v-if="isRetryable"
                        variant="outline"
                        class="cursor-pointer"
                        @click="reload"
                    >
                        <RotateCw aria-hidden="true" />
                        Try again
                    </Button>
                    <Button
                        v-else
                        variant="outline"
                        class="cursor-pointer"
                        @click="goBack"
                    >
                        <ArrowLeft aria-hidden="true" />
                        Go back
                    </Button>

                    <!--
                      Plain anchors, not <Link>: after a server error the
                      client state may be what broke, so leave with a full load.
                    -->
                    <Button v-if="dashboardUrl" as-child>
                        <a :href="dashboardUrl">
                            <LayoutDashboard aria-hidden="true" />
                            Go to dashboard
                        </a>
                    </Button>
                    <Button v-else as-child>
                        <a :href="home().url">
                            <House aria-hidden="true" />
                            Go to home page
                        </a>
                    </Button>
                </div>
            </main>
        </div>
    </div>
</template>

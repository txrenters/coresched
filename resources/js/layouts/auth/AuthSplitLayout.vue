<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

const page = usePage();
const name = page.props.name;

defineProps<{
    title?: string;
    description?: string;
}>();

const highlights = [
    'One link replaces the scheduling email thread',
    'Google and Microsoft calendars stay in sync',
    'Round-robin, collective and group events',
];
</script>

<template>
    <div
        class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
    >
        <!--
          The brand panel is the one surface that stays brand blue in both
          themes, so it uses the fixed brand-* tokens rather than primary-*,
          which inverts to a light blue under .dark.
        -->
        <div
            class="relative hidden h-full flex-col justify-between bg-brand p-10 text-brand-foreground lg:flex"
        >
            <Link
                :href="home()"
                class="flex w-fit items-center gap-2.5 rounded-md text-lg font-bold tracking-tight focus-visible:ring-2 focus-visible:ring-brand-foreground focus-visible:ring-offset-2 focus-visible:ring-offset-brand focus-visible:outline-none"
            >
                <!--
                  The mark is navy + green, so it needs a neutral chip to sit
                  on rather than the brand blue itself.
                -->
                <span
                    class="flex size-9 items-center justify-center rounded-lg bg-card"
                >
                    <AppLogoIcon class="size-7" />
                </span>
                {{ name }}
            </Link>

            <div>
                <p
                    class="max-w-md text-2xl font-bold tracking-tight text-balance"
                >
                    Stop emailing back and forth to find a time.
                </p>
                <ul class="mt-6 space-y-3">
                    <li
                        v-for="highlight in highlights"
                        :key="highlight"
                        class="flex items-start gap-2.5 text-sm opacity-90"
                    >
                        <Check
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{ highlight }}
                    </li>
                </ul>
            </div>
        </div>

        <div class="lg:p-8">
            <div
                class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]"
            >
                <div class="flex flex-col space-y-2 text-center">
                    <h1 v-if="title" class="text-xl font-bold tracking-tight">
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>

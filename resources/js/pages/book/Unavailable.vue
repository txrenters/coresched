<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarOff } from '@lucide/vue';

defineProps<{
    pageName: string;
}>();
</script>

<template>
    <Head :title="`${pageName} is not taking bookings`" />

    <div class="min-h-svh bg-background px-4 py-12 sm:py-20">
        <div
            class="mx-auto w-full max-w-lg rounded-2xl border border-border bg-card p-8 shadow-raised"
            data-test="booking-unavailable"
        >
            <span
                class="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"
            >
                <CalendarOff class="size-5" aria-hidden="true" />
            </span>
            <h1 class="mt-4 text-xl font-bold tracking-tight">
                Not taking bookings right now
            </h1>
            <p class="mt-1.5 text-sm text-muted-foreground">
                {{ pageName }} is not accepting new bookings at the moment.
                Please contact them directly.
            </p>
        </div>
    </div>
</template>

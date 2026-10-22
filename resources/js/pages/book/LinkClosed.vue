<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { UserRoundCheck } from '@lucide/vue';

defineProps<{
    eventTypeName: string;
    pageName: string;
}>();
</script>

<template>
    <Head title="An agent is already handling your inquiry" />

    <div class="min-h-svh bg-background px-4 py-12 sm:py-20">
        <div
            class="mx-auto w-full max-w-lg rounded-2xl border border-border bg-card p-8 shadow-raised"
            data-test="booking-link-closed"
        >
            <span
                class="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"
            >
                <UserRoundCheck class="size-5" aria-hidden="true" />
            </span>
            <h1 class="mt-4 text-xl font-bold tracking-tight">
                An agent is already handling your inquiry
            </h1>
            <p class="mt-1.5 text-sm text-muted-foreground">
                They will contact you shortly, so there is no need to book
                {{ eventTypeName }} with {{ pageName }}.
            </p>
        </div>
    </div>
</template>

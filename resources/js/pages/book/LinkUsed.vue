<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarX } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{
    eventTypeName: string;
    pageName: string;
    pageUrl: string;
}>();
</script>

<template>
    <Head title="Link already used" />

    <div class="min-h-svh bg-background px-4 py-12 sm:py-20">
        <div
            class="mx-auto w-full max-w-lg rounded-2xl border border-border bg-card p-8 shadow-raised"
            data-test="single-use-link-used"
        >
            <span
                class="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"
            >
                <CalendarX class="size-5" aria-hidden="true" />
            </span>
            <h1 class="mt-4 text-xl font-bold tracking-tight">
                This link has already been used
            </h1>
            <p class="mt-1.5 text-sm text-muted-foreground">
                The link to book {{ eventTypeName }} worked for one booking
                only. Ask {{ pageName }} for a new one, or see what else you can
                book.
            </p>
            <Button as-child variant="outline" class="mt-6">
                <a :href="pageUrl">Go to {{ pageName }}</a>
            </Button>
        </div>
    </div>
</template>

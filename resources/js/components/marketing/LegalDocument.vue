<script setup lang="ts">
import MarketingFooter from '@/components/marketing/MarketingFooter.vue';
import MarketingHeader from '@/components/marketing/MarketingHeader.vue';

defineProps<{
    title: string;
    updated: string;
}>();
</script>

<template>
    <div class="min-h-svh overflow-x-clip bg-background">
        <MarketingHeader />

        <main
            class="mx-auto w-full max-w-3xl px-4 pt-16 pb-20 sm:px-6 sm:pt-24"
        >
            <p class="text-sm font-semibold text-primary">Legal</p>
            <h1 class="mt-2 text-4xl font-bold tracking-tight sm:text-5xl">
                {{ title }}
            </h1>
            <p class="mt-4 text-sm text-muted-foreground">
                Last updated {{ updated }}
            </p>

            <div
                class="mt-10 space-y-8 text-base leading-7 text-muted-foreground [&_a]:font-medium [&_a]:text-primary [&_a]:underline-offset-4 hover:[&_a]:underline [&_h2]:mb-3 [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-foreground [&_li]:mt-1.5 [&_p+p]:mt-3 [&_strong]:text-foreground [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-6"
            >
                <slot />
            </div>
        </main>

        <MarketingFooter />
    </div>
</template>

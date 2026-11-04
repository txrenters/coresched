<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { logout, pricing } from '@/routes';

const page = usePage();
</script>

<template>
    <!--
      Shown on every app page while a visitor explores the shared demo, so
      nobody mistakes it for their own account or expects an email to arrive.
    -->
    <div
        v-if="page.props.isDemo"
        class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-primary px-4 py-2 text-sm text-primary-foreground"
        role="status"
        data-test="demo-banner"
    >
        <Sparkles class="size-4 shrink-0" aria-hidden="true" />
        <span>
            You're exploring the demo. Try anything: no emails or texts are
            sent, and it resets every night.
        </span>
        <Link
            :href="`${pricing().url}#contact`"
            class="rounded-md bg-primary-foreground/15 px-2.5 py-1 font-semibold hover:bg-primary-foreground/25 focus-visible:ring-2 focus-visible:ring-primary-foreground focus-visible:outline-none"
        >
            Get CoreSched
        </Link>
        <Link
            :href="logout()"
            as="button"
            class="cursor-pointer rounded-md px-2.5 py-1 font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-primary-foreground focus-visible:outline-none"
        >
            Leave demo
        </Link>
    </div>
</template>

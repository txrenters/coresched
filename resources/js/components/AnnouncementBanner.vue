<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Megaphone, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const page = usePage();

const announcement = computed(() => page.props.announcement);

/** Dismissal is a per-browser convenience; the bell keeps the message. */
const storageKey = (id: number) => `announcement-dismissed-${id}`;

const readDismissed = (id: number): boolean => {
    try {
        return window.localStorage.getItem(storageKey(id)) === '1';
    } catch {
        return false;
    }
};

const dismissed = ref(false);

watch(
    () => announcement.value?.id,
    (id) => (dismissed.value = id ? readDismissed(id) : false),
    { immediate: true },
);

const dismiss = () => {
    const id = announcement.value?.id;

    dismissed.value = true;

    if (!id) {
        return;
    }

    try {
        window.localStorage.setItem(storageKey(id), '1');
    } catch {
        // Storage can be blocked; the banner simply returns on the next visit.
    }
};
</script>

<template>
    <!--
      A super admin's announcement, shown on every app page until its banner
      runs out or this person dismisses it.
    -->
    <div
        v-if="announcement && !dismissed"
        class="flex items-start justify-center gap-3 border-b border-border bg-accent px-4 py-2.5 text-sm text-accent-foreground"
        role="status"
        data-test="announcement-banner"
    >
        <Megaphone class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <p class="min-w-0" data-wrap-anywhere>
            <strong class="font-semibold">{{ announcement.title }}</strong>
            <span class="mx-1.5" aria-hidden="true">·</span>
            <span>{{ announcement.body }}</span>
        </p>
        <button
            type="button"
            class="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md hover:bg-background/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            aria-label="Dismiss announcement"
            data-test="dismiss-announcement"
            @click="dismiss"
        >
            <X class="size-4" aria-hidden="true" />
        </button>
    </div>
</template>

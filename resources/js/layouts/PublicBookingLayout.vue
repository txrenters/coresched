<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';

const page = usePage();
</script>

<template>
    <div>
        <!--
          The demo's booking pages are edited by anyone trying the demo, and
          everyone trying it can see the bookings made here.
        -->
        <div
            v-if="page.props.isDemoBookingPage"
            class="flex items-center justify-center gap-2 bg-primary px-4 py-2 text-center text-sm text-primary-foreground"
            role="note"
            data-test="demo-booking-notice"
        >
            <TriangleAlert class="size-4 shrink-0" aria-hidden="true" />
            <span>
                This is a CoreSched demo, not a real business. Its content may
                be written by other visitors, and anyone trying the demo can see
                what you enter here, so please don't use real details.
            </span>
        </div>
        <slot />
    </div>
</template>

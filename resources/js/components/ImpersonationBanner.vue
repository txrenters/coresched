<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { UserRoundCog } from '@lucide/vue';
import { destroy as stopImpersonating } from '@/routes/impersonation';

const page = usePage();
</script>

<template>
    <!--
      Shown on every app page while a super admin is signed in as someone
      else, so it is never forgotten whose account is open.
    -->
    <div
        v-if="page.props.impersonator"
        class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-primary px-4 py-2 text-sm text-primary-foreground"
        role="status"
        data-test="impersonation-banner"
    >
        <UserRoundCog class="size-4 shrink-0" aria-hidden="true" />
        <span>
            You are signed in as
            <strong>{{ page.props.auth.user?.name }}</strong
            >. Account security settings are locked.
        </span>
        <Link
            :href="stopImpersonating()"
            as="button"
            class="cursor-pointer rounded-md bg-primary-foreground/15 px-2.5 py-1 font-semibold hover:bg-primary-foreground/25 focus-visible:ring-2 focus-visible:ring-primary-foreground focus-visible:outline-none"
        >
            Return to {{ page.props.impersonator.name }}
        </Link>
    </div>
</template>

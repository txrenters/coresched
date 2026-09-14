<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/invitations/join';

defineProps<{
    invitation: {
        code: string;
        email: string;
        teamName: string;
        roleLabel: string;
        inviterName: string | null;
    };
}>();

defineOptions({
    layout: {
        title: 'You’re invited',
        description: 'Join your organization to start taking bookings',
    },
});
</script>

<template>
    <Head title="Join organization" />

    <div class="space-y-6">
        <div
            class="rounded-lg border border-border bg-muted/50 p-4 text-sm leading-relaxed"
            data-test="join-invitation-summary"
        >
            <p>
                <template v-if="invitation.inviterName">
                    <span class="font-semibold">{{
                        invitation.inviterName
                    }}</span>
                    invited
                </template>
                <template v-else>You were invited as</template>
                <span class="font-semibold break-all">{{
                    invitation.email
                }}</span>
                to join
                <span class="font-semibold">{{ invitation.teamName }}</span>
                as {{ invitation.roleLabel.toLowerCase() }}.
            </p>
        </div>

        <!--
          Joining is a POST on purpose: mail scanners open the emailed link on
          their own, so only this button creates the account and signs in.
        -->
        <Form v-bind="store.form(invitation.code)" v-slot="{ processing }">
            <Button
                type="submit"
                class="w-full"
                :disabled="processing"
                data-test="join-invitation-button"
            >
                <Spinner v-if="processing" />
                Join {{ invitation.teamName }}
            </Button>
        </Form>

        <p class="text-center text-sm text-muted-foreground">
            Not {{ invitation.email }}?
            <TextLink :href="login()">Log in</TextLink>
        </p>
    </div>
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { plan as updatePlan } from '@/routes/admin/organizations';
import type { ConsoleOptions, OrganizationAdminSummary } from '@/types/admin';

const props = defineProps<{
    /** The organization being edited; null keeps the dialog closed. */
    organization: {
        name: string;
        slug: string;
        admin: OrganizationAdminSummary;
    } | null;
    plans: ConsoleOptions['plans'];
    senders: ConsoleOptions['senders'];
}>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({ plan: '', plan_note: '', mail_from_address: '' });

watch(
    () => props.organization,
    (organization) => {
        if (!organization) {
            return;
        }

        form.clearErrors();
        form.plan = organization.admin.plan ?? '';
        form.plan_note = organization.admin.planNote ?? '';
        form.mail_from_address = organization.admin.mailFrom ?? '';
    },
    { immediate: true },
);

const save = () => {
    if (!props.organization) {
        return;
    }

    form.transform((data) => ({
        ...data,
        plan: data.plan || null,
        mail_from_address: data.mail_from_address || null,
    })).patch(updatePlan(props.organization.slug).url, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';
</script>

<template>
    <Dialog
        :open="organization !== null"
        @update:open="(open) => !open && emit('close')"
    >
        <DialogContent>
            <form class="grid gap-5" @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>
                        Plan &amp; email for {{ organization?.name }}
                    </DialogTitle>
                    <DialogDescription>
                        The plan is a label for your records: it switches no
                        features on or off, and the organization never sees the
                        note.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="plan">Plan</Label>
                    <select
                        id="plan"
                        v-model="form.plan"
                        :class="selectClass"
                        data-test="plan-select"
                    >
                        <option value="">No plan</option>
                        <option
                            v-for="plan in plans"
                            :key="plan.value"
                            :value="plan.value"
                        >
                            {{ plan.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.plan" />
                </div>

                <div class="grid gap-2">
                    <Label for="mail-from">Booking emails from</Label>
                    <select
                        id="mail-from"
                        v-model="form.mail_from_address"
                        :class="selectClass"
                        :disabled="!senders.organization.length"
                        data-test="mail-from-select"
                    >
                        <option value="">
                            {{ senders.default }} (platform)
                        </option>
                        <option
                            v-for="sender in senders.organization"
                            :key="sender"
                            :value="sender"
                        >
                            {{ sender }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Confirmations, reminders and workflow emails. The sender
                        name is always the organization's. Account emails always
                        come from the platform.
                        <template v-if="!senders.organization.length">
                            Add addresses with MAIL_ORG_SENDERS to offer more.
                        </template>
                    </p>
                    <InputError :message="form.errors.mail_from_address" />
                </div>

                <div class="grid gap-2">
                    <Label for="plan-note">Note</Label>
                    <Textarea
                        id="plan-note"
                        v-model="form.plan_note"
                        rows="3"
                        placeholder="e.g. Annual contract, renews in March"
                    />
                    <InputError :message="form.errors.plan_note" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="emit('close')"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="save-plan"
                    >
                        Save
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

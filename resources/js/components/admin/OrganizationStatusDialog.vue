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
import { status as updateStatus } from '@/routes/admin/organizations';

const props = defineProps<{
    /** The organization being switched; null keeps the dialog closed. */
    organization: { name: string; slug: string; disabled: boolean } | null;
}>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({ disabled: true, reason: '' });

watch(
    () => props.organization,
    (organization) => {
        if (organization) {
            form.clearErrors();
            form.reason = '';
            // The dialog always proposes the opposite of the current state.
            form.disabled = !organization.disabled;
        }
    },
    { immediate: true },
);

const save = () => {
    if (!props.organization) {
        return;
    }

    form.patch(updateStatus(props.organization.slug).url, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
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
                        {{ form.disabled ? 'Disable' : 'Enable' }}
                        {{ organization?.name }}?
                    </DialogTitle>
                    <DialogDescription v-if="form.disabled">
                        Its members lose access straight away; anyone with no
                        other active organization cannot sign in. Its booking
                        pages stop taking bookings, its reminders, workflows and
                        API keys stop, and existing meetings stay in calendars.
                        You can enable it again at any time.
                    </DialogDescription>
                    <DialogDescription v-else>
                        Its members get their access back and its booking pages,
                        reminders, workflows and API keys work again. Messages
                        that fell due while it was disabled are not sent late.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="form.disabled" class="grid gap-2">
                    <Label for="disable-reason">
                        Reason
                        <span class="font-normal text-muted-foreground"
                            >(optional, super admins only)</span
                        >
                    </Label>
                    <Textarea
                        id="disable-reason"
                        v-model="form.reason"
                        rows="3"
                        placeholder="e.g. Unpaid invoice"
                    />
                    <InputError :message="form.errors.reason" />
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
                        :variant="form.disabled ? 'destructive' : 'default'"
                        :disabled="form.processing"
                        data-test="confirm-status"
                    >
                        {{
                            form.disabled
                                ? 'Disable organization'
                                : 'Enable organization'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/teams';

/*
  A super admin does not join the organizations they create, so they name its
  owner (first admin) here. An organization admin creating one becomes its
  admin themselves and sees only the name.
*/
const isSuperAdmin = usePage().props.isSuperAdmin;

const open = ref(false);
const formKey = ref(0);

function handleOpenChange(value: boolean) {
    open.value = value;

    if (!value) {
        formKey.value++;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="store.form()"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="open = false"
            >
                <DialogHeader>
                    <DialogTitle>Create a new organization</DialogTitle>
                    <DialogDescription>
                        {{
                            isSuperAdmin
                                ? 'Create the organization and choose who runs it.'
                                : 'Create a new organization to collaborate with others.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="name">Organization name</Label>
                    <Input
                        id="name"
                        name="name"
                        data-test="create-team-name"
                        placeholder="My organization"
                        required
                    />
                    <InputError :message="errors.name" />
                </div>

                <fieldset
                    v-if="isSuperAdmin"
                    class="grid gap-4 rounded-lg border border-border p-4"
                >
                    <legend class="px-1 text-sm font-medium">Owner</legend>
                    <p class="-mt-1 text-sm text-muted-foreground">
                        The organization's first admin. A new address gets an
                        account and an email to set their password; an existing
                        account is added as admin.
                    </p>
                    <div class="grid gap-2">
                        <Label for="owner_email">Email</Label>
                        <Input
                            id="owner_email"
                            name="owner_email"
                            type="email"
                            placeholder="owner@company.com"
                            data-test="create-team-owner-email"
                            required
                        />
                        <InputError :message="errors.owner_email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="owner_name">
                            Name
                            <span class="font-normal text-muted-foreground">
                                (new accounts only)
                            </span>
                        </Label>
                        <Input
                            id="owner_name"
                            name="owner_name"
                            autocomplete="off"
                            data-test="create-team-owner-name"
                        />
                        <InputError :message="errors.owner_name" />
                    </div>
                </fieldset>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary"> Cancel </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        data-test="create-team-submit"
                        :disabled="processing"
                    >
                        Create organization
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>

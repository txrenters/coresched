<script setup lang="ts">
import { Head, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Link2, Pencil, Plus, Trash2, Users } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import HostPriorityList from '@/components/scheduling/HostPriorityList.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useCurrentTeam } from '@/composables/useCurrentTeam';
import { destroy, index, store, update } from '@/routes/groups';

type Member = { id: number; name: string; email: string };

type Group = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    availabilityScheduleId: number | null;
    availabilityScheduleName: string | null;
    eventTypeCount: number;
    bookingUrl: string;
    members: Member[];
    memberIds: number[];
    pageEventTypeIds: number[];
};

type EventTypeOption = {
    id: number;
    name: string;
    kindLabel: string;
    ownerName: string | null;
    isActive: boolean;
};

type Props = {
    groups: Group[];
    teamMembers: Member[];
    canManage: boolean;
    /** Hours the organization shares, selectable as this team's own. */
    sharedSchedules: Array<{ value: number; label: string }>;
    /** Every event type in the organization, pickable for a team's page. */
    eventTypes: EventTypeOption[];
};

defineProps<Props>();

const { teamSlug } = useCurrentTeam();

const copyLink = async (url: string) => {
    await navigator.clipboard.writeText(url);
    toast.success('Booking link copied');
};

const editing = ref<Group | null>(null);
const panelOpen = ref(false);
const deleting = ref<Group | null>(null);

const form = useForm({
    name: '',
    description: '',
    availability_schedule_id: null as number | null,
    member_ids: [] as number[],
    page_event_type_ids: [] as number[],
});

const togglePageEventType = (
    id: number,
    checked: boolean | 'indeterminate',
) => {
    form.page_event_type_ids =
        checked === true
            ? [...form.page_event_type_ids, id]
            : form.page_event_type_ids.filter((selected) => selected !== id);
};

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    panelOpen.value = true;
};

const openEdit = (group: Group) => {
    editing.value = group;
    form.clearErrors();
    form.name = group.name;
    form.description = group.description ?? '';
    form.availability_schedule_id = group.availabilityScheduleId;
    form.member_ids = [...group.memberIds];
    form.page_event_type_ids = [...group.pageEventTypeIds];
    panelOpen.value = true;
};

const submit = () => {
    const onSuccess = () => (panelOpen.value = false);

    if (editing.value) {
        form.patch(
            update({ current_team: teamSlug.value, group: editing.value.slug })
                .url,
            { preserveScroll: true, onSuccess },
        );

        return;
    }

    form.post(store(teamSlug.value).url, { preserveScroll: true, onSuccess });
};

const confirmDelete = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(
        destroy({ current_team: teamSlug.value, group: deleting.value.slug })
            .url,
        { preserveScroll: true, onFinish: () => (deleting.value = null) },
    );
};

setLayoutProps({
    breadcrumbs: [{ title: 'Teams', href: index(teamSlug.value) }],
});
</script>

<template>
    <Head title="Teams" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Teams"
            description="Groups of people inside this organization — Leasing, Maintenance — that event types can host from."
        >
            <template #actions>
                <Button
                    v-if="canManage"
                    data-test="new-group"
                    @click="openCreate"
                >
                    <Plus /> New team
                </Button>
            </template>
        </PageHeader>

        <div v-if="groups.length" class="grid gap-3 md:grid-cols-2">
            <div
                v-for="group in groups"
                :key="group.id"
                data-test="group-card"
                class="flex flex-col rounded-lg border border-border bg-card p-5 shadow-flat"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium">{{ group.name }}</p>
                        <p
                            v-if="group.availabilityScheduleName"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            Hours: {{ group.availabilityScheduleName }}
                        </p>
                        <p
                            v-if="group.description"
                            class="mt-0.5 text-sm text-muted-foreground"
                        >
                            {{ group.description }}
                        </p>
                    </div>
                    <Badge variant="secondary">
                        {{ group.eventTypeCount }} event
                        {{ group.eventTypeCount === 1 ? 'type' : 'types' }}
                    </Badge>
                </div>

                <ul class="mt-4 flex flex-wrap gap-1.5">
                    <li
                        v-for="member in group.members"
                        :key="member.id"
                        class="rounded-md bg-muted px-2 py-1 text-xs"
                    >
                        {{ member.name }}
                    </li>
                </ul>

                <div
                    v-if="canManage"
                    class="mt-4 flex items-center gap-2 border-t pt-4"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        :data-test="`edit-group-${group.id}`"
                        @click="openEdit(group)"
                    >
                        <Pencil class="size-3.5" /> Edit
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :data-test="`copy-group-link-${group.id}`"
                        @click="copyLink(group.bookingUrl)"
                    >
                        <Link2 class="size-3.5" /> Copy link
                    </Button>
                    <div class="flex-1" />
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Delete ${group.name}`"
                        @click="deleting = group"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </div>
        </div>

        <div
            v-else
            class="rounded-lg border border-dashed p-12 text-center text-muted-foreground"
        >
            <Users class="mx-auto mb-3 size-8 opacity-50" />
            <p>No teams yet.</p>
            <p class="mt-1 text-sm">
                Build a team once — Leasing, Maintenance — then point an event
                type at it and everyone in it hosts, in priority order.
            </p>
            <Button v-if="canManage" class="mt-4" @click="openCreate">
                Create a team
            </Button>
        </div>
    </div>

    <Sheet v-model:open="panelOpen">
        <SheetContent
            side="right"
            class="w-full gap-0 overflow-y-auto sm:max-w-md"
        >
            <SheetHeader>
                <SheetTitle>
                    {{ editing ? editing.name : 'New team' }}
                </SheetTitle>
                <SheetDescription>
                    Members are offered in the order they are listed here.
                </SheetDescription>
            </SheetHeader>

            <form
                class="flex flex-col gap-6 px-4 pb-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-2">
                    <Label for="group-name">Name</Label>
                    <Input
                        id="group-name"
                        v-model="form.name"
                        placeholder="Leasing"
                        data-test="group-name"
                        required
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="group-description">Description</Label>
                    <Input
                        id="group-description"
                        v-model="form.description"
                        placeholder="Who is in this team?"
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="group-schedule">Hours</Label>
                    <select
                        id="group-schedule"
                        v-model="form.availability_schedule_id"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        data-test="group-schedule"
                    >
                        <option :value="null">Each member's own hours</option>
                        <option
                            v-for="schedule in sharedSchedules"
                            :key="schedule.value"
                            :value="schedule.value"
                        >
                            {{ schedule.label }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Shared hours become this team's bookable window whoever
                        the booking lands on. Create them on the Availability
                        page.
                    </p>
                    <InputError
                        :message="form.errors.availability_schedule_id"
                    />
                </div>

                <div class="grid gap-2">
                    <Label>Members</Label>
                    <p class="text-xs text-muted-foreground">
                        Every member here hosts the event types pointed at this
                        team. Bookings go to the highest one who is free.
                    </p>
                    <HostPriorityList
                        :members="teamMembers"
                        :model-value="form.member_ids"
                        @update:model-value="(ids) => (form.member_ids = ids)"
                    />
                    <InputError :message="form.errors.member_ids" />
                </div>

                <div class="grid gap-2">
                    <Label>Show on team page</Label>
                    <p class="text-xs text-muted-foreground">
                        Event types hosting from this team are always listed.
                        Pick any others to show on its booking page too — this
                        does not change who hosts them.
                    </p>
                    <div
                        v-if="eventTypes.length"
                        class="grid max-h-64 gap-1 overflow-y-auto rounded-md border border-border p-2"
                    >
                        <label
                            v-for="eventType in eventTypes"
                            :key="eventType.id"
                            class="flex items-center gap-2 rounded px-1.5 py-1 text-sm hover:bg-muted"
                            :data-test="`page-event-type-${eventType.id}`"
                        >
                            <Checkbox
                                :model-value="
                                    form.page_event_type_ids.includes(
                                        eventType.id,
                                    )
                                "
                                @update:model-value="
                                    (checked) =>
                                        togglePageEventType(
                                            eventType.id,
                                            checked,
                                        )
                                "
                            />
                            <span class="min-w-0 flex-1 truncate">
                                {{ eventType.name }}
                            </span>
                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                            >
                                {{ eventType.kindLabel }}
                                <template v-if="eventType.ownerName">
                                    · {{ eventType.ownerName }}
                                </template>
                                <template v-if="!eventType.isActive">
                                    · Off
                                </template>
                            </span>
                        </label>
                    </div>
                    <p v-else class="text-xs text-muted-foreground">
                        No event types in this organization yet.
                    </p>
                    <InputError :message="form.errors.page_event_type_ids" />
                </div>

                <SheetFooter class="flex-row justify-end gap-2 px-0">
                    <Button
                        type="button"
                        variant="outline"
                        @click="panelOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="save-group"
                    >
                        {{ editing ? 'Save group' : 'Create group' }}
                    </Button>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>

    <Dialog :open="deleting !== null" @update:open="deleting = null">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete {{ deleting?.name }}?</DialogTitle>
                <DialogDescription>
                    Event types hosting from this team fall back to their own
                    hosts. Bookings already made are untouched.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="deleting = null">
                    Keep it
                </Button>
                <Button
                    variant="destructive"
                    data-test="confirm-delete-group"
                    @click="confirmDelete"
                >
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

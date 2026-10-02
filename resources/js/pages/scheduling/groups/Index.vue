<script setup lang="ts">
import { Head, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import {
    ExternalLink,
    Link2,
    Pencil,
    Plus,
    QrCode,
    Trash2,
    Users,
} from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import BookingQrCodeDialog from '@/components/scheduling/BookingQrCodeDialog.vue';
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
import {
    destroy as destroyPage,
    store as storePage,
    update as updatePage,
} from '@/routes/department-pages';
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

type DepartmentPage = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    groupIds: number[];
    groupNames: string[];
    url: string;
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
    /** The organization's own public booking page, when it has a slug. */
    organizationUrl: string | null;
    /** Public pages each listing a chosen set of these teams. */
    departmentPages: DepartmentPage[];
    /** Hours the organization shares, selectable as this team's own. */
    sharedSchedules: Array<{ value: number; label: string }>;
    /** Every event type in the organization, pickable for a team's page. */
    eventTypes: EventTypeOption[];
};

defineProps<Props>();

const { teamSlug } = useCurrentTeam();

const copyLink = async (url: string, message = 'Booking link copied') => {
    await navigator.clipboard.writeText(url);
    toast.success(message);
};

/** The booking link whose QR code is open, if any. */
const qrCode = ref<{ url: string; name: string } | null>(null);

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

const editingPage = ref<DepartmentPage | null>(null);
const pagePanelOpen = ref(false);
const deletingPage = ref<DepartmentPage | null>(null);

const pageForm = useForm({
    name: '',
    description: '',
    group_ids: [] as number[],
});

const togglePageGroup = (id: number, checked: boolean | 'indeterminate') => {
    pageForm.group_ids =
        checked === true
            ? [...pageForm.group_ids, id]
            : pageForm.group_ids.filter((selected) => selected !== id);
};

const openCreatePage = () => {
    editingPage.value = null;
    pageForm.reset();
    pageForm.clearErrors();
    pagePanelOpen.value = true;
};

const openEditPage = (page: DepartmentPage) => {
    editingPage.value = page;
    pageForm.clearErrors();
    pageForm.name = page.name;
    pageForm.description = page.description ?? '';
    pageForm.group_ids = [...page.groupIds];
    pagePanelOpen.value = true;
};

const submitPage = () => {
    const onSuccess = () => (pagePanelOpen.value = false);

    if (editingPage.value) {
        pageForm.patch(
            updatePage({
                current_team: teamSlug.value,
                department_page: editingPage.value.slug,
            }).url,
            { preserveScroll: true, onSuccess },
        );

        return;
    }

    pageForm.post(storePage(teamSlug.value).url, {
        preserveScroll: true,
        onSuccess,
    });
};

const confirmDeletePage = () => {
    if (!deletingPage.value) {
        return;
    }

    router.delete(
        destroyPage({
            current_team: teamSlug.value,
            department_page: deletingPage.value.slug,
        }).url,
        {
            preserveScroll: true,
            onFinish: () => (deletingPage.value = null),
        },
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

        <section
            v-if="organizationUrl"
            class="flex flex-col gap-3 rounded-lg border border-border bg-card p-5 shadow-flat sm:flex-row sm:items-center"
            data-test="organization-page"
        >
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold">Organization page</h2>
                <p class="text-sm text-muted-foreground" data-wrap-anywhere>
                    {{ organizationUrl.replace(/^https?:\/\//, '') }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    data-test="copy-organization-link"
                    @click="copyLink(organizationUrl)"
                >
                    <Link2 class="size-3.5" /> Copy link
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    data-test="organization-qr-code"
                    @click="
                        qrCode = {
                            url: organizationUrl,
                            name:
                                $page.props.currentTeam?.name ?? 'Organization',
                        }
                    "
                >
                    <QrCode class="size-3.5" /> QR code
                </Button>
                <Button variant="ghost" size="icon" as-child>
                    <a
                        :href="organizationUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="Open the organization page"
                        title="Open the organization page"
                    >
                        <ExternalLink class="size-4" />
                    </a>
                </Button>
            </div>
        </section>

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
                    <Button
                        variant="outline"
                        size="sm"
                        :data-test="`group-qr-code-${group.id}`"
                        @click="
                            qrCode = { url: group.bookingUrl, name: group.name }
                        "
                    >
                        <QrCode class="size-3.5" /> QR code
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        as-child
                        :data-test="`open-group-page-${group.id}`"
                    >
                        <a
                            :href="group.bookingUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            :aria-label="`Open ${group.name}'s page`"
                            :title="`Open ${group.name}'s page`"
                        >
                            <ExternalLink class="size-4" />
                        </a>
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

        <section class="flex flex-col gap-4" data-test="department-pages">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Pages</h2>
                    <p class="text-sm text-muted-foreground">
                        Public pages that list a chosen set of teams — one for
                        residents, one for owners — each with its own link.
                    </p>
                </div>
                <Button
                    v-if="canManage && groups.length"
                    variant="outline"
                    data-test="new-department-page"
                    @click="openCreatePage"
                >
                    <Plus /> New page
                </Button>
            </div>

            <div
                v-if="departmentPages.length"
                class="grid gap-3 md:grid-cols-2"
            >
                <div
                    v-for="page in departmentPages"
                    :key="page.id"
                    data-test="department-page-card"
                    class="flex flex-col rounded-lg border border-border bg-card p-5 shadow-flat"
                >
                    <p class="font-medium">{{ page.name }}</p>
                    <p
                        v-if="page.description"
                        class="mt-0.5 text-sm text-muted-foreground"
                    >
                        {{ page.description }}
                    </p>

                    <ul class="mt-4 flex flex-wrap gap-1.5">
                        <li
                            v-for="name in page.groupNames"
                            :key="name"
                            class="rounded-md bg-muted px-2 py-1 text-xs"
                        >
                            {{ name }}
                        </li>
                    </ul>

                    <div class="mt-4 flex items-center gap-2 border-t pt-4">
                        <Button
                            v-if="canManage"
                            variant="outline"
                            size="sm"
                            :data-test="`edit-department-page-${page.id}`"
                            @click="openEditPage(page)"
                        >
                            <Pencil class="size-3.5" /> Edit
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :data-test="`copy-department-page-link-${page.id}`"
                            @click="copyLink(page.url, 'Page link copied')"
                        >
                            <Link2 class="size-3.5" /> Copy link
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :data-test="`department-page-qr-code-${page.id}`"
                            @click="qrCode = { url: page.url, name: page.name }"
                        >
                            <QrCode class="size-3.5" /> QR code
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            as-child
                            :data-test="`open-department-page-${page.id}`"
                        >
                            <a
                                :href="page.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                :aria-label="`Open ${page.name}`"
                                :title="`Open ${page.name}`"
                            >
                                <ExternalLink class="size-4" />
                            </a>
                        </Button>
                        <div class="flex-1" />
                        <Button
                            v-if="canManage"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Delete ${page.name}`"
                            @click="deletingPage = page"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </div>

            <div
                v-else
                class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No pages yet.
                <template v-if="groups.length">
                    Create one to share a single link for a set of teams.
                </template>
                <template v-else>Create a team first.</template>
            </div>
        </section>
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

    <Sheet v-model:open="pagePanelOpen">
        <SheetContent
            side="right"
            class="w-full gap-0 overflow-y-auto sm:max-w-md"
        >
            <SheetHeader>
                <SheetTitle>
                    {{ editingPage ? editingPage.name : 'New page' }}
                </SheetTitle>
                <SheetDescription>
                    Visitors pick a team here, then book on that team's page.
                </SheetDescription>
            </SheetHeader>

            <form
                class="flex flex-col gap-6 px-4 pb-4"
                @submit.prevent="submitPage"
            >
                <div class="grid gap-2">
                    <Label for="department-page-name">Name</Label>
                    <Input
                        id="department-page-name"
                        v-model="pageForm.name"
                        placeholder="Residents"
                        data-test="department-page-name"
                        required
                    />
                    <InputError :message="pageForm.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="department-page-description">Description</Label>
                    <Input
                        id="department-page-description"
                        v-model="pageForm.description"
                        placeholder="Who is this page for?"
                    />
                    <InputError :message="pageForm.errors.description" />
                </div>

                <div class="grid gap-2">
                    <Label>Teams</Label>
                    <div
                        class="grid max-h-64 gap-1 overflow-y-auto rounded-md border border-border p-2"
                    >
                        <label
                            v-for="group in groups"
                            :key="group.id"
                            class="flex items-center gap-2 rounded px-1.5 py-1 text-sm hover:bg-muted"
                            :data-test="`department-page-group-${group.id}`"
                        >
                            <Checkbox
                                :model-value="
                                    pageForm.group_ids.includes(group.id)
                                "
                                @update:model-value="
                                    (checked) =>
                                        togglePageGroup(group.id, checked)
                                "
                            />
                            <span class="min-w-0 flex-1 truncate">
                                {{ group.name }}
                            </span>
                        </label>
                    </div>
                    <InputError :message="pageForm.errors.group_ids" />
                </div>

                <SheetFooter class="flex-row justify-end gap-2 px-0">
                    <Button
                        type="button"
                        variant="outline"
                        @click="pagePanelOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="pageForm.processing"
                        data-test="save-department-page"
                    >
                        {{ editingPage ? 'Save page' : 'Create page' }}
                    </Button>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>

    <Dialog :open="deletingPage !== null" @update:open="deletingPage = null">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete {{ deletingPage?.name }}?</DialogTitle>
                <DialogDescription>
                    Its link stops working. The teams and their own pages are
                    untouched.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="deletingPage = null">
                    Keep it
                </Button>
                <Button
                    variant="destructive"
                    data-test="confirm-delete-department-page"
                    @click="confirmDeletePage"
                >
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

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

    <BookingQrCodeDialog
        :url="qrCode?.url ?? null"
        :name="qrCode?.name ?? ''"
        @close="qrCode = null"
    />
</template>

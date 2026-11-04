<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Building2,
    Ban,
    ChevronDown,
    CircleCheck,
    Pencil,
    Plus,
    Search,
    Settings2,
    Trash2,
    UserPlus,
} from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import OpenAsButton from '@/components/admin/OpenAsButton.vue';
import OrganizationPlanDialog from '@/components/admin/OrganizationPlanDialog.vue';
import OrganizationStatusDialog from '@/components/admin/OrganizationStatusDialog.vue';
import SetupChecklist from '@/components/admin/SetupChecklist.vue';
import CreateTeamModal from '@/components/CreateTeamModal.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as organizationsIndex } from '@/routes/organizations';
import {
    destroy as destroyMember,
    store as storeMember,
    update as updateMemberRole,
} from '@/routes/organizations/members';
import type { ConsoleOptions, OrganizationAdminSummary } from '@/types/admin';

type Member = {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
};

type Organization = {
    id: number;
    name: string;
    slug: string;
    logoUrl: string | null;
    eventTypeCount: number;
    bookingCount: number;
    groupCount: number;
    createdAt: string | null;
    settingsUrl: string;
    members: Member[];
    /** Plan, sender, setup and "Open as"; present for super admins only. */
    admin: OrganizationAdminSummary | null;
};

const props = defineProps<{
    organizations: Organization[];
    search: string;
    roles: Array<{ value: string; label: string }>;
    /** Plans and senders for the super admin's controls; null for anyone else. */
    consoleOptions: ConsoleOptions | null;
}>();

const page = usePage();

const description = computed(() =>
    page.props.isSuperAdmin
        ? 'Every organization in the installation, who runs it, and what it holds.'
        : 'The organizations you administer, who is in them, and what they hold.',
);

const searchTerm = ref(props.search);
const planFilter = ref(props.consoleOptions?.planFilter ?? '');

const reload = () =>
    router.get(
        organizationsIndex().url,
        {
            search: searchTerm.value,
            ...(props.consoleOptions ? { plan: planFilter.value } : {}),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );

let debounce: ReturnType<typeof setTimeout> | null = null;

// A pending search would otherwise pull the reader back from the next page.
onUnmounted(() => {
    if (debounce) {
        clearTimeout(debounce);
    }
});

watch(searchTerm, () => {
    if (debounce) {
        clearTimeout(debounce);
    }

    debounce = setTimeout(reload, 300);
});

watch(planFilter, reload);

/** The organization whose plan and sender are being edited. */
const editingPlan = ref<Organization | null>(null);

const planDialogOrganization = computed(() =>
    editingPlan.value?.admin
        ? {
              name: editingPlan.value.name,
              slug: editingPlan.value.slug,
              admin: editingPlan.value.admin,
          }
        : null,
);

/** The organization currently having someone added to it. */
const adding = ref<Organization | null>(null);

const form = useForm({ name: '', email: '', role: 'admin' });

const openAdd = (organization: Organization) => {
    adding.value = organization;
    form.reset();
    form.clearErrors();
};

/** The organization being disabled or enabled. */
const switchingStatus = ref<Organization | null>(null);

const statusDialogOrganization = computed(() =>
    switchingStatus.value
        ? {
              name: switchingStatus.value.name,
              slug: switchingStatus.value.slug,
              disabled: Boolean(switchingStatus.value.admin?.disabledAt),
          }
        : null,
);

const submitMember = () => {
    if (!adding.value) {
        return;
    }

    form.post(storeMember(adding.value.slug).url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            adding.value = null;
        },
    });
};

const changeRole = (
    organization: Organization,
    member: Member,
    role: string,
) => {
    router.patch(
        updateMemberRole([organization.slug, member.id]).url,
        { role },
        { preserveScroll: true },
    );
};

const removeMember = (organization: Organization, member: Member) => {
    router.delete(destroyMember([organization.slug, member.id]).url, {
        preserveScroll: true,
    });
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Organizations', href: organizationsIndex() }],
    },
});
</script>

<template>
    <Head title="Organizations" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader title="Organizations" :description="description">
            <template #actions>
                <CreateTeamModal>
                    <Button data-test="new-organization">
                        <Plus /> New organization
                    </Button>
                </CreateTeamModal>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-3 sm:flex-row">
            <div class="relative max-w-md flex-1">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="searchTerm"
                    class="pl-9"
                    placeholder="Search organizations"
                    aria-label="Search organizations"
                    data-test="organization-search"
                />
            </div>
            <select
                v-if="consoleOptions"
                v-model="planFilter"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                aria-label="Filter by plan"
                data-test="organization-plan-filter"
            >
                <option value="">All plans</option>
                <option value="none">No plan</option>
                <option
                    v-for="plan in consoleOptions.plans"
                    :key="plan.value"
                    :value="plan.value"
                >
                    {{ plan.label }}
                </option>
            </select>
        </div>

        <div v-if="organizations.length" class="flex flex-col gap-4">
            <section
                v-for="organization in organizations"
                :key="organization.id"
                class="rounded-xl border border-border bg-card shadow-flat"
                data-test="organization-card"
            >
                <header
                    class="flex flex-wrap items-start justify-between gap-3 border-b border-border p-5"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted"
                            aria-hidden="true"
                        >
                            <img
                                v-if="organization.logoUrl"
                                :src="organization.logoUrl"
                                alt=""
                                class="size-full object-cover"
                            />
                            <Building2
                                v-else
                                class="size-4 text-muted-foreground"
                            />
                        </span>
                        <div>
                            <p
                                class="flex flex-wrap items-center gap-2 font-medium"
                            >
                                {{ organization.name }}
                                <span
                                    v-if="organization.admin"
                                    :class="[
                                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                                        organization.admin.plan
                                            ? 'bg-accent text-accent-foreground'
                                            : 'bg-muted text-muted-foreground',
                                    ]"
                                    data-test="organization-plan"
                                >
                                    {{
                                        organization.admin.planLabel ??
                                        'No plan'
                                    }}
                                </span>
                                <span
                                    v-if="organization.admin?.disabledAt"
                                    class="rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-semibold text-destructive"
                                    data-test="organization-disabled"
                                >
                                    Disabled
                                </span>
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ organization.members.length }} members ·
                                {{ organization.eventTypeCount }} event types ·
                                {{ organization.bookingCount }} bookings ·
                                {{ organization.groupCount }} teams
                                <template
                                    v-if="organization.admin?.lastBookedAt"
                                >
                                    · last booked
                                    {{ organization.admin.lastBookedAt }}
                                </template>
                                <template
                                    v-if="
                                        organization.admin &&
                                        consoleOptions &&
                                        organization.admin.bookingMailFrom !==
                                            consoleOptions.senders.default
                                    "
                                >
                                    · emails from
                                    {{ organization.admin.bookingMailFrom }}
                                </template>
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <OpenAsButton
                            v-if="
                                organization.admin &&
                                !organization.admin.disabledAt
                            "
                            :organization-id="organization.id"
                            :slug="organization.slug"
                            :members="organization.admin.openAs"
                        />
                        <Button
                            v-if="organization.admin"
                            variant="outline"
                            size="sm"
                            :data-test="`edit-plan-${organization.id}`"
                            @click="editingPlan = organization"
                        >
                            <Pencil class="size-3.5" /> Plan &amp; email
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :data-test="`add-member-${organization.id}`"
                            @click="openAdd(organization)"
                        >
                            <UserPlus class="size-3.5" /> Add member
                        </Button>
                        <Button variant="ghost" size="sm" as-child>
                            <a :href="organization.settingsUrl">
                                <Settings2 class="size-3.5" /> Settings
                            </a>
                        </Button>
                        <Button
                            v-if="organization.admin"
                            variant="ghost"
                            size="sm"
                            :class="
                                organization.admin.disabledAt
                                    ? ''
                                    : 'text-destructive hover:text-destructive'
                            "
                            :data-test="`toggle-status-${organization.id}`"
                            @click="switchingStatus = organization"
                        >
                            <template v-if="organization.admin.disabledAt">
                                <CircleCheck class="size-3.5" /> Enable
                            </template>
                            <template v-else>
                                <Ban class="size-3.5" /> Disable
                            </template>
                        </Button>
                    </div>
                </header>

                <p
                    v-if="organization.admin?.disabledAt"
                    class="border-b border-border bg-destructive/5 px-5 py-3 text-sm text-destructive"
                >
                    Disabled since {{ organization.admin.disabledAt }}. Its
                    members cannot use it and its booking pages are paused.
                    <template v-if="organization.admin.disabledReason">
                        Reason: {{ organization.admin.disabledReason }}
                    </template>
                </p>

                <div
                    v-if="organization.admin"
                    class="border-b border-border px-5 py-3"
                >
                    <SetupChecklist
                        :organization-id="organization.id"
                        :setup="organization.admin.setup"
                    />
                    <p
                        v-if="organization.admin.planNote"
                        class="pt-2 text-sm text-muted-foreground"
                    >
                        Note: {{ organization.admin.planNote }}
                    </p>
                </div>

                <ul class="divide-y divide-border">
                    <li
                        v-for="member in organization.members"
                        :key="member.id"
                        class="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                    >
                        <span class="min-w-0">
                            <span class="font-medium">{{ member.name }}</span>
                            <span class="block text-sm text-muted-foreground">
                                {{ member.email }}
                            </span>
                        </span>

                        <span class="flex items-center gap-2">
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        :data-test="`role-${organization.id}-${member.id}`"
                                    >
                                        {{ member.roleLabel }}
                                        <ChevronDown
                                            class="size-3 opacity-50"
                                        />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuLabel
                                        class="text-muted-foreground"
                                    >
                                        Role
                                    </DropdownMenuLabel>
                                    <DropdownMenuItem
                                        v-for="role in roles"
                                        :key="role.value"
                                        @click="
                                            changeRole(
                                                organization,
                                                member,
                                                role.value,
                                            )
                                        "
                                    >
                                        {{ role.label }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>

                            <Button
                                variant="ghost"
                                size="sm"
                                :data-test="`remove-${organization.id}-${member.id}`"
                                @click="removeMember(organization, member)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </span>
                    </li>
                    <li
                        v-if="!organization.members.length"
                        class="px-5 py-4 text-sm text-muted-foreground"
                    >
                        Nobody belongs to this organization yet.
                    </li>
                </ul>
            </section>
        </div>

        <div
            v-else
            class="rounded-xl border border-dashed border-border px-6 py-12 text-center text-muted-foreground"
        >
            <Building2 class="mx-auto mb-3 size-6" aria-hidden="true" />
            <p>No organizations match that search.</p>
        </div>

        <Dialog
            :open="adding !== null"
            @update:open="(open) => !open && (adding = null)"
        >
            <DialogContent>
                <form class="grid gap-5" @submit.prevent="submitMember">
                    <DialogHeader>
                        <DialogTitle
                            >Add a member to {{ adding?.name }}</DialogTitle
                        >
                        <DialogDescription>
                            A new address gets an account and an email to set
                            their password.
                            <template v-if="page.props.isSuperAdmin">
                                An existing account is added straight in.
                            </template>
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="member-email">Email</Label>
                        <Input
                            id="member-email"
                            v-model="form.email"
                            type="email"
                            placeholder="person@company.com"
                            data-test="member-email"
                            required
                        />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="member-name">
                            Name
                            <span class="font-normal text-muted-foreground">
                                (new accounts only)
                            </span>
                        </Label>
                        <Input
                            id="member-name"
                            v-model="form.name"
                            data-test="member-name"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="member-role">Role</Label>
                        <select
                            id="member-role"
                            v-model="form.role"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            data-test="member-role"
                        >
                            <option
                                v-for="role in roles"
                                :key="role.value"
                                :value="role.value"
                            >
                                {{ role.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.role" />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="adding = null"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="form.processing"
                            data-test="submit-member"
                        >
                            Add member
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <OrganizationStatusDialog
            v-if="consoleOptions"
            :organization="statusDialogOrganization"
            @close="switchingStatus = null"
        />

        <OrganizationPlanDialog
            v-if="consoleOptions"
            :organization="planDialogOrganization"
            :plans="consoleOptions.plans"
            :senders="consoleOptions.senders"
            @close="editingPlan = null"
        />
    </div>
</template>

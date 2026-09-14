<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CalendarCheck,
    CalendarOff,
    CalendarX,
    ChevronDown,
    ExternalLink,
    KeyRound,
    Plus,
    MoreHorizontal,
    Search,
    ShieldCheck,
    Trash2,
    UserMinus,
    UserRound,
} from '@lucide/vue';
import { onUnmounted, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
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
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    destroy as destroyUser,
    index as usersIndex,
    passwordReset,
    role as updateRole,
    store as storeUser,
} from '@/routes/users';
import { destroy as removeFromOrganization } from '@/routes/users/organizations';

type Organization = {
    id: number;
    name: string;
    role: string;
    roleLabel: string;
};

type RemovableFrom = { id: number; slug: string; name: string };

type DirectoryUser = {
    id: number;
    name: string;
    email: string;
    initials: string;
    isSuperAdmin: boolean;
    isVerified: boolean;
    joinedAt: string | null;
    bookingUrl: string | null;
    groups: string[];
    calendar: { state: string; label: string; detail: string | null };
    canResetPassword: boolean;
    removableFrom: RemovableFrom[];
    organizations: Organization[];
};

const props = defineProps<{
    users: DirectoryUser[];
    search: string;
    page: number;
    lastPage: number;
    total: number;
    roles: Array<{ value: string; label: string }>;
    canManage: boolean;
    organizations: Array<{ id: number; name: string }>;
}>();

/** Creating an account: a super admin, or somebody placed in an organization. */
const creating = ref(false);

const createForm = useForm({
    name: '',
    email: '',
    is_super_admin: false,
    team_id: null as number | null,
    role: 'member',
});

const openCreate = () => {
    createForm.reset();
    createForm.clearErrors();
    createForm.team_id = props.organizations[0]?.id ?? null;
    creating.value = true;
};

const submitCreate = () => {
    createForm.post(storeUser().url, {
        preserveScroll: true,
        onSuccess: () => {
            creating.value = false;
            createForm.reset();
        },
    });
};

const searchTerm = ref(props.search);

const reload = (data: Record<string, string | number>) => {
    router.get(
        usersIndex().url,
        { search: searchTerm.value, ...data },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

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

    debounce = setTimeout(() => reload({ page: 1 }), 300);
});

const sendPasswordReset = (user: DirectoryUser) => {
    router.post(
        passwordReset(user.id).url,
        {},
        { preserveScroll: true, preserveState: true },
    );
};

const changeRole = (
    user: DirectoryUser,
    organization: Organization,
    role: string,
) => {
    router.patch(
        updateRole(user.id).url,
        { team_id: organization.id, role },
        { preserveScroll: true, preserveState: true },
    );
};

const removing = ref<{ user: DirectoryUser; team: RemovableFrom } | null>(null);

const confirmRemoval = () => {
    if (!removing.value) {
        return;
    }

    router.delete(
        removeFromOrganization([
            removing.value.user.id,
            removing.value.team.slug,
        ]).url,
        { preserveScroll: true, onFinish: () => (removing.value = null) },
    );
};

/** Deleting takes the account's bookings and availability with it. */
const deleting = ref<DirectoryUser | null>(null);

const confirmDelete = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(destroyUser(deleting.value.id).url, {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
};

const calendarIcon = (state: string) =>
    state === 'synced'
        ? CalendarCheck
        : state === 'error'
          ? CalendarX
          : CalendarOff;

const calendarClass = (state: string) =>
    state === 'synced'
        ? 'text-success'
        : state === 'error'
          ? 'text-destructive'
          : 'text-muted-foreground';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: usersIndex() }],
    },
});
</script>

<template>
    <Head title="Users" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Users"
            :description="
                canManage
                    ? 'Every account in the installation, what they can reach, and whether their calendar is syncing.'
                    : 'The people in your organizations, and whether their calendar is syncing.'
            "
        >
            <template #actions>
                <div class="flex items-center gap-3">
                    <p class="text-sm text-muted-foreground">
                        {{ total }} {{ total === 1 ? 'account' : 'accounts' }}
                    </p>
                    <Button
                        v-if="organizations.length || canManage"
                        data-test="new-user"
                        @click="openCreate"
                    >
                        <Plus /> New user
                    </Button>
                </div>
            </template>
        </PageHeader>

        <div class="relative max-w-md">
            <Search
                class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                v-model="searchTerm"
                class="pl-9"
                placeholder="Search by name or email"
                aria-label="Search users"
                data-test="user-search"
            />
        </div>

        <div
            v-if="users.length"
            class="overflow-x-auto rounded-xl border border-border bg-card shadow-flat"
        >
            <table class="w-full text-sm">
                <thead class="border-b border-border text-muted-foreground">
                    <tr>
                        <th class="p-4 text-left font-medium">Name</th>
                        <th class="p-4 text-left font-medium">Organizations</th>
                        <th class="p-4 text-left font-medium">Booking page</th>
                        <th class="p-4 text-left font-medium">Teams</th>
                        <th class="p-4 text-left font-medium">Calendar</th>
                        <th v-if="canManage" class="p-4 text-right font-medium">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr
                        v-for="user in users"
                        :key="user.id"
                        data-test="user-row"
                    >
                        <td class="p-4">
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold"
                                    aria-hidden="true"
                                >
                                    {{ user.initials }}
                                </span>
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span class="font-medium">
                                            {{ user.name }}
                                        </span>
                                        <Badge
                                            v-if="user.isSuperAdmin"
                                            variant="secondary"
                                            class="gap-1"
                                        >
                                            <ShieldCheck class="size-3" />
                                            Super admin
                                        </Badge>
                                        <Badge
                                            v-if="!user.isVerified"
                                            variant="outline"
                                        >
                                            Unverified
                                        </Badge>
                                    </div>
                                    <span class="text-muted-foreground">
                                        {{ user.email }}
                                    </span>
                                </div>
                            </div>
                        </td>

                        <td class="p-4">
                            <div class="flex flex-col gap-1">
                                <template v-if="!canManage">
                                    <span
                                        v-for="organization in user.organizations"
                                        :key="organization.id"
                                        class="px-2 py-1"
                                    >
                                        {{ organization.name }}
                                        <span class="text-muted-foreground">
                                            · {{ organization.roleLabel }}
                                        </span>
                                    </span>
                                </template>
                                <DropdownMenu
                                    v-for="organization in user.organizations"
                                    v-else
                                    :key="organization.id"
                                >
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="h-auto justify-start gap-1 px-2 py-1"
                                            :data-test="`role-${user.id}-${organization.id}`"
                                        >
                                            <span class="truncate">
                                                {{ organization.name }}
                                            </span>
                                            <span class="text-muted-foreground">
                                                · {{ organization.roleLabel }}
                                            </span>
                                            <ChevronDown
                                                class="size-3 opacity-50"
                                            />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="start">
                                        <DropdownMenuLabel
                                            class="text-muted-foreground"
                                        >
                                            Role in {{ organization.name }}
                                        </DropdownMenuLabel>
                                        <DropdownMenuItem
                                            v-for="option in roles"
                                            :key="option.value"
                                            :data-test="`set-role-${option.value}`"
                                            @click="
                                                changeRole(
                                                    user,
                                                    organization,
                                                    option.value,
                                                )
                                            "
                                        >
                                            {{ option.label }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                                <span
                                    v-if="!user.organizations.length"
                                    class="text-muted-foreground"
                                >
                                    None
                                </span>
                            </div>
                        </td>

                        <td class="p-4">
                            <a
                                v-if="user.bookingUrl"
                                :href="user.bookingUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-primary hover:underline"
                            >
                                Open
                                <ExternalLink class="size-3" />
                            </a>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>

                        <td class="p-4">
                            <span
                                v-if="user.groups.length"
                                class="flex flex-wrap gap-1"
                            >
                                <Badge
                                    v-for="group in user.groups"
                                    :key="group"
                                    variant="outline"
                                    class="font-normal"
                                >
                                    {{ group }}
                                </Badge>
                            </span>
                            <span v-else class="text-muted-foreground">
                                None
                            </span>
                        </td>

                        <td class="p-4">
                            <span
                                class="flex items-center gap-1.5"
                                :class="calendarClass(user.calendar.state)"
                                :title="user.calendar.detail ?? undefined"
                            >
                                <component
                                    :is="calendarIcon(user.calendar.state)"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ user.calendar.label }}
                            </span>
                        </td>

                        <td class="p-4 text-right">
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        :data-test="`actions-${user.id}`"
                                        aria-label="Actions"
                                    >
                                        <MoreHorizontal class="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        v-if="user.canResetPassword"
                                        :data-test="`reset-password-${user.id}`"
                                        @click="sendPasswordReset(user)"
                                    >
                                        <KeyRound class="size-3.5" />
                                        Send reset link
                                    </DropdownMenuItem>

                                    <template v-if="user.removableFrom.length">
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            v-for="organization in user.removableFrom"
                                            :key="organization.id"
                                            class="text-destructive"
                                            :data-test="`remove-${user.id}-${organization.id}`"
                                            @click="
                                                removing = {
                                                    user,
                                                    team: organization,
                                                }
                                            "
                                        >
                                            <UserMinus class="size-3.5" />
                                            Remove from {{ organization.name }}
                                        </DropdownMenuItem>
                                    </template>

                                    <template v-if="canManage">
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            class="text-destructive"
                                            :data-test="`delete-user-${user.id}`"
                                            @click="deleting = user"
                                        >
                                            <Trash2 class="size-3.5" />
                                            Delete account
                                        </DropdownMenuItem>
                                    </template>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-else
            class="rounded-xl border border-dashed border-border px-6 py-12 text-center text-muted-foreground"
        >
            <UserRound class="mx-auto mb-3 size-6" aria-hidden="true" />
            <p>No accounts match that search.</p>
        </div>

        <div v-if="lastPage > 1" class="flex items-center justify-between">
            <Button
                variant="outline"
                size="sm"
                :disabled="page <= 1"
                @click="reload({ page: page - 1 })"
            >
                Previous
            </Button>
            <span class="text-sm text-muted-foreground">
                Page {{ page }} of {{ lastPage }}
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="page >= lastPage"
                @click="reload({ page: page + 1 })"
            >
                Next
            </Button>
        </div>

        <Dialog v-model:open="creating">
            <DialogContent>
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitCreate"
                >
                    <DialogHeader>
                        <DialogTitle>New user</DialogTitle>
                        <DialogDescription>
                            No password is set here. They get an email with a
                            link to choose their own.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-1.5">
                        <Label for="new-user-name">Name</Label>
                        <Input
                            id="new-user-name"
                            v-model="createForm.name"
                            data-test="new-user-name"
                        />
                        <InputError :message="createForm.errors.name" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="new-user-email">Email</Label>
                        <Input
                            id="new-user-email"
                            v-model="createForm.email"
                            type="email"
                            data-test="new-user-email"
                        />
                        <InputError :message="createForm.errors.email" />
                    </div>

                    <label
                        v-if="canManage"
                        class="flex items-center justify-between gap-3 rounded-lg border border-border p-3"
                    >
                        <span>
                            <span class="block font-medium">Super admin</span>
                            <span class="text-sm text-muted-foreground">
                                Reaches every organization and belongs to none.
                            </span>
                        </span>
                        <Switch
                            :model-value="createForm.is_super_admin"
                            data-test="new-user-super-admin"
                            @update:model-value="
                                (value) =>
                                    (createForm.is_super_admin = Boolean(value))
                            "
                        />
                    </label>

                    <template v-if="!createForm.is_super_admin">
                        <div class="grid gap-1.5">
                            <Label for="new-user-team">Organization</Label>
                            <select
                                id="new-user-team"
                                v-model="createForm.team_id"
                                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                data-test="new-user-team"
                            >
                                <option
                                    v-for="organization in organizations"
                                    :key="organization.id"
                                    :value="organization.id"
                                >
                                    {{ organization.name }}
                                </option>
                            </select>
                            <InputError :message="createForm.errors.team_id" />
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="new-user-role">Role</Label>
                            <select
                                id="new-user-role"
                                v-model="createForm.role"
                                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                data-test="new-user-role"
                            >
                                <option
                                    v-for="option in roles"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                            <InputError :message="createForm.errors.role" />
                        </div>
                    </template>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="creating = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="createForm.processing"
                            data-test="submit-new-user"
                        >
                            Create user
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="removing !== null"
            title="Remove from this organization?"
            :description="`${removing?.user.name} will lose access to ${removing?.team.name}. Their account and any other organizations they belong to are untouched.`"
            confirm-label="Remove"
            @cancel="removing = null"
            @confirm="confirmRemoval"
        />

        <ConfirmDialog
            :open="deleting !== null"
            title="Delete this account?"
            :description="`${deleting?.name} (${deleting?.email}) will be removed, along with their event types, availability and the bookings they host. This cannot be undone.`"
            confirm-label="Delete account"
            @cancel="deleting = null"
            @confirm="confirmDelete"
        />
    </div>
</template>

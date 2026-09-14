<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    ChevronDown,
    Plus,
    Search,
    Settings2,
    Trash2,
    UserPlus,
} from '@lucide/vue';
import { onUnmounted, ref, watch } from 'vue';
import CreateTeamModal from '@/components/CreateTeamModal.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
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
};

const props = defineProps<{
    organizations: Organization[];
    search: string;
    roles: Array<{ value: string; label: string }>;
}>();

const searchTerm = ref(props.search);

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

    debounce = setTimeout(
        () =>
            router.get(
                organizationsIndex().url,
                { search: searchTerm.value },
                { preserveState: true, preserveScroll: true, replace: true },
            ),
        300,
    );
});

/** The organization currently having someone added to it. */
const adding = ref<Organization | null>(null);

const form = useForm({ name: '', email: '', role: 'admin' });

const openAdd = (organization: Organization) => {
    adding.value = adding.value?.id === organization.id ? null : organization;
    form.reset();
    form.clearErrors();
};

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
        <PageHeader
            title="Organizations"
            description="Every organization in the installation, who runs it, and what it holds."
        >
            <template #actions>
                <CreateTeamModal>
                    <Button data-test="new-organization">
                        <Plus /> New organization
                    </Button>
                </CreateTeamModal>
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
                placeholder="Search organizations"
                aria-label="Search organizations"
                data-test="organization-search"
            />
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
                            <p class="flex items-center gap-2 font-medium">
                                {{ organization.name }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ organization.members.length }} members ·
                                {{ organization.eventTypeCount }} event types ·
                                {{ organization.bookingCount }} bookings ·
                                {{ organization.groupCount }} teams
                            </p>
                        </div>
                    </div>

                    <div class="flex gap-2">
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
                    </div>
                </header>

                <form
                    v-if="adding?.id === organization.id"
                    class="flex flex-wrap items-end gap-3 border-b border-border bg-muted/40 p-5"
                    @submit.prevent="submitMember"
                >
                    <div class="min-w-56 flex-1">
                        <Label :for="`email-${organization.id}`">Email</Label>
                        <Input
                            :id="`email-${organization.id}`"
                            v-model="form.email"
                            type="email"
                            placeholder="person@texasrenters.com"
                            data-test="member-email"
                        />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="min-w-48 flex-1">
                        <Label :for="`name-${organization.id}`">
                            Name
                            <span class="text-muted-foreground">
                                (new accounts only)
                            </span>
                        </Label>
                        <Input
                            :id="`name-${organization.id}`"
                            v-model="form.name"
                            data-test="member-name"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div>
                        <Label :for="`role-${organization.id}`">Role</Label>
                        <select
                            :id="`role-${organization.id}`"
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
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="submit-member"
                    >
                        Add
                    </Button>
                </form>

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
    </div>
</template>

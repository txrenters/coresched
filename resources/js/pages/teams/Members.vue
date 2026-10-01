<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ChevronDown, Mail, UserPlus, UserRoundPlus, X } from '@lucide/vue';
import { ref } from 'vue';
import CancelInvitationModal from '@/components/CancelInvitationModal.vue';
import CreateMemberModal from '@/components/CreateMemberModal.vue';
import Heading from '@/components/Heading.vue';
import InviteMemberModal from '@/components/InviteMemberModal.vue';
import RemoveMemberModal from '@/components/RemoveMemberModal.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useInitials } from '@/composables/useInitials';
import { edit, index } from '@/routes/teams';
import {
    index as membersIndex,
    update as updateMember,
} from '@/routes/teams/members';
import type {
    RoleOption,
    Team,
    TeamInvitation,
    TeamMember,
    TeamPermissions,
} from '@/types';

type Props = {
    team: Team;
    members: TeamMember[];
    invitations: TeamInvitation[];
    permissions: TeamPermissions;
    availableRoles: RoleOption[];
    /**
     * Creating an account outright is super admin only, matching
     * TeamPolicy::createMember. Everyone else invites.
     */
    canCreateMember: boolean;
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: { team: Team }) => ({
        breadcrumbs: [
            {
                title: 'Organizations',
                href: index(),
            },
            {
                title: props.team.name,
                href: edit(props.team.slug),
            },
            {
                title: 'Members',
                href: membersIndex(props.team.slug),
            },
        ],
    }),
});

const { getInitials } = useInitials();

const inviteDialogOpen = ref(false);
const createMemberDialogOpen = ref(false);
const removeMemberDialogOpen = ref(false);
const memberToRemove = ref<TeamMember | null>(null);
const cancelInvitationDialogOpen = ref(false);
const invitationToCancel = ref<TeamInvitation | null>(null);

const updateMemberRole = (member: TeamMember, newRole: string) => {
    router.visit(updateMember([props.team.slug, member.id]), {
        data: { role: newRole },
        preserveScroll: true,
    });
};

const confirmRemoveMember = (member: TeamMember) => {
    memberToRemove.value = member;
    removeMemberDialogOpen.value = true;
};

const confirmCancelInvitation = (invitation: TeamInvitation) => {
    invitationToCancel.value = invitation;
    cancelInvitationDialogOpen.value = true;
};
</script>

<template>
    <Head :title="`${team.name} members`" />

    <h1 class="sr-only">{{ team.name }} members</h1>

    <div class="flex flex-col space-y-10">
        <!-- Members Section -->
        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <Heading
                    variant="small"
                    title="Organization members"
                    :description="
                        permissions.canCreateInvitation
                            ? 'Manage who belongs to this organization'
                            : ''
                    "
                />

                <div class="flex items-center gap-2">
                    <Button
                        v-if="canCreateMember"
                        variant="outline"
                        data-test="create-member-button"
                        @click="createMemberDialogOpen = true"
                    >
                        <UserRoundPlus /> Create user
                    </Button>

                    <Button
                        v-if="permissions.canCreateInvitation"
                        data-test="invite-member-button"
                        @click="inviteDialogOpen = true"
                    >
                        <UserPlus /> Invite member
                    </Button>
                </div>
            </div>

            <div class="space-y-3">
                <div
                    v-for="member in members"
                    :key="member.id"
                    data-test="member-row"
                    class="flex items-center justify-between rounded-lg border border-border bg-card p-4"
                >
                    <div class="flex items-center gap-4">
                        <Avatar class="h-10 w-10">
                            <AvatarImage
                                v-if="member.avatar"
                                :src="member.avatar"
                                :alt="member.name"
                            />
                            <AvatarFallback>{{
                                getInitials(member.name)
                            }}</AvatarFallback>
                        </Avatar>
                        <div>
                            <div class="font-medium">
                                {{ member.name }}
                            </div>
                            <div class="text-sm text-muted-foreground">
                                {{ member.email }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <DropdownMenu
                            v-if="
                                !member.isLastAdmin &&
                                permissions.canUpdateMember
                            "
                        >
                            <DropdownMenuTrigger as-child>
                                <Button
                                    data-test="member-role-trigger"
                                    variant="outline"
                                    size="sm"
                                >
                                    {{ member.role_label }}
                                    <ChevronDown
                                        class="ml-2 h-4 w-4 opacity-50"
                                    />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent>
                                <DropdownMenuItem
                                    v-for="role in availableRoles"
                                    :key="role.value"
                                    data-test="member-role-option"
                                    @click="
                                        updateMemberRole(member, role.value)
                                    "
                                >
                                    {{ role.label }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Badge v-else variant="secondary">
                            {{ member.role_label }}
                        </Badge>

                        <TooltipProvider
                            v-if="
                                !member.isLastAdmin &&
                                permissions.canRemoveMember
                            "
                        >
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        data-test="member-remove-button"
                                        variant="ghost"
                                        size="sm"
                                        @click="confirmRemoveMember(member)"
                                    >
                                        <X class="h-4 w-4" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>Remove member</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Invitations Section -->
        <div v-if="invitations.length > 0" class="space-y-6">
            <Heading
                variant="small"
                title="Pending invitations"
                description="Invitations that haven't been accepted yet"
            />

            <div class="space-y-3">
                <div
                    v-for="invitation in invitations"
                    :key="invitation.code"
                    data-test="invitation-row"
                    class="flex items-center justify-between rounded-lg border border-border bg-card p-4"
                >
                    <div class="flex items-center gap-4">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-muted"
                        >
                            <Mail class="h-5 w-5 text-muted-foreground" />
                        </div>
                        <div>
                            <div class="font-medium">
                                {{ invitation.email }}
                            </div>
                            <div class="text-sm text-muted-foreground">
                                {{ invitation.role_label }}
                            </div>
                        </div>
                    </div>

                    <TooltipProvider v-if="permissions.canCancelInvitation">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button
                                    data-test="invitation-cancel-button"
                                    variant="ghost"
                                    size="sm"
                                    @click="confirmCancelInvitation(invitation)"
                                >
                                    <X class="h-4 w-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>Cancel invitation</p>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>
            </div>
        </div>
    </div>

    <CreateMemberModal
        v-if="canCreateMember"
        :team="team"
        :available-roles="availableRoles"
        :open="createMemberDialogOpen"
        @update:open="createMemberDialogOpen = $event"
    />

    <InviteMemberModal
        v-if="permissions.canCreateInvitation"
        :team="team"
        :available-roles="availableRoles"
        :open="inviteDialogOpen"
        @update:open="inviteDialogOpen = $event"
    />

    <RemoveMemberModal
        :team="team"
        :member="memberToRemove"
        :open="removeMemberDialogOpen"
        @update:open="removeMemberDialogOpen = $event"
    />

    <CancelInvitationModal
        :team="team"
        :invitation="invitationToCancel"
        :open="cancelInvitationDialogOpen"
        @update:open="cancelInvitationDialogOpen = $event"
    />
</template>

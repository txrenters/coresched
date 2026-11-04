<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronDown, LogIn } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { store as impersonate } from '@/routes/admin/impersonation';
import type { OpenAsMember } from '@/types/admin';

const props = defineProps<{
    organizationId: number;
    slug: string;
    members: OpenAsMember[];
}>();

/** "Open the organization" means as its first admin, falling back to anyone. */
const primary = computed(
    () =>
        props.members.find((member) => member.role === 'admin') ??
        props.members[0],
);

const signInAs = (memberId: number) =>
    router.post(impersonate({ team: props.slug, user: memberId }).url);
</script>

<template>
    <div v-if="primary" class="flex">
        <Button
            size="sm"
            class="rounded-r-none"
            :title="`Sign in as ${primary.name}`"
            :data-test="`open-organization-${organizationId}`"
            @click="signInAs(primary.id)"
        >
            <LogIn class="size-3.5" /> Open as {{ primary.name }}
        </Button>
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    size="sm"
                    class="rounded-l-none border-l border-primary-foreground/20 px-2"
                    aria-label="Sign in as another member"
                    :data-test="`sign-in-as-${organizationId}`"
                >
                    <ChevronDown class="size-3.5" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-64">
                <DropdownMenuLabel>Sign in as a member</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    v-for="member in members"
                    :key="member.id"
                    class="cursor-pointer"
                    @select="signInAs(member.id)"
                >
                    <span class="flex min-w-0 flex-col">
                        <span class="truncate font-medium">
                            {{ member.name }}
                            <span
                                class="text-xs font-normal text-muted-foreground"
                                >· {{ member.roleLabel }}</span
                            >
                        </span>
                        <span class="truncate text-xs text-muted-foreground">{{
                            member.email
                        }}</span>
                    </span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>

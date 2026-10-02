<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Plus, Users } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import CreateTeamModal from '@/components/CreateTeamModal.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getInitials } from '@/composables/useInitials';
import { switchMethod } from '@/routes/teams';
import type { Team } from '@/types';

const props = withDefaults(
    defineProps<{
        inHeader?: boolean;
    }>(),
    {
        inHeader: false,
    },
);

const page = usePage();
const isMobile = ref(false);
let mediaQuery: MediaQueryList | null = null;
const updateIsMobile = () => {
    if (mediaQuery) {
        isMobile.value = mediaQuery.matches;
    }
};

const currentTeam = computed(() => page.props.currentTeam);
const teams = computed(() => page.props.teams ?? []);

/**
 * A super admin moves between every organization, and a member of more than
 * one moves between their own. Anyone in a single organization sees the
 * switcher as a label rather than a menu.
 */
const canSwitchTeams = computed(() => page.props.canSwitchTeams);

/**
 * "New organization" stays a super admin's tool: an organization admin who
 * also belongs elsewhere now opens this menu, and should not find it there.
 */
const canCreateTeam = computed(
    () => page.props.isSuperAdmin && page.props.canCreateTeam,
);
const menuContentClass = computed(() =>
    props.inHeader
        ? 'w-64'
        : 'w-(--reka-dropdown-menu-trigger-width) min-w-64 rounded-lg',
);

const subtitleFor = (team: Team) => team.roleLabel ?? 'Organization';

const switchTeam = (team: Team) => {
    // Re-selecting the current organization would only trigger a pointless
    // full reload, so treat it as a dismiss.
    if (team.isCurrent || team.id === currentTeam.value?.id) {
        return;
    }

    const previousTeamSlug = currentTeam.value?.slug;

    router.visit(switchMethod(team.slug), {
        onFinish: () => {
            if (!previousTeamSlug || typeof window === 'undefined') {
                router.reload();

                return;
            }

            const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
            const segment = `/${previousTeamSlug}`;

            if (currentUrl.includes(segment)) {
                router.visit(currentUrl.replace(segment, `/${team.slug}`), {
                    replace: true,
                });

                return;
            }

            router.reload();
        },
    });
};

onMounted(() => {
    mediaQuery = window.matchMedia('(max-width: 767px)');
    updateIsMobile();
    mediaQuery.addEventListener('change', updateIsMobile);
});

onUnmounted(() => {
    mediaQuery?.removeEventListener('change', updateIsMobile);
});
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child :disabled="!canSwitchTeams">
            <Button
                data-test="team-switcher-trigger"
                variant="ghost"
                :class="[
                    props.inHeader
                        ? 'h-9 gap-2 px-2'
                        : 'h-12 w-full justify-start gap-2 px-2 group-data-[collapsible=icon]:justify-center has-[>svg]:px-2 data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground',
                    canSwitchTeams || 'cursor-default disabled:opacity-100',
                ]"
            >
                <span
                    :class="[
                        'flex shrink-0 items-center justify-center overflow-hidden rounded-lg font-semibold',
                        currentTeam?.logoUrl
                            ? 'bg-card'
                            : 'bg-sidebar-primary text-sidebar-primary-foreground',
                        props.inHeader
                            ? 'size-6 text-[10px]'
                            : 'size-8 text-xs',
                    ]"
                    aria-hidden="true"
                >
                    <img
                        v-if="currentTeam?.logoUrl"
                        :src="currentTeam.logoUrl"
                        :alt="''"
                        class="size-full object-cover"
                    />
                    <template v-else-if="currentTeam?.name">
                        {{ getInitials(currentTeam.name) }}
                    </template>
                    <Users v-else class="size-4" />
                </span>
                <div
                    :class="
                        props.inHeader
                            ? 'grid flex-1 text-left text-sm leading-tight'
                            : 'grid flex-1 text-left text-sm leading-tight group-data-[collapsible=icon]:hidden'
                    "
                >
                    <span
                        :class="
                            props.inHeader
                                ? 'max-w-[140px] truncate font-medium'
                                : 'truncate font-semibold'
                        "
                    >
                        {{ currentTeam?.name ?? 'Select organization' }}
                    </span>
                    <span
                        v-if="!props.inHeader && currentTeam"
                        class="truncate text-xs font-normal text-muted-foreground"
                    >
                        {{ subtitleFor(currentTeam) }}
                    </span>
                </div>
                <ChevronsUpDown
                    v-if="canSwitchTeams"
                    :class="
                        props.inHeader
                            ? 'size-4 opacity-50'
                            : 'ml-auto size-4 opacity-50 group-data-[collapsible=icon]:hidden'
                    "
                />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent
            :class="menuContentClass"
            :side="props.inHeader ? undefined : isMobile ? 'bottom' : 'right'"
            :align="props.inHeader ? 'end' : 'start'"
            :side-offset="props.inHeader ? undefined : 4"
        >
            <DropdownMenuLabel class="text-xs text-muted-foreground">
                Organizations
            </DropdownMenuLabel>
            <DropdownMenuItem
                v-for="team in teams"
                :key="team.id"
                data-test="team-switcher-item"
                class="cursor-pointer gap-2 p-2"
                :aria-current="currentTeam?.id === team.id ? 'true' : undefined"
                @click="switchTeam(team)"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-background text-xs font-semibold text-foreground"
                    aria-hidden="true"
                >
                    <img
                        v-if="team.logoUrl"
                        :src="team.logoUrl"
                        :alt="''"
                        class="size-full object-cover"
                    />
                    <template v-else>{{ getInitials(team.name) }}</template>
                </span>
                <div class="grid min-w-0 flex-1 leading-tight">
                    <span class="truncate text-sm font-medium">
                        {{ team.name }}
                    </span>
                    <span class="truncate text-xs text-muted-foreground">
                        {{ subtitleFor(team) }}
                    </span>
                </div>
                <Check
                    v-if="currentTeam?.id === team.id"
                    class="ml-auto size-4 shrink-0"
                />
            </DropdownMenuItem>
            <template v-if="canCreateTeam">
                <DropdownMenuSeparator />
                <CreateTeamModal>
                    <DropdownMenuItem
                        data-test="team-switcher-new-team"
                        class="cursor-pointer gap-2 p-2"
                        @select.prevent
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-dashed border-border bg-transparent"
                            aria-hidden="true"
                        >
                            <Plus class="size-4 text-muted-foreground" />
                        </span>
                        <span class="font-medium text-muted-foreground">
                            New organization
                        </span>
                    </DropdownMenuItem>
                </CreateTeamModal>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

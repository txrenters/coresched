<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CalendarClock,
    CalendarDays,
    Clock,
    ExternalLink,
    History,
    LayoutGrid,
    ScrollText,
    Settings,
    Users,
    UsersRound,
    Workflow,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as activityIndex } from '@/routes/activity';
import { index as automationsIndex } from '@/routes/automations';
import { index as availabilityIndex } from '@/routes/availability';
import { page as bookingPage } from '@/routes/book';
import { index as groupsIndex } from '@/routes/groups';
import { index as logsIndex } from '@/routes/log-viewer';
import { index as meetingsIndex } from '@/routes/meetings';
import { index as organizationsIndex } from '@/routes/organizations';
import { edit as profileSettings } from '@/routes/profile';
import { index as schedulingIndex } from '@/routes/scheduling';
import { index as usersIndex } from '@/routes/users';
import type { NavItem } from '@/types';

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

const workspaceNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
    {
        title: 'Scheduling',
        href: schedulingIndex(teamSlug.value),
        icon: CalendarDays,
    },
    {
        title: 'Meetings',
        href: meetingsIndex(teamSlug.value),
        icon: CalendarClock,
    },
    {
        title: 'Availability',
        href: availabilityIndex(teamSlug.value),
        icon: Clock,
    },
]);

const teamNavItems = computed<NavItem[]>(() => [
    {
        title: 'Teams',
        href: groupsIndex(teamSlug.value),
        icon: Users,
    },
]);

const otherNavItems = computed<NavItem[]>(() => [
    {
        title: 'Workflows',
        href: automationsIndex(teamSlug.value),
        icon: Workflow,
    },
    {
        title: 'Activity',
        href: activityIndex(teamSlug.value),
        icon: History,
    },
    {
        title: 'Settings',
        href: profileSettings(),
        icon: Settings,
        activePrefix: '/settings',
    },
    // Admins get a read-only view of the people in their organizations; super
    // admins get the whole installation and the actions that go with it.
    ...(page.props.canViewUsers
        ? [{ title: 'Users', href: usersIndex(), icon: UsersRound }]
        : []),
]);

/**
 * The application log, for super admins only. It sits in the footer directly
 * above the booking page link rather than in Settings, because it is an
 * operator tool rather than a personal preference.
 */
const adminNavItems = computed<NavItem[]>(() =>
    page.props.isSuperAdmin
        ? [
              {
                  title: 'Logs',
                  href: logsIndex(),
                  icon: ScrollText,
                  // opcodesio/log-viewer serves its own Blade page, so this
                  // leaves Inertia rather than visiting through it, and opens
                  // in a tab of its own so the app stays where it was.
                  external: true,
              },
              {
                  title: 'Organizations',
                  href: organizationsIndex(),
                  icon: Building2,
              },
          ]
        : [],
);

/**
 * The only external link worth a permanent slot is the user's own public
 * booking page, so they can check what invitees actually see.
 */
const footerNavItems = computed<NavItem[]>(() => {
    const slug = page.props.auth.user?.booking_slug;

    return slug
        ? [
              {
                  title: 'View booking page',
                  href: bookingPage(String(slug)),
                  icon: ExternalLink,
              },
          ]
        : [];
});
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarMenu>
                <SidebarMenuItem>
                    <TeamSwitcher />
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="workspaceNavItems" label="Workspace" />
            <NavMain :items="teamNavItems" label="Team" />
            <NavMain :items="otherNavItems" label="Other" />
        </SidebarContent>

        <SidebarFooter>
            <NavMain
                v-if="adminNavItems.length"
                :items="adminNavItems"
                label="Admin"
            />
            <NavFooter v-if="footerNavItems.length" :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CalendarClock,
    CalendarDays,
    Clock,
    ExternalLink,
    History,
    Inbox,
    Megaphone,
    LayoutGrid,
    ScrollText,
    ShieldCheck,
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
import { index as announcementsIndex } from '@/routes/admin/announcements';
import { index as inquiriesIndex } from '@/routes/admin/inquiries';
import { index as adminConsoleIndex } from '@/routes/admin/organizations';
import { index as automationsIndex } from '@/routes/automations';
import { index as availabilityIndex } from '@/routes/availability';
import { page as bookingPage } from '@/routes/book';
import { index as groupsIndex } from '@/routes/groups';
import { index as logsIndex } from '@/routes/log-viewer';
import { index as meetingsIndex } from '@/routes/meetings';
import { index as organizationsIndex } from '@/routes/organizations';
import { index as schedulingIndex } from '@/routes/scheduling';
import { edit as editTeam } from '@/routes/teams';
import { index as usersIndex } from '@/routes/users';
import type { NavItem } from '@/types';

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

/*
  Every workspace page lives under an organization. A super admin belongs to
  none and may not have picked one yet, so until they do, Dashboard opens the
  admin console and the organization-only links stay hidden rather than
  pointing at a URL with no organization in it.
*/
const hasTeam = computed(() => Boolean(page.props.currentTeam));

const dashboardUrl = computed(() => {
    if (page.props.currentTeam) {
        return dashboard(page.props.currentTeam.slug).url;
    }

    return page.props.isSuperAdmin ? adminConsoleIndex().url : '/';
});

const workspaceNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
    ...(hasTeam.value ? teamWorkspaceItems.value : []),
]);

const teamWorkspaceItems = computed<NavItem[]>(() => [
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

const teamNavItems = computed<NavItem[]>(() =>
    hasTeam.value && page.props.canManageGroups
        ? [
              {
                  title: 'Teams',
                  href: groupsIndex(teamSlug.value),
                  icon: Users,
              },
          ]
        : [],
);

const otherNavItems = computed<NavItem[]>(() => [
    ...(hasTeam.value && page.props.canManageWorkflows
        ? [
              {
                  title: 'Workflows',
                  href: automationsIndex(teamSlug.value),
                  icon: Workflow,
              },
          ]
        : []),
    ...(hasTeam.value && page.props.canViewActivity
        ? [
              {
                  title: 'Activity',
                  href: activityIndex(teamSlug.value),
                  icon: History,
              },
          ]
        : []),
    // The organization's settings; personal ones live in the user menu.
    ...(hasTeam.value &&
    (page.props.canManageOrganization || page.props.canManageApiKeys)
        ? [
              {
                  title: 'Settings',
                  href: editTeam(teamSlug.value),
                  icon: Settings,
                  activePrefix: ['/settings/teams', '/settings/api-keys'],
              },
          ]
        : []),
    // Admins get a read-only view of the people in their organizations; super
    // admins get the whole installation and the actions that go with it.
    ...(page.props.canViewUsers
        ? [{ title: 'Users', href: usersIndex(), icon: UsersRound }]
        : []),
]);

/**
 * Operator tools. They sit in the footer directly above the booking page link
 * rather than in Settings, because they are not personal preferences. The log
 * is for super admins only; organization admins also get Organizations, scoped
 * to the ones they run.
 */
const adminNavItems = computed<NavItem[]>(() => [
    ...(page.props.isSuperAdmin
        ? [
              {
                  title: 'Admin console',
                  href: adminConsoleIndex(),
                  icon: ShieldCheck,
              },
              {
                  title: 'Logs',
                  href: logsIndex(),
                  icon: ScrollText,
                  // opcodesio/log-viewer serves its own Blade page, so this
                  // leaves Inertia rather than visiting through it, and opens
                  // in a tab of its own so the app stays where it was.
                  external: true,
              },
          ]
        : []),
    ...(page.props.canViewOrganizations
        ? [
              {
                  title: 'Organizations',
                  href: organizationsIndex(),
                  icon: Building2,
              },
          ]
        : []),
]);

/*
  A super admin runs the platform rather than working inside an organization:
  their sidebar is the platform's own, with no workspace and no organization
  switcher. They see an organization's workspace only by signing in as one of
  its members from the admin console, and while they do, the signed-in user
  is that member, so this is false and the normal sidebar shows.
*/
const platformMode = computed(() => Boolean(page.props.isSuperAdmin));

const platformNavItems = computed<NavItem[]>(() => [
    { title: 'Dashboard', href: adminConsoleIndex(), icon: LayoutGrid },
    { title: 'Organizations', href: organizationsIndex(), icon: Building2 },
    { title: 'Users', href: usersIndex(), icon: UsersRound },
    { title: 'Sales inquiries', href: inquiriesIndex(), icon: Inbox },
    { title: 'Announcements', href: announcementsIndex(), icon: Megaphone },
    {
        title: 'Logs',
        href: logsIndex(),
        icon: ScrollText,
        // opcodesio/log-viewer serves its own Blade page, so this leaves
        // Inertia and opens in a tab of its own.
        external: true,
    },
]);

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
                        <Link
                            :href="
                                platformMode
                                    ? adminConsoleIndex()
                                    : dashboardUrl
                            "
                        >
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarMenu v-if="!platformMode">
                <SidebarMenuItem>
                    <TeamSwitcher />
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent v-if="platformMode">
            <NavMain :items="platformNavItems" label="Platform" />
        </SidebarContent>

        <SidebarContent v-else>
            <NavMain :items="workspaceNavItems" label="Workspace" />
            <NavMain
                v-if="teamNavItems.length"
                :items="teamNavItems"
                label="Team"
            />
            <NavMain
                v-if="otherNavItems.length"
                :items="otherNavItems"
                label="Other"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavMain
                v-if="!platformMode && adminNavItems.length"
                :items="adminNavItems"
                label="Admin"
            />
            <NavFooter
                v-if="!platformMode && footerNavItems.length"
                :items="footerNavItems"
            />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

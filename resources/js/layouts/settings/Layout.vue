<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { index as apiKeys } from '@/routes/api-keys';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { edit as editTeam } from '@/routes/teams';
import { index as teamMembers } from '@/routes/teams/members';
import type { NavItem } from '@/types';

const page = usePage();

/**
 * Organization pages (its profile, its API keys) share this layout with the
 * personal account pages, but get the organization's tabs instead.
 */
const isOrganizationPage = computed(
    () =>
        page.component.startsWith('teams/') ||
        page.component === 'settings/ApiKeys',
);

/** The organization on screen: the one being edited, else the current one. */
const organization = computed(
    () =>
        (page.props.team as { name: string; slug: string } | undefined) ??
        page.props.currentTeam,
);

const organizationTabs = computed<NavItem[]>(() => {
    if (!organization.value) {
        return [];
    }

    return [
        { title: 'Organization', href: editTeam(organization.value.slug) },
        { title: 'Members', href: teamMembers(organization.value.slug) },
        // API keys belong to the current organization, so the tab is only
        // offered while looking at that one.
        ...(page.props.canManageApiKeys &&
        organization.value.slug === page.props.currentTeam?.slug
            ? [{ title: 'API keys', href: apiKeys() }]
            : []),
    ];
});

const accountNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
    },
    {
        title: 'Security',
        href: editSecurity(),
    },
    {
        title: 'Appearance',
        href: editAppearance(),
    },
];

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

// Settings pages are forms, so the content column is deliberately narrow.
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <template v-if="isOrganizationPage">
            <PageHeader
                title="Settings"
                :description="
                    organization
                        ? `Manage ${organization.name}, its members and its API keys.`
                        : 'Manage your organization.'
                "
            />

            <nav
                class="flex gap-1 border-b border-border"
                aria-label="Organization settings"
            >
                <Link
                    v-for="item in organizationTabs"
                    :key="toUrl(item.href)"
                    :href="item.href"
                    :class="[
                        '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                        isCurrentUrl(item.href)
                            ? 'border-primary text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    ]"
                >
                    {{ item.title }}
                </Link>
            </nav>

            <div class="md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </template>

        <template v-else>
            <PageHeader
                title="Account"
                description="Manage your profile and account settings."
            />

            <div class="flex flex-col lg:flex-row lg:space-x-12">
                <aside class="w-full max-w-xl lg:w-48">
                    <nav
                        class="flex flex-col space-y-1 space-x-0"
                        aria-label="Account settings"
                    >
                        <Button
                            v-for="item in accountNavItems"
                            :key="toUrl(item.href)"
                            variant="ghost"
                            :class="[
                                'w-full justify-start',
                                { 'bg-muted': isCurrentOrParentUrl(item.href) },
                            ]"
                            as-child
                        >
                            <Link :href="item.href">
                                {{ item.title }}
                            </Link>
                        </Button>
                    </nav>
                </aside>

                <Separator class="my-6 lg:hidden" />

                <div class="flex-1 md:max-w-2xl">
                    <section class="max-w-xl space-y-12">
                        <slot />
                    </section>
                </div>
            </div>
        </template>
    </div>
</template>

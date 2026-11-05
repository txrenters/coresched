<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, TrendingDown, TrendingUp } from '@lucide/vue';
import { computed } from 'vue';
import BarList from '@/components/admin/BarList.vue';
import ColumnChart from '@/components/admin/ColumnChart.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { index as inquiriesIndex } from '@/routes/admin/inquiries';
import { index as consoleIndex } from '@/routes/admin/organizations';
import { index as organizationsIndex } from '@/routes/organizations';

type Series = Array<{ label: string; value: number }>;

type Props = {
    newInquiryCount: number;
    analytics: {
        stats: {
            organizations: number;
            activeOrganizations: number;
            users: number;
            bookingsLast30Days: number;
            bookingsPrevious30Days: number;
            newInquiries: number;
        };
        bookingsByWeek: Series;
        organizationsByMonth: Series;
        planMix: Series;
        setupFunnel: Series;
        traffic: {
            visitorsLast30Days: number;
            visitorsPrevious30Days: number;
            pageViewsLast30Days: number;
            visitorsByDay: Series;
            topPages: Series;
            topReferrers: Series;
            devices: Series;
        };
    };
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: consoleIndex() }],
    },
});

const stats = computed(() => {
    const current = props.analytics.stats.bookingsLast30Days;
    const previous = props.analytics.stats.bookingsPrevious30Days;

    return {
        ...props.analytics.stats,
        // Null when there is no earlier period to compare against.
        bookingsChange:
            previous > 0
                ? Math.round(((current - previous) / previous) * 100)
                : null,
    };
});

const traffic = computed(() => {
    const current = props.analytics.traffic.visitorsLast30Days;
    const previous = props.analytics.traffic.visitorsPrevious30Days;

    return {
        ...props.analytics.traffic,
        visitorsChange:
            previous > 0
                ? Math.round(((current - previous) / previous) * 100)
                : null,
    };
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Dashboard"
            description="How the platform is doing across every organization."
        >
            <template #actions>
                <Button variant="outline" as-child>
                    <Link :href="organizationsIndex()">
                        Manage organizations
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div
                class="rounded-xl border border-border bg-card px-4 py-3 shadow-flat"
            >
                <dt class="text-sm text-muted-foreground">Organizations</dt>
                <dd class="mt-1 text-2xl font-bold tracking-tight">
                    {{ stats.organizations.toLocaleString() }}
                </dd>
                <dd class="text-xs text-muted-foreground">
                    {{ stats.activeOrganizations }} booked in the last 30 days
                </dd>
            </div>
            <div
                class="rounded-xl border border-border bg-card px-4 py-3 shadow-flat"
            >
                <dt class="text-sm text-muted-foreground">
                    Bookings, last 30 days
                </dt>
                <dd class="mt-1 text-2xl font-bold tracking-tight">
                    {{ stats.bookingsLast30Days.toLocaleString() }}
                </dd>
                <dd
                    class="flex items-center gap-1 text-xs text-muted-foreground"
                    data-test="bookings-change"
                >
                    <template v-if="stats.bookingsChange !== null">
                        <TrendingUp
                            v-if="stats.bookingsChange >= 0"
                            class="size-3.5 text-success"
                            aria-hidden="true"
                        />
                        <TrendingDown
                            v-else
                            class="size-3.5 text-destructive"
                            aria-hidden="true"
                        />
                        {{ stats.bookingsChange >= 0 ? '+' : ''
                        }}{{ stats.bookingsChange }}% vs the 30 days before
                    </template>
                    <template v-else
                        >No bookings in the 30 days before</template
                    >
                </dd>
            </div>
            <div
                class="rounded-xl border border-border bg-card px-4 py-3 shadow-flat"
            >
                <dt class="text-sm text-muted-foreground">Users</dt>
                <dd class="mt-1 text-2xl font-bold tracking-tight">
                    {{ stats.users.toLocaleString() }}
                </dd>
                <dd class="text-xs text-muted-foreground">
                    Across every organization
                </dd>
            </div>
            <Link
                :href="inquiriesIndex()"
                class="rounded-xl border border-border bg-card px-4 py-3 shadow-flat transition hover:shadow-raised focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                data-test="new-inquiry-count"
            >
                <dt class="text-sm text-muted-foreground">
                    New sales inquiries
                </dt>
                <dd class="mt-1 text-2xl font-bold tracking-tight">
                    {{ newInquiryCount }}
                </dd>
                <dd class="text-xs text-muted-foreground">
                    Waiting for a reply
                </dd>
            </Link>
        </dl>

        <div class="grid gap-4 lg:grid-cols-3">
            <section
                class="rounded-xl border border-border bg-card p-5 shadow-flat lg:col-span-2"
                aria-labelledby="bookings-chart-title"
            >
                <h2 id="bookings-chart-title" class="font-semibold">
                    Bookings per week
                </h2>
                <p class="text-sm text-muted-foreground">
                    Meetings booked across all organizations, last 12 weeks
                </p>
                <ColumnChart
                    class="mt-4"
                    :data="analytics.bookingsByWeek"
                    series-label="Bookings"
                />
            </section>
            <section
                class="rounded-xl border border-border bg-card p-5 shadow-flat"
                aria-labelledby="plan-mix-title"
            >
                <h2 id="plan-mix-title" class="font-semibold">Plans</h2>
                <p class="text-sm text-muted-foreground">
                    Organizations on each plan
                </p>
                <BarList class="mt-5" :items="analytics.planMix" />
            </section>
            <section
                class="rounded-xl border border-border bg-card p-5 shadow-flat lg:col-span-2"
                aria-labelledby="orgs-chart-title"
            >
                <h2 id="orgs-chart-title" class="font-semibold">
                    New organizations
                </h2>
                <p class="text-sm text-muted-foreground">
                    Created per month, last 12 months
                </p>
                <ColumnChart
                    class="mt-4"
                    :data="analytics.organizationsByMonth"
                    series-label="Organizations"
                />
            </section>
            <section
                class="rounded-xl border border-border bg-card p-5 shadow-flat"
                aria-labelledby="setup-title"
            >
                <h2 id="setup-title" class="font-semibold">Setup progress</h2>
                <p class="text-sm text-muted-foreground">
                    Organizations that have reached each step
                </p>
                <BarList
                    class="mt-5"
                    :items="analytics.setupFunnel"
                    :total="stats.organizations"
                />
            </section>
        </div>

        <section aria-labelledby="traffic-title" class="grid gap-4">
            <div
                class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2"
            >
                <div>
                    <h2 id="traffic-title" class="text-lg font-semibold">
                        Website traffic
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Signed-out visitors to the home, pricing and legal
                        pages, last 30 days. No cookies; a visitor counts once
                        per day.
                    </p>
                </div>
                <dl class="flex gap-6" data-test="traffic-stats">
                    <div>
                        <dt class="text-sm text-muted-foreground">Visitors</dt>
                        <dd class="text-2xl font-bold tracking-tight">
                            {{ traffic.visitorsLast30Days.toLocaleString() }}
                        </dd>
                        <dd
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <template v-if="traffic.visitorsChange !== null">
                                <TrendingUp
                                    v-if="traffic.visitorsChange >= 0"
                                    class="size-3.5 text-success"
                                    aria-hidden="true"
                                />
                                <TrendingDown
                                    v-else
                                    class="size-3.5 text-destructive"
                                    aria-hidden="true"
                                />
                                {{ traffic.visitorsChange >= 0 ? '+' : ''
                                }}{{ traffic.visitorsChange }}% vs the 30 days
                                before
                            </template>
                            <template v-else
                                >No visits in the 30 days before</template
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">
                            Page views
                        </dt>
                        <dd class="text-2xl font-bold tracking-tight">
                            {{ traffic.pageViewsLast30Days.toLocaleString() }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <section
                    class="rounded-xl border border-border bg-card p-5 shadow-flat lg:col-span-2"
                    aria-labelledby="visitors-chart-title"
                >
                    <h3 id="visitors-chart-title" class="font-semibold">
                        Visitors per day
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        Unique visitors each day, last 30 days
                    </p>
                    <ColumnChart
                        class="mt-4"
                        :data="traffic.visitorsByDay"
                        series-label="Visitors"
                    />
                </section>
                <section
                    class="rounded-xl border border-border bg-card p-5 shadow-flat"
                    aria-labelledby="top-pages-title"
                >
                    <h3 id="top-pages-title" class="font-semibold">
                        Top pages
                    </h3>
                    <p class="text-sm text-muted-foreground">Page views</p>
                    <BarList
                        v-if="traffic.topPages.length"
                        class="mt-5"
                        :items="traffic.topPages"
                    />
                    <p v-else class="mt-5 text-sm text-muted-foreground">
                        No visits yet.
                    </p>
                </section>
                <section
                    class="rounded-xl border border-border bg-card p-5 shadow-flat lg:col-span-2"
                    aria-labelledby="referrers-title"
                >
                    <h3 id="referrers-title" class="font-semibold">
                        Where visitors come from
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        Page views from other sites; direct visits are not
                        listed
                    </p>
                    <BarList
                        v-if="traffic.topReferrers.length"
                        class="mt-5"
                        :items="traffic.topReferrers"
                    />
                    <p v-else class="mt-5 text-sm text-muted-foreground">
                        No visits from other sites yet.
                    </p>
                </section>
                <section
                    class="rounded-xl border border-border bg-card p-5 shadow-flat"
                    aria-labelledby="devices-title"
                >
                    <h3 id="devices-title" class="font-semibold">Devices</h3>
                    <p class="text-sm text-muted-foreground">Page views</p>
                    <BarList
                        v-if="traffic.devices.length"
                        class="mt-5"
                        :items="traffic.devices"
                        :total="traffic.pageViewsLast30Days"
                    />
                    <p v-else class="mt-5 text-sm text-muted-foreground">
                        No visits yet.
                    </p>
                </section>
            </div>
        </section>
    </div>
</template>

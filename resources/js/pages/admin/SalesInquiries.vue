<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Inbox, Mail } from '@lucide/vue';
import { ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import {
    index as inquiriesIndex,
    update as updateInquiry,
} from '@/routes/admin/inquiries';

type Inquiry = {
    id: number;
    name: string;
    email: string;
    company: string;
    teamSize: string;
    plan: string | null;
    message: string | null;
    status: string;
    receivedAt: string | null;
    receivedAgo: string | null;
};

type Props = {
    inquiries: {
        data: Inquiry[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    statuses: Array<{ value: string; label: string }>;
    filters: { status: string };
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sales inquiries', href: inquiriesIndex() }],
    },
});

const statusFilter = ref(props.filters.status);

watch(statusFilter, () =>
    router.get(
        inquiriesIndex().url,
        { status: statusFilter.value },
        { preserveState: true, preserveScroll: true, replace: true },
    ),
);

const setStatus = (inquiry: Inquiry, status: string) =>
    router.patch(
        updateInquiry(inquiry.id).url,
        { status },
        { preserveScroll: true, preserveState: true },
    );

/** Laravel's paginator labels its ends with HTML entities. */
const labelFor = (label: string) =>
    label.replace('&laquo;', '«').replace('&raquo;', '»');

const statusTone: Record<string, string> = {
    new: 'bg-primary text-primary-foreground',
    contacted: 'bg-accent text-accent-foreground',
    won: 'bg-success-muted text-success-muted-foreground',
    lost: 'bg-muted text-muted-foreground',
};

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';
</script>

<template>
    <Head title="Sales inquiries" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Sales inquiries"
            description="Requests sent from the Contact sales form on the pricing page. Each one is also emailed to the sales inbox."
        />

        <select
            v-model="statusFilter"
            :class="[selectClass, 'w-fit']"
            aria-label="Filter by status"
            data-test="inquiry-status-filter"
        >
            <option value="">All statuses</option>
            <option
                v-for="status in statuses"
                :key="status.value"
                :value="status.value"
            >
                {{ status.label }}
            </option>
        </select>

        <ul v-if="inquiries.data.length" class="flex flex-col gap-4">
            <li
                v-for="inquiry in inquiries.data"
                :key="inquiry.id"
                class="rounded-xl border border-border bg-card p-5 shadow-flat"
                data-test="sales-inquiry"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p
                            class="flex flex-wrap items-center gap-2 font-medium"
                        >
                            {{ inquiry.company }}
                            <span
                                :class="[
                                    'rounded-full px-2 py-0.5 text-xs font-semibold',
                                    statusTone[inquiry.status],
                                ]"
                            >
                                {{
                                    statuses.find(
                                        (status) =>
                                            status.value === inquiry.status,
                                    )?.label
                                }}
                            </span>
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ inquiry.name }} ·
                            <a
                                :href="`mailto:${inquiry.email}`"
                                class="inline-flex items-center gap-1 underline-offset-4 hover:underline"
                            >
                                <Mail class="size-3.5" aria-hidden="true" />
                                {{ inquiry.email }}
                            </a>
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ inquiry.teamSize }} people
                            <template v-if="inquiry.plan">
                                · interested in {{ inquiry.plan }}
                            </template>
                            ·
                            <time :title="inquiry.receivedAt ?? undefined">{{
                                inquiry.receivedAgo
                            }}</time>
                        </p>
                    </div>

                    <select
                        :value="inquiry.status"
                        :class="selectClass"
                        :aria-label="`Status of ${inquiry.company}'s inquiry`"
                        :data-test="`inquiry-status-${inquiry.id}`"
                        @change="
                            setStatus(
                                inquiry,
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option
                            v-for="status in statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </select>
                </div>

                <p
                    v-if="inquiry.message"
                    class="mt-4 rounded-lg bg-muted px-4 py-3 text-sm whitespace-pre-line"
                >
                    {{ inquiry.message }}
                </p>
            </li>
        </ul>

        <div
            v-else
            class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground"
        >
            <Inbox class="size-6" aria-hidden="true" />
            No inquiries yet. They appear here when someone uses Contact sales
            on the pricing page.
        </div>

        <nav
            v-if="inquiries.links.length > 3"
            class="flex flex-wrap gap-1"
            aria-label="Inquiry pages"
        >
            <template v-for="link in inquiries.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    :aria-current="link.active ? 'page' : undefined"
                    class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                    "
                >
                    {{ labelFor(link.label) }}
                </Link>
                <span
                    v-else
                    class="px-2.5 py-1 text-xs text-muted-foreground/50"
                >
                    {{ labelFor(link.label) }}
                </span>
            </template>
        </nav>
    </div>
</template>

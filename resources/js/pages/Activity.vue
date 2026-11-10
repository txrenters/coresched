<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    CalendarClock,
    History,
    LayoutList,
    Mail,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCurrentTeam } from '@/composables/useCurrentTeam';
import { index as activityIndex } from '@/routes/activity';
import type { Team } from '@/types';

type Entry = {
    id: number;
    event: string;
    kind: string;
    description: string;
    actorName: string | null;
    isSystem: boolean;
    properties: Record<string, unknown> | null;
    at: string;
    atIso: string;
};

type Paginated = {
    data: Entry[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    entries: Paginated;
    kinds: Array<{ value: string; label: string }>;
    kind: string;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Activity',
                href: props.currentTeam
                    ? activityIndex(props.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const { teamSlug } = useCurrentTeam();

const iconFor = (kind: string) => {
    const icons: Record<string, typeof History> = {
        booking: CalendarClock,
        event_type: LayoutList,
        member: Users,
        invitation: Mail,
        organization: Building2,
    };

    return icons[kind] ?? History;
};

const applyKind = (value: string) => {
    router.get(
        activityIndex(teamSlug.value).url,
        { kind: value },
        { preserveScroll: true, preserveState: true, replace: true },
    );
};

/**
 * Laravel's pagination labels arrive with HTML entities ("&laquo; Previous").
 * Decoding them to text keeps the markup free of v-html.
 */
const labelFor = (label: string) =>
    label
        .replace(/&laquo;/g, '\u2039')
        .replace(/&raquo;/g, '\u203a')
        .replace(/&nbsp;/g, ' ')
        .trim();

const range = computed(() => {
    if (!props.entries.total) {
        return '';
    }

    return `${props.entries.from}–${props.entries.to} of ${props.entries.total}`;
});
</script>

<template>
    <Head title="Activity" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Activity"
            description="Everything that has happened in this organization."
        >
            <template #actions>
                <Select
                    :model-value="kind"
                    @update:model-value="(value) => applyKind(value as string)"
                >
                    <SelectTrigger
                        class="w-48 cursor-pointer"
                        data-test="activity-filter"
                        aria-label="Filter activity"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in kinds"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </template>
        </PageHeader>

        <div
            v-if="entries.data.length"
            class="overflow-hidden rounded-xl border border-border bg-card shadow-flat"
        >
            <ul class="divide-y divide-border">
                <li
                    v-for="entry in entries.data"
                    :key="entry.id"
                    class="flex items-start gap-4 p-4 sm:p-5"
                    :data-test="`activity-${entry.id}`"
                >
                    <span
                        class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground"
                    >
                        <component
                            :is="iconFor(entry.kind)"
                            class="size-4"
                            aria-hidden="true"
                        />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium" data-wrap-anywhere>
                            {{ entry.description }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            <template v-if="entry.actorName">
                                {{ entry.actorName }} ·
                            </template>
                            <template v-else-if="entry.isSystem">
                                Booking page ·
                            </template>
                            <time :datetime="entry.atIso">{{ entry.at }}</time>
                        </p>
                    </div>
                </li>
            </ul>

            <div
                v-if="entries.links.length > 3"
                class="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-muted/50 px-4 py-3"
            >
                <p class="text-xs text-muted-foreground" data-numeric>
                    {{ range }}
                </p>
                <nav class="flex flex-wrap gap-1" aria-label="Activity pages">
                    <template v-for="link in entries.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            :aria-current="link.active ? 'page' : undefined"
                            class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors duration-200 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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
        </div>

        <div
            v-else
            class="rounded-xl border border-dashed border-border px-6 py-16 text-center"
        >
            <History
                class="mx-auto size-6 text-muted-foreground"
                aria-hidden="true"
            />
            <p class="mt-3 font-medium">Nothing recorded yet</p>
            <p
                class="mx-auto mt-1 max-w-sm text-sm leading-relaxed text-muted-foreground"
            >
                Bookings, event type changes and member updates all show up here
                as they happen.
            </p>
        </div>
    </div>
</template>
